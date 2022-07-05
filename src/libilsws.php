<?php

namespace Libilsws;

/**
 *
 * Multnomah County Library ILSWS API Support
 *
 * Copyright (c) 2022 Multnomah County (Oregon)
 * 
 * John Houser
 * john.houser@multco.us
 *
 */

use Symfony\Component\Yaml\Yaml;
use Curl\Curl;
use DateTime;
use \Exception;

/**
 * Custom API exception.
 *
 * @package Libilsws
 */
class APIException extends Exception 
{

    // Handles API errors that should be logged
    public function __construct ($message = "", $code = 0) 
    {
        return "$code: $message";
    }
}

class Libilsws
{
    // Public variable to share error information
    public $error;

    // Public variable to HTML return code
    public $code;

    // Turn this on to see various debug messages
    private $debug = 1;

    // The ILSWS host we should connect to.
    private $config;

    /**
     * Searchable indexes in Symphony. These are derived from the
     * Symphony configuration and could change, so they are set 
     * in the YAML configuration
     */
    private $valid_search_indexes;

    // Data handler instance
    private $dh;
    
    // Constructor for this class
    public function __construct($yaml_file)
    {
        include_once 'datahandler.php';
        $this->dh = new DataHandler();

        // Read the YAML configuration file and assign private varaibles
        if ( filesize($yaml_file) > 0 && substr($yaml_file, -4, 4) == 'yaml' ) {
            $this->config = Yaml::parseFile('libilsws.yaml');

            if ( $this->debug ) {
                print json_encode($this->config, JSON_PRETTY_PRINT) . "\n";
            }

        } else {
            throw new Exception("Empty or inappropriate YAML file: $yaml_file");
        }

        $this->valid_search_indexes = preg_replace('/,/', '|', $this->config['symphony']['valid_search_indexes']);

        $this->base_url = 'https://' 
            . $this->config['ilsws']['hostname'] 
            . ':' 
            . $this->config['ilsws']['port'] 
            . '/' 
            . $this->config['ilsws']['webapp'];
    }

    /**
     * Validation by rule, using dataHandler/validate class
     * 
     * @access private
     * @param  string  Name of calling function
     * @param  string  Name of parameter to validate
     * @param  string  Value to be validated
     * @param  string  Rule to apply
     * @return integer Always returns 1, if it doesn't throw an exception
     */

    private function validate ($function, $param, $value, $rule)
    {
        if ( ! $this->dh->validate($value, $rule) ) {
            throw new Exception ("Invalid $param (rule: '$rule') in $function: \"$value\"");
        }

        return 1;
    }

    /**
     * Connect to ILSWS
     * 
     * @return string $token The x-sirs-sessionToken to be used in all subsequent headers
     */
    public function connect()
    {
        try {
            $action = "rest/security/loginUser";
            $params = 'client_id=' 
                . $this->config['ilsws']['client_id'] 
                . '&login=' 
                . $this->config['ilsws']['username'] 
                . '&password=' 
                . $this->config['ilsws']['password'];

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'SD-Originating-App-ID: ' . $this->config['ilsws']['app_id'],
                'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
                ];

            $options = array(
                CURLOPT_URL              => "$this->base_url/$action?$params",
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_SSL_VERIFYSTATUS => true,
                CURLOPT_CONNECTTIMEOUT   => $this->config['ilsws']['timeout'],
                CURLOPT_HTTPHEADER       => $headers,
                );

            $ch = curl_init();
            curl_setopt_array($ch, $options);

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $response = json_decode($json, true);
            $token = $response['sessionToken'];

            curl_close($ch);

        } catch (APIException $e) {

            // Obfuscate the password if it's part of the dsn
            $obfuscated_url =  "$url/$action?" . preg_replace('/(password)=(.*?([;]|$))/', '${1}=***', "$params");
            $this->error = "Could not connect to ILSWS: $obfuscated_url: " . $e->getMessage();

            throw new Exception($this->message, $this->code);
        }

