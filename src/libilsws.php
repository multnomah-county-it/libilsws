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
use \Exception;

/**
 * Custom API exception.
 *
 * @package Libilsws
 */
class APIException extends Exception 
{

  /**
   * Handles API errors that should be logged
   */
  public function __construct($message = "", $code = 0, Exception $previous = NULL) 
  {

    // Construct message from JSON if required.
    if (substr($message, 0, 1) == '{') {
      $message_obj = json_decode($message);
      $message = $message_obj->status . ': ' . $message_obj->title;
      if (!empty($message_obj->detail)) {
        $message .= ' - ' . $message_obj->detail;
      }
      if (!empty($message_obj->errors)) {
        $message .= ' ' . serialize($message_obj->errors);
      }
    }

    parent::__construct($message, $code, $previous);
  }
}

class Libilsws
{
    /**
     * Public variable to share error information
     */
    public $error;

    /**
     * Public variable to HTML return code
     */
    public $code;

    /**
     * Turn this on to see various debug messages
     */
    private $debug = 1;

    /**
     * The ILSWS host we should connect to.
     */
    private $hostname;

    /**
     * The ILSWS port we should connect on.
     */
    private $port;

    /**
     * The username we should connect to the database with.
     */
    private $username;

    /**
     * The password we should connect to the database with.
     */
    private $password;

    /**
     * The ILSWS webapp
     */
    private $webapp;

    /**
     * The ILSWS app_id
     */
    private $app_id;

    /**
     * The ILSWS client_id
     */
    private $client_id;

    /**
     * The ILSWS connection timeout
     */
    private $timeout;

    /**
     * The ILSWS max search count
     */
    private $max_search_count;

    /**
     * Default field list to return from patron searches
     */
    private $default_include_fields;

    /**
     * Constructor for this class
     */
    public function __construct()
    {
        $config = Yaml::parseFile('libilsws.yaml');

        $this->hostname                = $config['ilsws']['hostname'];
        $this->port                    = $config['ilsws']['port'];
        $this->username                = $config['ilsws']['username'];
        $this->password                = $config['ilsws']['password'];
        $this->webapp                  = $config['ilsws']['webapp'];
        $this->app_id                  = $config['ilsws']['app_id'];
        $this->client_id               = $config['ilsws']['client_id'];
        $this->timeout                 = $config['ilsws']['timeout'];
        $this->max_search_count        = $config['ilsws']['max_search_count'];
        $this->user_privilege_override = $config['ilsws']['user_privilege_override'];
        $this->default_include_fields  = $config['ilsws']['default_include_fields'];

        //For convenience
        $this->base_url         = "https://$this->hostname:$this->port/$this->webapp";
    }

    /**
     * Connect to ILSWS
     *
     * @return x-sirs-sessionToken
     */
    public function connect()
    {
        try {
            $action = "rest/security/loginUser";
            $params = "client_id=$this->client_id&login=$this->username&password=$this->password";

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                "SD-Originating-App-ID: $this->app_id",
                "x-sirs-clientID: $this->client_id",
                ];

            $options = array(
                CURLOPT_URL              => "$this->base_url/$action?$params",
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_SSL_VERIFYSTATUS => true,
                CURLOPT_CONNECTTIMEOUT   => $this->timeout,
                CURLOPT_HTTPHEADER       => $headers,
                );

            $ch = curl_init();
            curl_setopt_array($ch, $options);

            $json = curl_exec($ch);

            $response = json_decode($json, true);
            $token = $response['sessionToken'];

            curl_close($ch);

        } catch (Exception $e) {
            // Obfuscate the password if it's part of the dsn
            $obfuscated_url =  preg_replace('/(password)=(.*?([;]|$))/', '${1}=***', "$url/$action?$params");

            throw new Exception('Could not connect to ILSWS: ' .  $obfuscated_url . ': ' . $e->getMessage());
        }