        return $token;
    }

    /**
     * Create a standard GET request object. Used by most API functions.
     *
     * @param  string $url      The URL to connect with
     * @param  string $token    The session token returned by ILSWS
     * @param  object $params   Associative array of optional parameters
     * @return object $response Associative array containing response from ILSWS
     */

    public function send_get ($url, $token, $params) 
    {
        $this->validate('send_get', 'token', $token, 's:40');
        $this->validate('send_get', 'url', $url, 'u');
 
        // Encode the query parameters, as they will be sent in the URL
        $url .= "?";
        $keys = array('q','rw','ct','j','includeFields');
        foreach ($keys as $key) {
            if ( isset($params[$key]) ) {
                $url .= "$key=" . htmlentities($params[$key]) . '&';
            }
        }
        $url = substr($url, 0, -1);
        $url = preg_replace('/(.*)\#(.*)/', '$1%23$2', $url);

        // Define a random request tracker. Can help when talking with SirsiDynix
        $req_num = rand(1, 1000000000);

        /** Set $error to the URL being submitted so that it can be accessed 
         * in debug mode, when there is no error
         */
        if ( $this->debug ) {
            print "$url\n";
        }

        try {

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'SD-Originating-App-ID: ' . $this->config['ilsws']['app_id'],
                "SD-Request-Tracker: $req_num",
                'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
                "x-sirs-sessionToken: $token",
                ];

            $options = array(
                CURLOPT_URL              => $url,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_SSL_VERIFYSTATUS => true,
                CURLOPT_CONNECTTIMEOUT   => $this->config['ilsws']['timeout'],
                CURLOPT_HTTPHEADER       => $headers,
                );

            $ch = curl_init();
            curl_setopt_array($ch, $options);

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ( $this->debug ) {
                print "Request number: $req_num\n";
                print "HTTP $this->code: " . json_decode($json, JSON_PRETTY_PRINT) . "\n";
            }
            
            $response = json_decode($json, true);

            curl_close($ch);

        } catch (APIException $e) {

            $this->error = 'ILSWS send_get failed: ' . $e->getMessage();

            throw new APIException($this->error, $this->code);
        }

        return $response;
    }

    /** 
     * Create a standard POST request object. Used by most updates and creates.
     * 
     * @param  string $url        The URL to connect with
     * @param  string $token      The session token returned by ILSWS
     * @param  string $query_json JSON containing the required query elements
     * @param  string $query_type The query type: POST or PUT
     * @return object $response   Associative array containing the response from ILSWS 
     */

    public function send_query ($url, $token, $query_json, $query_type)
    {
        $this->validate('send_query', 'url', $url, 'u');
        $this->validate('send_query', 'token', $token, 's:40');
        $this->validate('send_query', 'query_json', $query_json, 'j');
        $this->validate('send_query', 'query_type', $query_type, 'v:POST|PUT');

        // Define a random request tracker
        $req_num = rand(1, 1000000000);

        try {

            // Define the request headers
            $headers = array(
                'Content-Type: application/json',
                'Accept: application/json',
                'SD-Originating-App-Id: ' . $this->config['ilsws']['app_id'],
                "SD-Response-Tracker: $req_num",
                'SD-Preferred-Role: STAFF',
                'SD-Prompt-Return: USER_PRIVILEGE_OVRCD/' . $this->config['ilsws']['user_privilege_override'],
                'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
                "x-sirs-sessionToken: $token",
                );

            $options = array(
                CURLOPT_URL              => $url,
                CURLOPT_CUSTOMREQUEST    => $query_type,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_SSL_VERIFYSTATUS => true,
                CURLOPT_CONNECTTIMEOUT   => $this->config['ilsws']['timeout'],
                CURLOPT_HTTPHEADER       => $headers,
                CURLOPT_POSTFIELDS       => $query_json,
                );

             $ch = curl_init();
             curl_setopt_array($ch, $options);
           
             $json = curl_exec($ch);
             $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
             
             if ( $this->debug ) {
                 print "Request number: $req_num\n";
                 print "HTTP $this->code: $json\n";
             }
             
             $response = json_decode($json, true);
            
             curl_close($ch);
        
        } catch (APIException $e) {

            $this->error = 'ILSWS send_query failed: ' . $e->getMessage();

            throw new APIException($this->error, $this->code);
        }

        return $response;
    }

    /**
     * Use an email, telephone or other value to retrieve a user barcode (ID)
     * and then see if we can authenticate with that barcode and the user password.
     *
     * Should return a patron key or 0. On error,it should throw an exception. 
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param  string $token      The session token returned by ILSWS
     * @param  string $index      The Symphony index to search
     * @param  string $search     The value to search for
     * @param  string $password   The patron password
     * @return string $patron_key The patron ID (barcode)
     */
    public function authenticate_search ($token, $index, $search, $password)
    {
        $this->validate('authenticate_search', 'token', $token, 's:40');
        $this->validate('authenticate_search', 'search', $search, 's:40');
        $this->validate('authenticate_search', 'password', $password, 's:40');

        // These values are determined by the Symphony configuration 
        $this->validate('authenticate_search', 'index', $index, "v:$this->valid_search_indexes");

        $params = array(
                'rw'            => '1',
                'ct'            => $this->config['ilsws']['max_search_count'],
                'j'             => 'AND',
                'includeFields' => 'barcode',
                );

        $response = $this->patron_search($token, $index, $search, $params);

        if ( $this->error ) {
            return 0;
        }

        /**
         * Symphony Web Services' with return nulls for records that have been deleted 
         * but still count them in the results. So, you can't trust the totalResults count 
         * match the number of actual records returned, and you have to loop through all 
         * possible result objects to see if there is data.
         */
        $patron_key = 0;
        $count = 0;
        if ( $response['totalResults'] > 0 && $response['totalResults'] <= $this->config['ilsws']['max_search_count'] ) {
            for ($i = 0; $i <= $response['totalResults'] - 1; $i++) {
                if ( isset($response['result'][$i]['fields']['barcode']) ) {
                    $patron_id = $response['result'][$i]['fields']['barcode'];

                    // Get the patron key from ILSWS via the patron ID and password
                    $patron_key = $this->authenticate_patron_id($token, $patron_id, $password);

                    if ( $patron_key ) {
                        $count++;
                    }
                }
                if ( $count > 1 ) {
                    $patron_key = 0;
                    break;
                }
            }
        }

        return $patron_key;
    }

    /**
     * Authenticate via patron_id (barcode) and password.
     *
     * On a successful login, this function should return the user's patron key. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a key of 0 is returned.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param  string $token      The session token returned by ILSWS
     * @param  string $patron_id  The patron ID (barcode)
     * @param  string $password   The patron password
     * @return string $patron_key The patron key (internal ID)
     */
    public function authenticate_patron_id($token, $patron_id, $password)
    {
        $this->validate('authenticate_patron_id', 'token', $token, 's:40');
        $this->validate('authenticate_patron_id', 'patron_id', $patron_id, 'i:20000000000000,29999999999999');
        $this->validate('authenticate_patron_id', 'password', $password, 's:20');

        $patron_key = 0;

        $action = "/user/patron/authenticate";
        $json = json_encode( array('barcode' => $patron_id, 'password' => $password) );

        $response = $this->send_query("$this->base_url/$action", $token, $json, 'POST');

        if ( $this->error ) {
            throw new Exception("ILSWS send_query failed: $this->error");
        }
                
        if ( isset($response['patronKey']) ) {
            $patron_key = $response['patronKey'];
        }

        return $patron_key;
    }

    /**
     * Attempt to retrieve patron attributes.
     *
     * This function returns a patron's attributes.
     *
     * @param  string $token      The session token returned by ILSWS
     * @param  string $patron_key The user's internal ID number
     * @return object $attributes Associative array with the user's attributes
     */
    public function get_patron_attributes ($token, $patron_key)
    {
        $this->validate('get_patron_attributes', 'token', $token, 's:40');
        $this->validate('get_patron_attributes', 'patron_key', $patron_key, 'i:1,99999999');
        
        $attributes = [];

        $include_fields = [
            'lastName',
            'firstName',
            'middleName',
            'barcode',
            'library',
            'profile',
            'language',
            'lastActivityDate',
            'address1',
            'category01',
            'category02',
            'category03',
            'standing',
            ];

        $include_str = implode(',', $include_fields);

        $response = $this->send_get("$this->base_url/user/patron/key/$patron_key", $token, array('includeFields' => $include_str));

        // Extract patron attributes from the ILSWS response and assign to $attributes.
        if ( isset($response['key']) ) {
            foreach ( $include_fields as &$field ) {

                if ( $field == 'address1' ) {
                    if ( isset($response['fields']['address1']) ) {
                        foreach ($response['fields']['address1'] as &$i) {
                            if ( $i['fields']['code']['key'] == 'EMAIL' ) {
                                $attributes['email'][] = $i['fields']['data'];
                            } elseif ( $i['fields']['code']['key'] == 'CITY/STATE' ) {
                                $parts = preg_split("/,\s*/", $i['fields']['data']);
                                $attributes['city'][] = $parts[0];
                                $attributes['state'][] = $parts[1];
                            } elseif ( $i['fields']['code']['key'] == 'ZIP' ) {
                                $attributes['zip'][] = $i['fields']['data'];
                            } elseif ( $i['fields']['code']['key'] == 'PHONE' ) {
                                $attributes['telephone'][] = $i['fields']['data'];
                            }
                        }
                    }
                } elseif ( isset($response['fields'][$field]['key']) ) {
                    $attributes[$field][] = $response['fields'][$field]['key'];
                } elseif ( isset($response['fields'][$field]) ) {
                    $attributes[$field][] = $response['fields'][$field];
                } else {
                    $attributes[$field][] = '';
                }
            }
        }
        // Generate a displayName
        if ( isset($response['fields']['lastName']) && isset($response['fields']['firstName']) ) {
            $attributes['displayName'][] = $response['fields']['firstName'] . ' ' . $response['fields']['lastName'];
        }
        // Generate a commonName
        if ( isset($response['fields']['lastName']) && isset($response['fields']['firstName']) ) {
            if ( isset($response['fields']['middleName']) ) {
                $attributes['commonName'][] = $response['fields']['lastName'] 
                  . ', ' 
                  . $response['fields']['firstName'] 
                  . ' ' 
                  . $response['fields']['middleName'];
            } else {
                $attributes['commonName'][] = $response['fields']['lastName'] 
                  . ', ' 
                  . $response['fields']['firstName'];
            }
        }

        if ( $this->debug ) {
            print 'ILSWS attributes returned: ' . implode(',', array_keys($attributes)) . "\n";
        }

        return $attributes;
    }

    /**
     * Authenticate a patron via ID (barcode) and password
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $patron_id The patron's ID (barcode)
     * @return object            Associative array contain the response from ILSWS
     */

    public function patron_authenticate ($token, $patron_id, $password)
    {
        $this->validate('patron_authenticate', 'token', $token, 's:40');
        $this->validate('patron_authenticate', 'patron_id', $patron_id, 'i:20000000000000,29999999999999');

        $json = "{ \"barcode\": \"$patron_id\", \"password\": \"$password\" }";

        return $this->send_query("$this->base_url/user/patron/authenticate", $token, $json, 'POST');
    }

    /**
     * Describe the patron resource
     * 
     * @param  string $token The session token returned by ILSWS
     * @return object        Associative array containing information about the patron record
     *                       structure used by SirsiDynix Symphony
     */

    public function patron_describe ($token) 
    {
        $this->validate('patron_describe', 'token', $token, 's:40');

        return $this->send_get("$this->base_url/user/patron/describe", $token, []);
    }

    /** 
     * Search for patron by any valid single field
     *
     * @param  string $token    The session token returned by ILSWS
     * @param  string $index    The index to search
     * @param  string $value    The value to search for
     * @param  object $params   Associative array of optional parameters
     * @return object $response Associative array containing search results
     */

    public function patron_search ($token, $index, $value, $params)
    {
        $this->validate('patron_search', 'token', $token, 's:40');
        $this->validate('patron_search', 'index', $index, "v:$this->valid_search_indexes");
        $this->validate('patron_search', 'value', $value, 's:40');

        /** 
         * Valid incoming params are: 
         * ct            = number of results to return,
         * rw            = row to start on (so you can page through results),
         * j             = boolean AND or OR to use with multiple search terms, and
         * includeFields = fields to return in result.
         *
         * Any incoming q will be replaced by the values $index and $value.
         */

        $params = array(
            'q'             => "$index:$value",
            'ct'            => $params['ct'] ?? '1000',
            'rw'            => $params['rw'] ?? '1',
            'j'             => $params['j'] ?? 'AND',
            'includeFields' => $params['includeFields'] ?? $this->config['ilsws']['default_include_fields'],
            );

        $response = $this->send_get("$this->base_url/user/patron/search", $token, $params);

        if ( $this->code == 401 ) {
            $this->error = $response;
        }

        return $response;
    }

    /**
     * Search by alternate ID number
     * 
     * @param  string $token  The session token returned by ILSWS
     * @param  string $alt_id The user's alternate ID number
     * @param  string $count  How many records to return per page
     * @return object         Associative array containing search results
     */

    public function patron_alt_id_search ($token, $alt_id, $count)
    {
        $this->validate('patron_alt_id_search', 'token', $token, 's:40');
        $this->validate('patron_alt_id_search', 'alt_id', $alt_id, 'i:1,99999999');
        $this->validate('patron_alt_id_search', 'count', $count, 'i:1,1000');

        return $this->patron_search($token, 'ALT_ID', $alt_id, array('ct' => $count));
    }

    /**
     * Search for patron by ID (barcode)
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $patron_id The user's alternate ID number
     * @param  string $count     How many records to return per page
     * @return object            Associative array containing search results
     */

    public function patron_id_search ($token, $patron_id, $count) 
    {
        $this->validate('patron_id_search', 'token', $token, 's:40');
        $this->validate('patron_id_search', 'patron_id', $patron_id, 'i:20000000000000,29999999999999');
        $this->validate('patron_id_search', 'count', $count, 'i:1,1000');

        return $this->patron_search($token, 'ID', $patron_id, array('ct' => $count));
    }

    /**
     * Uses a birth day to determine whether a person is a youth, based on the 
     * max_youth_age set in the config file
     *
     * @access private
     * @param  string  $birthDate Birth date in YYYY-MM-DD format
     * @return integer $youth     Sets 1 to indicate the patron is less than or equal
     *                            to the maximum age for a youth. Sets 0 if they are not.
     */
    private function is_youth ($birthDate)
    {
        $youth = 0;

        $today = date('Y-m-d');
        $d1 = new DateTime($today);
        $d2 = new DateTime($birthDate);
        $diff = $d2->diff($d1);
        $age = $diff->y;

        if ( $age <= $this->max_youth_age ) {
            $youth = 1;
        }

        return $youth;
    }

    /**
     * Create patron data structure
     *
     * @param  object $patron     Associative array of patron data elements
     * @param  string $patron_key Optional patron key to include if updating existing record
     * @return string $json       Complete Symphony patron record JSON
     */

    public function create_patron_json ($patron, $patron_key = 0)
    {
        $mode = 'new';
        $age_group = 'default';

        $new['resource'] = '/user/patron';

        // If we have a patron key, then we're in overlay mode
        if ( $patron_key ) {
            $this->validate('create_patron_json', 'patron_key', $patron_key, 'i:1,99999999');
            $mode = 'overlay';
            $new['key'] = $patron_key;
        }

        // Check if patron is a youth
        if ( ! empty($this->config['symphony']['user_record']['fields']['birthDate']['label']) ) {
            $dob = $patron[$this->config['symphony']['user_record']['fields']['birthDate']['label']];
        } else {
            $dob = $patron['birthDate'];
        }
        if ( $this->is_youth($dob) ) {
            $age_group = 'youth';
        }

        # Extract the field definitions from the configuration
        $fields = $this->config['symphony']['user_record']['fields'];

        foreach ($fields as $field => $value) {

            // Check if the data is coming in with a different field name (label)
            if ( empty($patron[$field]) && ! empty($patron[$fields[$field]['label']]) ) {
                $patron[$field] = $patron[$fields[$field]['label']];
            }

            // Assign default values to empty fields, where appropriate
            if ( empty($patron[$field]) && ! empty($fields[$field][$mode][$age_group]) ) {
                $patron[$field] = $fields[$field][$mode][$age_group];
            }

            // Validate
            $this->validate('create_patron_json', $field, $patron[$field], $fields[$field]['validation']);
            
            if ( $field === 'profile' ) {

                // This is a required field
                $new['fields']['profile']['resource'] = '/policy/userProfile';
                $new['fields']['profile']['key'] = $patron['profile'];

            } else if ( $field === 'library' ) {

                // This is a required field
                $new['fields']['library']['resource'] = '/policy/library';
                $new['fields']['library']['key'] = $patron['library'];

            } else if ( preg_match('/^category/', $field) ) {

                // Determine the category number
                $num = substr($field, -2, 2);

                // Add category to $new
                if ( ! empty($patron[$field]) ) {
                    $new['fields'][$field]['resource'] = "/policy/patronCategory$num";
                    $new['fields'][$field]['key'] = $patron[$field];
                }

            } else if ( preg_match('/^address/', $field) ) {

                $new['fields'][$field] = [];

                // Determine the address number
                $num = substr($field, -2, 2);

                foreach ($fields[$field] as $part => $value) {

                    // Check if the data is coming in with a different field name (label)
                    if ( empty($patron[$part]) && ! empty($fields[$field][$part]['label']) ) {
                        $patron[$part] = $patron[$fields[$field][$part]['label']];
                    }

                    // Assign default values where appropriate
                    if ( empty($patron[$part]) && ! empty($fields[$field][$part][$mode][$age_group]) ) {
                        $patron[$part] = $fields[$field][$part][$mode][$age_group];
                    }

                    $addr = [];
                    $addr['resource'] = "/user/patron/address$num";
                    $addr['fields']['code']['resource'] = '/policy/patronAddress1';
                    $addr['fields']['code']['key'] = $part;
                    $addr['fields']['data'] = $patron[$part];

                    // Add this part to the address one array
                    array_push($new['fields'][$field], $addr);
                }

            } else {

                // Store as regular field
                if ( ! empty($patron[$field]) ) {
                    $new['fields'][$field] = $patron[$field];
                }
            }
        }

        // Return a JSON string suitable for use in patron_create
        return json_encode($new, JSON_PRETTY_PRINT);
    }

    /**
     * Create a new patron record
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $json      Complete JSON of patron record
     * @return object            Associative array containing result
     */

    public function patron_create ($token, $json) 
    {
        $this->validate('patron_update', 'token', $token, 's:40');
        $this->validate('patron_update', 'json', $json, 'j');

        $response = $this->send_query("$this->base_url/user/patron", $token, $json, 'POST');

        if ( $this->code == 404 ) {
            $this->error = "404: Invalid access point (resource)";
        }

        return $response;
    }

    /**
     * Update existing patron record
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $json      Complete JSON of patron record
     * @return object            Associative array containing result
     */

    public function patron_update ($token, $json, $patron_key) 
    {
        $this->validate('patron_update', 'token', $token, 's:40');
        $this->validate('patron_update', 'json', $json, 'j');
        $this->validate('patron_update', 'patron_key', $patron_key, 'i:1,99999999');

        return $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json, 'PUT');
    }

    /**
     * Update the patron lastActivityDate
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $patron_id The patron ID (barcode)
     * @return object            Associative array containing result
     */

    public function patron_activity_update ($token, $patron_id)
    {
        $this->validate('patron_activity_update', 'token', $token, 's:40');
        $this->validate('patron_activity_update', 'patron_id', $patron_id, 'i:20000000000000,29999999999999');

        $json = "{\"patronBarcode\": \"$patron_id\"}";

        return $this->send_query("$this->base_url/user/patron/updateActivityDate", $token, $json, 'POST');
    }
}

// EOF