        return $token;
    }

    /**
     * Create a standard GET request object. Used by most API functions.
     * 
     */

    public function send_get ($url, $token, $params) 
    {

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

        /* Set $error to the URL being submitted so that it can be accessed 
         * in debug mode, when there is no error
         */
        if ( $this->debug ) {
            print "$url\n";
        }

        try {

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                "SD-Originating-App-ID: $this->app_id",
                "SD-Request-Tracker: $req_num",
                "x-sirs-clientID: $this->client_id",
                "x-sirs-sessionToken: $token",
                ];

            $options = array(
                CURLOPT_URL              => $url,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_SSL_VERIFYSTATUS => true,
                CURLOPT_CONNECTTIMEOUT   => $this->timeout,
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

        } catch (RequestException $e) {
            $this->error = 'ILSWS send_get failed: ' . $e->getMessage();
        }

        return $response;
    }

    /* 
     * Create a standard POST request object. Used by most updates and creates.
     *
     */

    public function send_query ($url, $token, $query_json, $query_type)
    {
        // Define a random request tracker
        $req_num = rand(1, 1000000000);

        try {

            // Define the request headers
            $headers = array(
                'Content-Type: application/json',
                'Accept: application/json',
                "SD-Originating-App-Id: $this->app_id",
                "SD-Response-Tracker: $req_num",
                'SD-Preferred-Role: STAFF',
                "SD-Prompt-Return: USER_PRIVILEGE_OVRCD/$this->user_privilege_override",
                "x-sirs-clientID: $this->client_id",
                "x-sirs-sessionToken: $token",
                );

            $options = array(
                CURLOPT_URL              => $url,
                CURLOPT_CUSTOMREQUEST    => $query_type,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_SSL_VERIFYSTATUS => true,
                CURLOPT_CONNECTTIMEOUT   => $this->timeout,
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
        
        } catch (Exception $e) {
            $this->error = 'ILSWS send_query failed: ' .$e->getMessage();
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
     * @param string $token  The session token returned by ILSWS.
     * @param string $index  The Symphony index to search in for the user.
     * @param string $search  The username the user entered.
     * @param string $password  The password the user entered.
     * @return string $barcode The user's barcode (ID).
     */
    public function authenticate_search ($token, $index, $search, $password)
    {
        $params = array(
                'rw'            => '1',
                'ct'            => $this->max_search_count,
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
        if ( $response['totalResults'] > 0 && $response['totalResults'] <= $this->max_search_count ) {
            for ($i = 0; $i <= $response['totalResults'] - 1; $i++) {
                if ( isset($response['result'][$i]['fields']['barcode']) ) {
                    $patron_id = $response['result'][$i]['fields']['barcode'];
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
     * username or password, a \SimpleSAML\Error\Error('WRONGUSERPASS') should be thrown.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $patron_id  The username the user wrote.
     * @param string $password  The password the user wrote.
     * @return string $patron_key The user's patron key.
     */
    public function authenticate_patron_id($token, $patron_id, $password)
    {
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
     * On a successful login, this function should return the users attributes. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a \SimpleSAML\Error\Error('WRONGUSERPASS') should be thrown.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $patron_key
     * @return array @attributes Associative array with the users attributes.
     */
    public function get_patron_attributes ($token, $patron_key)
    {
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

    /*
     * Authenticate a patron via ID (Barcode) and password
     */

    public function patron_authenticate ($token, $patron_id, $password)
    {
        $json = "{ \"barcode\": \"$patron_id\", \"password\": \"$password\" }";

        return $this->send_query("$this->base_url/user/patron/authenticate", $token, $json, 'POST');
    }

    /*
     * Describe the patron resource
     */

    public function patron_describe ($token) 
    {
        return $this->send_get("$this->base_url/user/patron/describe", $token, []);
    }

    /* 
     * Search for patron by any valid single field
     */

    public function patron_search ($token, $index, $value, $params)
    {
        /* Valid params are: 
         * search index and value, 
         * number of results to return,
         * row to start on (so you can page through results),
         * boolean AND or OR to use with multiple search terms, and
         * fields to return in result.
         */
        $params = array(
            'q'             => "$index:$value",
            'ct'            => $params['ct'] ?? '1000',
            'rw'            => $params['rw'] ?? '1',
            'j'             => $params['j'] ?? 'AND',
            'includeFields' => $params['includeFields'] ?? $this->default_include_fields,
            );

        $response = $this->send_get("$this->base_url/user/patron/search", $token, $params);

        if ( $this->code == 401 ) {
            $this->error = $response;
        }

        return $response;
    }

    /*
     * Search by alternate ID number
     */

    public function patron_alt_id_search ($token, $alt_id, $count)
    {
        $response = $this->patron_search($token, 'ALT_ID', $alt_id, array('ct' => $count));
    }

    /*
     * Search by barcode number
     */

    public function patron_barcode_search ($token, $patron_id, $count) 
    {
        return $this->patron_search($token, 'ID', $patron_id, array('ct' => $count));
    }

    /*
     * Create a new patron record
     */

    public function patron_create ($token, $json) 
    {
        $res = $this->send_query("$this->base_url/user/patron", $token, $json, 'POST');

        if ( $this->code == 404 ) {
            $this->error = "404: Invalid access point (resource)";
        }

        return $res;
    }

    /*
     * Update existing patron record
     */

    public function patron_update ($token, $json, $patron_key) 
    {
        return $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json, 'PUT');
    }

    /*
     * Update the patron lastActivityDate
     */

    public function patron_activity_update ($token, $patron_id)
    {
        $json = "{\"patronBarcode\": \"$patron_id\"}";
        return $this->send_query("$this->base_url/user/patron/updateActivityDate", $token, $json, 'POST');
    }
}
