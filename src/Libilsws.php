<?php

namespace Libilsws;

/**
 *
 * Multnomah County Library
 * SirsiDynix ILSWS API Support
 * Copyright (c) 2024 Multnomah County (Oregon)
 * 
 * John Houser
 * john.houser@multco.us
 *
 */

use Symfony\Component\Yaml\Yaml;
use Curl\Curl;
use DateTime;
use \Exception;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Custom API exception.
 *
 * @package Libilsws
 */

class APIException extends Exception 
{

    // Handles API errors that should be logged
    public function errorMessage ($error = '', $code = 0) 
    {
        $message = '';
        $err_message = json_decode($error, true);
        if ( json_last_error() === JSON_ERROR_NONE ) {
            if ( !empty($err_message['messageList'][0]['message']) ) {
                $error = $err_message['messageList'][0]['message'];
            }
        } else {
            $error = "HTML error";
        }

        switch ($code) {
            case 400:
                $message = "HTTP $code: Bad Request";
                break;
            case 401:
                $message = "HTTP $code: Unauthorized";
                break;
            case 403:
                $message = "HTTP $code: Forbidden";
                break;
            case 404:
                $message = "HTTP $code: Not Found";
                break;
            case (preg_match('/^5\d\d$/', $code) ? true : false):
                $message = "HTTP $code: SirsiDynix Web Services unavailable";
                break;
            default:
                $message = "HTTP $code: $error";
        }

        return $message;
    }
}

class Libilsws
{
    // Public variable to share error information
    public $error;

    // Public variable to HTML return code
    public $code;

    // Base URL constructed from config for convenience
    public $base_url;
    
    // The ILSWS connection parameters and Symphony field configuration
    public $config;

    // Data handler instance
    public $dh;

    // ILSWS patron field description information
    public $field_desc = [];

    // Constructor for this class
    public function __construct ($yaml_file)
    {
        $this->dh = new DataHandler();

        // Read the YAML configuration file and assign private varaibles
        if ( filesize($yaml_file) > 0 && substr($yaml_file, -4, 4) == 'yaml' ) {
            $this->config = Yaml::parseFile($yaml_file);

            if ( $this->config['debug']['config'] ) {
                error_log('DEBUG_CONFIG ' . json_encode($this->config, JSON_PRETTY_PRINT), 0);
            }

        } else {
            throw new Exception("Bad YAML file: $yaml_file");
        }

        $this->base_url = 'https://' 
            . $this->config['ilsws']['hostname'] 
            . ':' 
            . $this->config['ilsws']['port'] 
            . '/' 
            . $this->config['ilsws']['webapp'];
    }

    private function handle_exception($message = '')
    {
        if ( php_sapi_name() === 'cli' || PHP_SAPI === 'cli') {

            // Running in CLI mode: print to STDERR
            fwrite(STDERR, "Error: " . $message . PHP_EOL);

        } else {

            // Running in web mode: log the error
            error_log($message);
        }
    }

    /**
     * Validate call or item input fields using the API describe function
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $field_list  Comma-delimited list of fields to be validated
     * @return object $response    Object containing include list, index list, 
     *                             and array of include fields
     */

    private function validate_fields ($token = null, $type = null, $field_list = '')
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('type', $type, 'v:item|call');

        if ($type == 'item' && preg_match('/call{.*}/', $field_list)) {
            $calls = preg_replace('/^(.*)(call{.*})(.*)$/', "$2", $field_list);
            $this->validate_fields($token, 'call', $calls);
            $field_list = preg_replace('/^(.*)(call{.*})(.*)$/', "$1,$3", $field_list);
            $field_list = preg_replace('/,{2}/', ',', $field_list);
        }

        if ( $field_list != '*' ) {

            // Convert the input fields to an array
            $inner_fields = preg_replace('/^(.*){(.*)}(.*)$/', "$2", $field_list);
            $field_list = preg_replace('/^(.*){(.*)}(.*)$/', "$1$3", $field_list);
            $field_list .= ",$inner_fields";
            $input_fields = preg_split('/[,{}]+/', $field_list, -1, PREG_SPLIT_NO_EMPTY);
            $input_fields = array_unique($input_fields, SORT_STRING);

            // Get the fields described by the API
            $fields = [];
            $describe = $this->send_get("$this->base_url/catalog/$type/describe", $token, []);
            for ($i = 0; $i < count($describe['fields']); $i++) {
                array_push($fields, $describe['fields'][$i]['name']);
            }
            // Get the item fields as well, if we're validating a get_call field_list
            if ( $type == 'call' ) {
                $describe = $this->send_get("$this->base_url/catalog/item/describe", $token, []);
                for ($i = 0; $i < count($describe['fields']); $i++) {
                    array_push($fields, $describe['fields'][$i]['name']);
                }
            }
            $valid_list = implode('|', $fields);

            foreach ($input_fields as $field) {
                $this->validate('includeFields', $field, "v:$valid_list|*");
            }
        }

        return 1;
    }

    /**
     * Validation by rule, using datahandler/validate. If 
     * dataHandler/validate receives a null value, it will return
     * 0. However, if it receives an empty value, the function will
     * return a 1. This is why function parameters within this class
     * are set to default to null even if they are actually 
     * required, so long as they are being validated by this function.
     * 
     * @access private
     * @param  string  Name of parameter to validate
     * @param  string  Value to be validated
     * @param  string  Rule to apply
     * @return integer Always returns 1, if it doesn't throw an exception
     */

    private function validate ($param, $value, $rule)
    {
        $result = $this->dh->validate($value, $rule);
        if ( $result === 0 ) {
            throw new Exception ("Invalid $param: \"$value\" (rule: '$rule')");
        }

        return $result;
    }

    /**
     * Connect to ILSWS
     * 
     * @return string $token The x-sirs-sessionToken to be used in all subsequent headers
     */
    public function connect ()
    {
        $url = $this->base_url . '/user/staff/login';
        $query_json = '{"login":"' . $this->config['ilsws']['username'] . '","password":"' . $this->config['ilsws']['password'] . '"}';
        $req_num = rand(1, 1000000000);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "SD-Response-Tracker: $req_num",
            'SD-Originating-App-ID: ' . $this->config['ilsws']['app_id'],
            'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
            ];

        $options = [
            CURLOPT_URL              => $url,
            CURLOPT_CUSTOMREQUEST    => 'POST',
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_SSL_VERIFYSTATUS => true,
            CURLOPT_CONNECTTIMEOUT   => $this->config['ilsws']['timeout'],
            CURLOPT_HTTPHEADER       => $headers,
            CURLOPT_POSTFIELDS       => $query_json,
            ];

        try {

            $ch = curl_init();
            curl_setopt_array($ch, $options);

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ( $this->config['debug']['connect'] ) {
                error_log("DEBUG_CONNECT HTTP $this->code: $json", 0);
            }

            if ( !preg_match('/^2\d\d$/', $this->code) ) {
                $obfuscated_url =  $this->base_url . "/$action?" . preg_replace('/(password)=(.*?([;]|$))/', '${1}=***', "$params");
                $this->error = "Connect failure: $obfuscated_url: " . curl_error($ch);
                throw new APIException($this->error);
            }

            $response = json_decode($json, true);
            $token = $response['sessionToken'];

            curl_close($ch);

        } catch (APIException $e) {

            $this->handle_exception($e->errorMessage($this->error, $this->code));
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

    public function send_get ($url = null, $token = null, $params = []) 
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('url', $url, 'u');
 
        // Encode the query parameters, as they will be sent in the URL
        if ( !empty($params) ) {
            $url .= "?";
            foreach ($params as $key => $value) {
                if ( !empty($params[$key]) ) {
                    $url .= "$key=" . urlencode($params[$key]) . '&';
                }
            }
            $url = substr($url, 0, -1);
        }

        $url = preg_replace('/(.*)\#(.*)/', '$1%23$2', $url);

        // Define a random request tracker. Can help when talking with SirsiDynix
        $req_num = rand(1, 1000000000);

        /** Set $error to the URL being submitted so that it can be accessed 
         * in debug mode, when there is no error
         */
        if ( $this->config['debug']['query'] ) {
            error_log("DEBUG_QUERY $url", 0);
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'SD-Originating-App-ID: ' . $this->config['ilsws']['app_id'],
            "SD-Request-Tracker: $req_num",
            'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
            "x-sirs-sessionToken: $token",
            ];

        $options = [
            CURLOPT_URL              => $url,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_SSL_VERIFYSTATUS => true,
            CURLOPT_CONNECTTIMEOUT   => $this->config['ilsws']['timeout'],
            CURLOPT_HTTPHEADER       => $headers,
            ];

        try {

            $ch = curl_init();
            curl_setopt_array($ch, $options);

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ( $this->config['debug']['query'] ) {
                error_log("DEBUG_QUERY Request number: $req_num", 0);
                error_log("DEBUG_QUERY HTTP $this->code: $json", 0);
            }
            
            // Check for errors
            if ( $this->code != 200 ) {
                $this->error = curl_error($ch);
                if ( !$this->error ) {
                    $this->error = $json;
                }
                throw new APIException($this->error);
            }

            curl_close($ch);

        } catch (APIException $e) {

            $this->handle_exception($e->errorMessage($this->error, $this->code));
        } 

        return json_decode($json, true);
    }

    /** 
     * Create a standard POST request object. Used by most updates and creates.
     * 
     * @param  string $url        The URL to connect with
     * @param  string $token      The session token returned by ILSWS
     * @param  string $query_json JSON containing the required query elements
     * @param  string $query_type The query type: POST or PUT
     * @param  array  $options    Associative array of options (role, client_id, header)
     * @return object $response   Associative array containing the response from ILSWS 
     */

    public function send_query ($url = null, $token = null, $query_json = null, $query_type = null, $options = [])
    {
        $this->validate('url', $url, 'u');
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('query_type', $query_type, 'v:POST|PUT|DELETE');

        $role = !empty($options['role']) ? $options['role'] : 'PATRON';
        $this->validate('role', $role, 'v:STAFF|PATRON|GUEST');

        $client_id = !empty($options['client_id']) ? $options['client_id'] : $this->config['ilsws']['client_id'];
        $this->validate('client_id', $client_id, 'r:#^[A-Za-z]{4,20}$#'); 

        $header = !empty($options['header']) ? $options['header'] : '';
        $this->validate('header', $header, 's:40');

        if ( $query_json ) {
            $this->validate('query_json', $query_json, 'j');
        }

        // Define a random request tracker
        $req_num = rand(1, 1000000000);

        // Define the request headers
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'SD-Originating-App-Id: ' . $this->config['ilsws']['app_id'],
            "SD-Response-Tracker: $req_num",
            "SD-Preferred-Role: $role",
            'SD-Prompt-Return: USER_PRIVILEGE_OVRCD/' . $this->config['ilsws']['user_privilege_override'],
            "x-sirs-clientID: $client_id",
            "x-sirs-sessionToken: $token",
            ];

        // Add an optional header if it exists
        array_push($headers, $header);

        $options = [
            CURLOPT_URL              => $url,
            CURLOPT_CUSTOMREQUEST    => $query_type,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_SSL_VERIFYSTATUS => true,
            CURLOPT_CONNECTTIMEOUT   => $this->config['ilsws']['timeout'],
            CURLOPT_HTTPHEADER       => $headers,
            ];

        try {

            $ch = curl_init();
            curl_setopt_array($ch, $options);

            if ( $query_json ) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query_json);
            }

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ( $this->config['debug']['query'] ) {
                error_log("DEBUG_QUERY Request number: $req_num", 0);
                error_log("DEBUG_QUERY HTTP $this->code: $json", 0);
            }

            // Check for errors
            if ( !preg_match('/^2\d\d$/', $this->code) ) {
                $this->error = curl_error($ch);
                if ( !$this->error ) {
                    $this->error = $json;
                }
                throw new APIException($this->error);
            }

            curl_close($ch);

        } catch (APIException $e) {

            $this->handle_exception($e->errorMessage($this->error, $this->code));
        }
        
        return json_decode($json, true);
    }

    /**
     * Get policy returns a policy record
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $policy_name Policy name for policy
     * @param  string $policy_key  Policy key for policy
     * @return object              Associative array containing the response from ILSWS
     */

    public function get_policy ($token = null, $policy_name = null, $policy_key = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('policy_name', $policy_name, 'r:#^[A-Za-z0-9]{1,20}$#');
        $this->validate('policy_key', $policy_key, 'r:#^[A-Za-z\- 0-9]{1,10}$#');
        
        return $this->send_get("$this->base_url/policy/$policy_name/key/$policy_key", $token, []);
    }

    /**
     * Flattens callList structure into simple hash
     * 
     * @access private 
     * @param  string  $token    Session token
     * @param  object  $callList Complex object with call list
     * @return array             Flat associative array
     */

    private function flatten_call_list ($token, $call_list)
    {
        $item_list = [];

        for ($i = 0; $i < count($call_list); $i++) {
            array_push($item_list, $this->flatten_call($token, $call_list[$i]));
        }

        return $item_list;
    }

    /**
     * Flatten call number record into array of items
     * 
     * @access private
     * @param  object $call Complex object with call number record
     * @return array        Flat associative array
     */

    function flatten_call ($token, $call)
    {
        $item_list = [];

        foreach ($call['fields'] as $field => $value) {
            if ( !is_array($value) ) {
                $item_list[$field] = $value;
            } elseif ( !empty($call['fields'][$field]['key']) ) {
                $item_list[$field] = $call['fields'][$field]['key'];
            } elseif ( $field == 'itemList' ) {
                foreach ($call['fields']['itemList'] as $item) {
                    $item = $this->flatten_item($token, $item);
                    foreach ($item as $item_field => $item_value) {
                        $item_list[$item_field] = $item_value;
                    }
                }
            }
        }

        return $item_list;
    }

    /**
     * Flattens item structure into simple hash
     *
     * @access private 
     * @param  object  $record Complex object with item list
     * @return array           Flat associative array
     */

    private function flatten_item ($token, $record)
    {
        $item = [];

        $item['key'] = $record['key'];

        foreach ($record['fields'] as $key => $value) {

            if ( $key === 'itemCircInfo' ) {
                $item['itemCircInfo'] = $this->get_item_circ_info($token, $record['fields']['itemCircInfo']['key']);
            } elseif ( $key === 'holdRecordList' ) {
                for ($i = 0; $i < count($record['fields']['holdRecordList']); $i++) {
                    if ( !empty($record['fields']['holdRecordList'][$i]['key']) ) {
                        $item['holdRecordList'][$i] = $this->get_hold($token, $record['fields']['holdRecordList'][$i]['key']);
                    }
                }
            } elseif ( $key === 'call' ) {
                foreach ($this->flatten_call($token, $record['fields']['call']) as $key => $value) {
                    $item[$key] = $value;
                }
            } elseif ( $key === 'price' ) {
                $item['price'] = $record['fields']['price']['currencyCode'] 
                    . ' ' 
                    . $record['fields']['price']['amount'];
            } elseif ( !empty($record['fields'][$key]['key']) ) {
                $item[$key] = $record['fields'][$key]['key'];
            } else {
                $item[$key] = $value;
            }
        }

        return $item;
    }
        
    /**
     * Flattens bib record into simple hash
     * 
     * @param  object $record Complex record object
     * @return array          Flat asociative array
     */

    private function flatten_bib ($token, $record)
    {
        $bib = [];

        // Extract the data from the structure so that it can be returned in a flat hash
        foreach ($record as $key => $value) {

            if ( $key == 'bib' ) {

                for ($i = 0; $i < count($record['bib']['fields']); $i++) {
                    for ($x = 0; $x < count($record['bib']['fields'][$i]['subfields']); $x++) {
                        if ( $record['bib']['fields'][$i]['subfields'][$x]['code'] === '_' ) {
                            $bib[$record['bib']['fields'][$i]['tag']] = $record['bib']['fields'][$i]['subfields'][$x]['data'];
                        } else {
                            $bib[$record['bib']['fields'][$i]['tag'] 
                                . '_' 
                                . $record['bib']['fields'][$i]['subfields'][$x]['code']] 
                                = $record['bib']['fields'][$i]['subfields'][$x]['data'];
                        }
                    }
                }

            } elseif ( $key == 'bibCircInfo' ) {

                $bib['bibCircInfo'] = $this->get_bib_circ_info($token, $record[$key]['key']);

            } elseif ( $key == 'callList' ) {
   
                $bib['callList'] = $this->flatten_call_list($token, $record['callList']);

            } elseif ( $key == 'holdRecordList' ) {
   
                for ($i = 0; $i < count($record['holdRecordList']); $i++) { 
                    $bib['holdRecordList'][$i] = $this->get_hold($token, $record['holdRecordList'][$i]['key']);
                }

            } elseif ( !empty($record[$key]['key']) ) {

                $bib[$key] = $record[$key]['key'];

            } else {

                $bib[$key] = $value;
            }
        }

        return $bib;
    }

    /**
     * Get catalog search indexes
     *
     * @access public
     * @param  string $token          Session token returned by ILSWS
     * @return array  $search_indexes Array of valid index names
     */

    public function get_catalog_indexes ($token = null)
    {
        $search_indexes = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        $describe = $this->send_get("$this->base_url/catalog/bib/describe", $token, []);

        foreach ($describe['searchIndexList'] as $index) {
            array_push($search_indexes, $index['name']);
        }

        return $search_indexes;
    }

    /**
     * Get bib MARC data
     * 
     * @param  string $token        Session token returned by ILSWS
     * @param  string $bib_key      Bibliographic record key
     * @return array                Flat associative array with MARC record
     */

    public function get_bib_marc ($token = null, $bib_key = null) 
    {
        $bib = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('bib_key', $bib_key, 'r:#^\d{1,8}$#');

        $response = $this->send_get("$this->base_url/catalog/bib/key/$bib_key", $token, []);

        if ( !empty($response['fields']['bib']) ) {
            $bib['key'] = $response['key'];
            foreach ($response['fields']['bib'] as $marc_key => $marc_value) {
                if ( !is_array($marc_value) ) {
                    $bib[$marc_key] = $marc_value;
                } else {
                    foreach ($marc_value as $tag) {
                        if ( !empty($tag['tag']) ) {
                            foreach ($tag['subfields'] as $subfield) {
                                if ( $subfield['code'] == '_' ) {
                                    $bib[$tag['tag']] = $subfield['data'];
                                } else {
                                    $bib[$tag['tag'] . ' ' . $tag['inds'] . ' _' . $subfield['code']] = $subfield['data'];
                                }
                            }
                        }
                    }
                }
            }
        }

        return $bib;
    }

    /**
     * Validate bib field names using the API describe functions
     *
     * @param  string $token       Session token returned by ILSWS
     * @param  string $field_list  Comma-delimited list of fields to be validated
     * @return object $response    Object containing include list, array of 
     *                             valid_fields, array of filter fields, array 
     *                             of include fields, and index list.
     */

    private function validate_bib_fields ($token = null, $field_list = '')
    {
        $response = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        // Convert the input fields to an array
        $input_fields = preg_split("/[,{}]/", $field_list, -1, PREG_SPLIT_NO_EMPTY);

        // Get the fields to validate against
        $bib_fields = []; 
        $describe = $this->send_get("$this->base_url/catalog/bib/describe", $token, []);
        foreach ($describe['fields'] as $field) {
            array_push($bib_fields, $field['name']);
        }
        array_push($bib_fields, '*');
        array_push($bib_fields, 'key');

        /**
         * Check if there are unvalidated fields left after checking against
         * bib fields. If there are, check against call fields, next.
         */
        $diff_fields = array_diff($input_fields, $bib_fields);

        $call_fields = [];
        if ( !empty($diff_fields) ) {
            $describe = $this->send_get("$this->base_url/catalog/call/describe", $token, []);
            foreach ($describe['fields'] as $field) {
                array_push($call_fields, $field['name']);
            }
        }

        /**
         * Check again. if there are still unvalidated fields after checking against
         * the call fields, check against item fields.
         */
        $diff_fields = array_diff($diff_fields, $call_fields);

        $item_fields = [];
        if ( !empty($diff_fields) ) {
            $describe = $this->send_get("$this->base_url/catalog/item/describe", $token, []);
            foreach ($describe['fields'] as $field) {
                array_push($item_fields, $field['name']);
            }
        }

        /**
         * Check one last time. If there are still unvalidated fields, they should be
         * bibliographic tag fields used for filtering results. Throw an error if we find
         * anything that doesn't look like a filter field.
         */
        $diff_fields = array_diff($diff_fields, $item_fields);

        if ( !empty($diff_fields) ) {
            foreach ($diff_fields as $field) {
                if ( !preg_match("/^\d{3}(_[a-zA-Z0-9]{1})*$/", $field) ) {
                    throw new Exception ("Invalid field \"$field\" in includeFields");
                }
            }
        }

        return 1;
    }

    /**
     * Put item into transit
     * 
     * @access public
     * @param  string $token    Session token returned by ILSWS
     * @param  string $item_key Item record key
     * @param  string $library  Library code
     * @return object $response Response from API server
     */

    public function transit_item ($token = null, $item_key = null, $new_library = null, $working_library = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('item_key', $item_key, 'r:#^\d{6,8}:\d{1,2}:\d{1,2}$#');
        $this->validate('library', $new_library, 'r:#^[A-Z]{3,9}$#');
        $this->validate('working_library', $working_library, 'r:#^[A-Z]{3,9}$#');

        $data = [
            'resource' => '/circulation/transit',
            'fields' => [
                'destinationLibrary' => ['resource' => '/policy/library', 'key' => $new_library],
                'item' => ['resource' => '/catalog/item', 'key' => $item_key],
                'transitReason' => 'EXCHANGE',
                ]
            ];
        $json =  json_encode($data);

        // Add header and role required for this API endpoint
        $options = [];
        $options['header'] = "SD-Working-LibraryID: $working_library";
        $options['role'] = 'STAFF';
 
        // Describe patron register function
        $response = $this->send_query("$this->base_url/circulation/transit", $token, $json, 'POST', $options);

        return $response;
    }

    /**
     * Receive an intransit item
     *
     * @access public
     * @param  string  $token    Session token returned by ILSWS
     * @param  integer $item_id  Item record barcode
     * @return object  $response Response from API server
     */

    public function untransit_item ($token = null, $item_id = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('item_id', $item_id, 'i:30000000000000,39999999999999');

        $json = "{\"itemBarcode\":\"$item_barcode\"}";
        $response = $this->send_query("$this->base_url/circulation/untransit", $token, $json, 'POST');

        return $response;
    }

    /**
     * Change item library
     * 
     * @access public
     * @param  string $token    Session token returned by ILSWS
     * @param  string $item_key Item record key
     * @return object $response Response from API server
     */

    public function change_item_library ($token = null, $item_key = null, $library = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('item_key', $item_key, 'r:#^\d{6,8}:\d{1,2}:\d{1,2}$#');
        $this->validate('library', $library, 'r:#^[A-Z]{3,9}$#');

        $json = "{\"resource\":\"/catalog/item\",\"key\":\"$item_key\",\"fields\":{\"library\":{\"resource\":\"/policy/library\",\"key\":\"$library\"}}}";
        $response = $this->send_query("$this->base_url/catalog/item/key/$item_key", $token, $json, 'PUT');

        return $response;
    }

    /**
     * Retrieves bib information
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $bib_key     Bibliographic record key
     * @param  string $field_list  Comma or comma and space delimited list of fields
     *                             to be returned
     * @return object              Flat associative array containing bib information
     */

    public function get_bib ($token = null, $bib_key = null, $field_list = '') 
    {
        $bib = [];
        $fields = preg_split("/[,{}]+/", $field_list, -1, PREG_SPLIT_NO_EMPTY);

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('bib_key', $bib_key, 'r:#^\d{1,8}$#');

        // Validate the $field_list
        if ( $this->config['symphony']['validate_catalog_fields'] ) {
            $this->validate_bib_fields($token, $field_list);
        } else {
            $this->validate('field_list', $field_list, 'r:#^[A-Z0-9a-z_{},*]{2,256}$#');
        }

        $response = $this->send_get("$this->base_url/catalog/bib/key/$bib_key?includeFields=" . $field_list, $token, []);

        if ( !empty($response['fields']) ) {
   
            // Flatten the structure to a simple hash 
            $temp = $this->flatten_bib($token, $response['fields']);

            // Filter out empty or not requested fields 
            
            $bib['key'] = $response['key'];
            foreach ($fields as $field) {
                if ( !empty($temp[$field]) ) {
                    $bib[$field] = $temp[$field];
                }
            }
        }

        return $bib;
    }

    /**
     * Retrieves item information
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $item_key    Item key
     * @param  string $field_list  Comma or comma and space delimited list of fields
     *                             to be returned
     * @return object              Flat associative array containing item information
     */

    public function get_item ($token = null, $item_key = null, $field_list = '*')
    {
        $item = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('item_key', $item_key, 'r:#^(\d{6,8})(:\d{1,2}){0,2}$#');

        // Validate the $field_list
        if ( $this->config['symphony']['validate_catalog_fields'] ) {
            $this->validate_fields($token, 'item', $field_list);
        } else {
            $this->validate('field_list', $field_list, 'r:#^[A-Za-z0-9_{},*]{2,256}$#');
        }

        $item = $this->send_get("$this->base_url/catalog/item/key/$item_key?includeFields=$field_list", $token, []);

        if ( !empty($item['fields']) ) {
            $item = $this->flatten_item($token, $item);
        }

        return $item;
    }

    /**
     * Get a call number
     *
     * @param  string $token       Session token returned by ILSWS
     * @param  string $call_key    Call number key
     * @return object              Flat associative array containing the response from ILSWS
     */

    public function get_call_number ($token = null, $call_key = null, $field_list = '*')
    {
        $call = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('call_key', $call_key, 'r:#^\d{6,8}:\d{1,2}$#');

        // Validate the $field_list
        if ( $this->config['symphony']['validate_catalog_fields'] ) {
            $this->validate_fields($token, 'call', $field_list);
        } else {
            $this->validate('field_list', $field_list, 'r:#^[A-Z0-9a-z_{},*]{2,256}$#');
        }

        $call = $this->send_get("$this->base_url/catalog/call/key/$call_key?includeFields=$field_list", $token);

        if ( !empty($call['fields']) ) {
            $call = $this->flatten_call($token, $call);
        }

        return $call;
    }

    /**
     * Describes the item record (used to determine valid indexes and fields)
     * 
     * @param  string $token The session token returned by ILSWS
     * @return object        Associative array of response from ILSWS
     */

    public function describe_item ($token = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
     
        return $this->send_get("$this->base_url/catalog/item/describe", $token, []);
    }

    /**
     * Describes the bib record (used to determine valid indexes and fields)
     * 
     * @param  string $token The session token returned by ILSWS
     * @return object        Associative array of response from ILSWS
     */

    public function describe_bib ($token = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
     
        return $this->send_get("$this->base_url/catalog/bib/describe", $token, []);
    }

    /**
     * Removes accents, punctuation, and non-ascii characters to
     * create search string acceptable to ILSWS
     *
     * @param  string $terms
     * @return string $terms
     */

    public function prepare_search ($terms = null)
    {
        // Trim leading and trailing whitespace
        $terms = trim($terms);

        // Validate
        $this->validate('terms', $terms, 's:256');

        // Change utf8 letters with accents to ascii characters
        setlocale(LC_ALL, "en_US.utf8");
        $terms = iconv("utf-8", "ASCII//TRANSLIT", $terms);

        // Remove boolean operators
        $terms = preg_replace("/(\s+)(and|or|not)(\s+)/", ' ', $terms);

        // Replace certain characters with a space
        $terms = preg_replace("/[\\\:;,\/\|]/", ' ', $terms);

        // Remove most punctuation and other unwanted characters
        $terms = preg_replace("/[!?&+=><%#\'\"\{\}\(\)\[\]]/", '', $terms);

        // Remove internal non-printing characters
        $terms = preg_replace('/[^\x20-\x7E]/','', $terms);

        // Replace multiple spaces with a single space
        $terms = preg_replace('/\s+/', ' ', $terms);

        return $terms;
    }

    /**
     * Search the catalog for bib records
     * 
     * @param  string $token    The session token returned by ILSWS
     * @param  string $index    The index to search
     * @param  string $value    The value to search for
     * @param  object $params   Associative array of optional parameters
     * @return object           Associative array containing search results
     */

    public function search_bib ($token = null, $index = null, $value = null, $params = null)
    {
        $fields = preg_split("/[,{}]+/", $params['includeFields'], -1, PREG_SPLIT_NO_EMPTY);

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('value', $value, 's:40');

        if ( $this->config['symphony']['validate_catalog_fields'] ) {

            // Validate fields and get valid search indexes
            $this->validate_bib_fields($token, $params['includeFields']);

            // Validate the search index 
            $index_list = $this->get_catalog_indexes($token);
            $this->validate('index', $index, 'v:' . implode('|', $index_list));

        } else {
            $this->validate('includeFields', $params['includeFields'], 'r:#^[A-Z0-9a-z_{},]{2,256}$#');
        }

        /** 
         * Valid incoming params are: 
         * ct            = number of results to return,
         * rw            = row to start on (so you can page through results),
         * j             = boolean AND or OR to use with multiple search terms, and
         * includeFields = fields to return in result.
         *
         * Any incoming q will be replaced by the values $index and $value.
         */

        $params = [
            'q'             => "$index:$value",
            'ct'            => $params['ct'] ?? '1000',
            'rw'            => $params['rw'] ?? '1',
            'j'             => $params['j'] ?? 'AND',
            'includeFields' => $params['includeFields'],
            ];

        $response = $this->send_get("$this->base_url/catalog/bib/search", $token, $params);

        $records = [];
        if ( !empty($response['totalResults']) && $response['totalResults'] > 0 ) {

            for ($i = 0; $i < count($response['result']); $i++) {

                if ( !is_null($response['result'][$i]) ) {

                    $bib = $this->flatten_bib($token, $response['result'][$i]['fields']);
                    $bib['key'] = $response['result'][$i]['key'];

                    $filtered_bib = [];
                    foreach ($fields as $field) {
                        if ( !empty($bib[$field]) ) {
                            $filtered_bib[$field] = $bib[$field];
                        }
                    }
                    array_push($records, $filtered_bib);
                }
            }
        }

        return $records;
    }

    /**
     * Pulls list of items checked out to a patron
     * 
     * @access public
     * @param  string  $token          Session token returned by ILSWS
     * @param  integer $patron_key     Patron key of patron whose records we need to see
     * @param  string  $include_fields Optional 
     * @return array   $return         Associative array of item keys and libraries
     */

    public function get_patron_checkouts ($token = null, $patron_key = null, $include_fields = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');

        if (!$include_fields) {
            $include_fields = 'item,library';
        }

        $response = $this->send_get("$this->base_url/user/patron/key/$patron_key?includeFields=circRecordList{*}", $token, []);
        $fields = preg_split('/,/', $include_fields);

        $return = [];
        $i = 0;
        if (count($response) > 0) {
            foreach ($response['fields']['circRecordList'] as $item) {
                foreach ($fields as $field) {
                    $data = $item['fields'][$field];
                    if (is_array($data)) {
                        $return[$i][$field] = $data['key'];
                    } else {
                        $return[$i][$field] = $data;
                    }
                }
                $i++;
            }
        }

        return $return;
    }

    /**
     * Get bibliographic circulation statistics
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $bib_key     Bibliographic record key
     * @return object              Flat associative array with circulation numbers
     */

    public function get_bib_circ_info ($token = null, $bib_key = null)
    {
        $stats = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('bib_key', $bib_key, 'r:#^\d{1,8}$#');
        
        $response = $this->send_get("$this->base_url/circulation/bibCircInfo/key/$bib_key", $token, []);

        if ( !empty($response['fields']) ) {
            foreach ($response['fields'] as $field => $value) {
                $stats[$field] = $value;
            }
        }

        return $stats;
    }

    /**
     * Get item circulation statistics
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $item_key    Item record key
     * @return object              Flat associative array with circulation numbers
     */

    public function get_item_circ_info ($token = null, $item_key = null)
    {
        $stats = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('item_key', $item_key, 'r:#^\d{6,8}:\d{1,2}:\d{1,2}$#');
        
        $response = $this->send_get("$this->base_url/circulation/itemCircInfo/key/$item_key", $token, []);

        if ( !empty($response['fields']) ) {
            foreach ($response['fields'] as $field => $value) {
                if ( !empty($response['fields'][$field]['key']) ) {
                    $stats[$field] = $response['fields'][$field]['key'];
                } else {
                    $stats[$field] = $value;
                }
            }
        }

        return $stats;
    }

    /**
     * Flatten hold record
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  object $record      Hold record object
     * @return array               Flat associative array of hold fields
     */

    private function flatten_hold ($record)
    {
        $hold = [];

        if ( !empty($record['fields']) ) {
            $hold['key'] = $record['key'];
            foreach ($record['fields'] as $field => $value) {
                if ( !empty($record['fields'][$field]['key']) ) {
                    $hold[$field] = $record['fields'][$field]['key'];
                } else {
                    $hold[$field] = $value;
                }
            }
        }

        return $hold;
    }

    /**
     * Get a hold record
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $hold_key    Hold record key
     * @return object              Associative array containing the response from ILSWS
     */

    public function get_hold ($token = null, $hold_key = null)
    {
        $hold = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('hold_key', $hold_key, 'r:#^\d{6,8}$#');

        $hold = $this->send_get("$this->base_url/circulation/holdRecord/key/$hold_key", $token, []);

        if ( !empty($hold['fields']) ) {
            $hold = $this->flatten_hold($hold);
        }

        return $hold;
    }


    /**
     * Removes URLs from the trailing end of a string
     *
     * @param  string $string String to be modifed
     * @return string $string Modified string
     */ 

    private function remove_url ($string)
    {
        $string = trim($string);
 
        $words = preg_split("/[\s]+/", $string);
        $new = [];

        foreach ($words as $word) {
            if ( !preg_match("#^(http)(s{0,1})(:\/\/)(.*)$#", $word) ) {
                array_push($new, $word);
            }
        }

        return implode(' ', $new);
    }

    /**
     * Pulls a hold list for a given library
     * 
     * @param  string $token       Session token returned by ILSWS
     * @param  string $library_key Library key (three character)
     * @return object              Associative array containing the response from ILSWS
     */

    public function get_library_paging_list ($token = null, $library_key = null)
    {
        $list = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('library_key', $library_key, 'r:#^[A-Z]$#');

        $include_fields = 'pullList{holdRecord{holdType,status,pickupLibrary},item{call{bib{author,title},callNumber,sortCallNumber},barcode,currentLocation{description}itemType}}';
        $response = $this->send_get("$this->base_url/circulation/holdItemPullList/key/$library_key", $token, ['includeFields' => $include_fields]);
        
        if ( !empty($response['fields']['pullList']) ) {
            foreach ($response['fields']['pullList'] as $hold) {

                $record = [];

                $record['holdType'] = $hold['fields']['holdRecord']['fields']['holdType'];
                $record['status'] = $hold['fields']['holdRecord']['fields']['status'];
                $record['pickupLibrary'] = $hold['fields']['holdRecord']['fields']['pickupLibrary']['key'];
                $record['item'] = $hold['fields']['item']['key'];
                $record['bib'] = $hold['fields']['item']['fields']['call']['fields']['bib']['key'];
                $record['author'] = $hold['fields']['item']['fields']['call']['fields']['bib']['fields']['author'];
                $record['title'] = $hold['fields']['item']['fields']['call']['fields']['bib']['fields']['title'];
                $record['callNumber'] = $hold['fields']['item']['fields']['call']['fields']['callNumber'];
                $record['sortCallNumber'] = $hold['fields']['item']['fields']['call']['fields']['sortCallNumber'];
                $record['barcode'] = $hold['fields']['item']['fields']['barcode'];
                $record['currentLocation'] = $hold['fields']['item']['fields']['currentLocation']['key'];
                $record['locationDescription'] = $hold['fields']['item']['fields']['currentLocation']['fields']['description'];
                $record['itemType'] = $hold['fields']['item']['fields']['itemType']['key'];
               
                // Remove URL from author field 
                $record['author'] = $this->remove_url($record['author']);

                array_push($list, $record);
            }
        }

        return $list;
    }

    /**
     * Deletes a patron
     *
     * @param  string $token       The session token returned by ILSWS
     * @param  string $patron_key  The patron key of the user to delete
     * @return string              Returns 1 if successful, 0 if not
     */

    public function delete_patron ($token = null, $patron_key = null)
    {
        $retval = 0;
        $json = '';

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');

        $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json, 'DELETE');
        if ( $this->code == 204 ) {
            $retval = 1;
        }

        return $retval;
    }

    /**
     * Resets a user password
     *
     * @param  string $token      The session token returned by ILSWS
     * @param  string $json       JSON containing either currentPassword and newPassword or
     *                            resetPasswordToken and newPassword
     * @param  array  $options    Associative array of options (role, client_id)
     * @return object             Associative array containing response from ILSWS
     */

    public function change_patron_password ($token = null, $json = null, $options = [])
    {

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('json', $json, 'j');

        return $this->send_query("$this->base_url/user/patron/changeMyPassword", $token, $json, 'POST', $options);
    } 

    /**
     * Resets a user password via call-back to a web application and email
     *
     * @param  string $token      The session token returned by ILSWS
     * @param  string $patron_id  The patron barcode
     * @param  string $url        The call-back URL for the web application
     * @param  string $email      Optional email address to use and validate
     * @return object             Associative array containing response from ILSWS
     */

    public function reset_patron_password ($token = null, $patron_id = null, $url = null, $email = null)
    {


        $data = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_id', $patron_id, 'r:#[A-Z0-9]{6,20}$#');
        $this->validate('url', $url, 'u');

        $data = [
            'barcode' => $patron_id,
            'resetPasswordUrl' => $url,
            ];

        if ( $email ) {
            $this->validate('email', $email, 'e');
            $data['email'] = $email;
        }

        $json = json_encode($data);

        return $this->send_query("$this->base_url/user/patron/resetMyPassword", $token, $json, 'POST');
    } 

    /**
     * Get patron indexes
     * 
     * @param  string $token     The session token returned by ILSWS
     * @return array             Array of valid patron indexes
     */

    public function get_patron_indexes ($token = null)
    {
        $indexes = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        $describe = $this->send_get("$this->base_url/user/patron/describe", $token, []);

        foreach ($describe['searchIndexList'] as $index) {
            array_push($indexes, $index['name']);
        }

        return $indexes;
    }

    /**
     * Function to check for duplicate accounts by searching in two indexes
     * and comparing the resulting arrays
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $index1    First search index
     * @param  string $search1   First search string
     * @param  string $index2    Second search index
     * @param  string $search2   Second search string
     * @return string            Boolean 1 or 0 depending on whether a duplicate is found
     */

    public function check_duplicate ($token = null, $index1 = null, $search1 = null, $index2 = null, $search2 = null)
    {
        $duplicate = 0;
        $matches = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('search1', $search1, 's:40');
        $this->validate('search2', $search2, 's:40');

        if ( $this->config['symphony']['validate_patron_indexes'] ) {
            $patron_indexes = $this->get_patron_indexes($token);
            $this->validate('index1', $index1, 'v:' . implode('|', $patron_indexes));
            $this->validate('index2', $index2, 'v:' . implode('|', $patron_indexes));
        } else {
            $this->validate('index1', $index1, 'r:#^[A-Z0-9a-z_]{2,9}$#');
            $this->validate('index2', $index2, 'r:#^[A-Z0-9a-z_]{2,9}$#');
        }

        if ( preg_match('/street/i', $index1) ) {
            $search1 = preg_replace('/[^A-Za-z0-9\- ]/', '', $search1);
        }
        if ( preg_match('/street/i', $index2) ) {
            $search2 = preg_replace('/[^A-Za-z0-9\- ]/', '', $search2);
        }
        if ( preg_match('/date/i', $index1) ) {
            $search1 = preg_replace('/-/', '', $this->create_field_date('search', $search1));
        }
        if ( preg_match('/date/i', $index2) ) {
            $search2 = preg_replace('/-/', '', $this->create_field_date('search2', $search2));
        }

        if ( $this->config['debug']['query'] ) {
            error_log("DEBUG_QUERY $index1:$search1", 0);
            error_log("DEBUG_QUERY $index2:$search2", 0);
        }

        $result1 = $this->search_patron($token, $index1, $search1, ['rw' => 1, 'ct' => 1000, 'includeFields' => 'key']);

        if ( isset($result1['totalResults']) && $result1['totalResults'] >= 1 ) {

            $start_row = 1;
            $result_rows = 0;

            $result2 = $this->search_patron($token, $index2, $search2, ['rw' => 1, 'ct' => 1000, 'includeFields' => 'key']);

            if ( isset($result2['totalResults']) && $result2['totalResults'] > 1 ) {

                foreach (array_filter($result1['result']) as $record1) {
                    foreach (array_filter($result2['result']) as $record2) {
                        if ( $record1['key'] === $record2['key'] ) {
                            $matches++;
                            if ( $matches > 1 ) {
                                break;
                            }
                        }
                    }
                    if ( $matches > 1 ) {
                        break;
                    }
                }
                if ( $matches > 1 ) {
                    $duplicate = 1;
                }

            } else {

                $result_rows = $result2['totalResults'];
                $start_row += 1000;
                
                while ( $result_rows >= $start_row ) {

                    $result2 = $this->search_patron($token, $index2, $search2, ['rw' => $start_row, 'ct' => 1000, 'includeFields' => 'key']);

                    foreach (array_filter($result1['result']) as $record1) {
                        foreach (array_filter($result2['result']) as $record2) {
                            if ( $record1['key'] === $record2['key'] ) {
                                $matches++;
                                if ( $matches > 1 ) {
                                    break;
                                }
                            }
                        }
                        if ( $matches > 1 ) {
                            break;
                        }
                    }
                    if ( $matches > 1 ) {
                        break;
                    }
                    $start_row += 1000;
                }
            }
        }

        if ( $matches > 1 ) {
            $duplicate = 1;
        }

        return $duplicate;
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

    public function search_authenticate ($token = null, $index = null, $search = null, $password = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('search', $search, 's:40');
        $this->validate('password', $password, 's:40');

        if ( $this->config['symphony']['validate_patron_indexes'] ) {
            $indexes = $this->get_patron_indexes($token);
            $this->validate('index', $index, 'v:' . implode('|', $indexes));
        } else {
            $this->validate('index', $index, 'r:#^[A-Z0-9a-z_]{2,9}$#');
        }

        $params = [
                'rw'            => '1',
                'ct'            => $this->config['ilsws']['max_search_count'],
                'j'             => 'AND',
                'includeFields' => 'barcode',
                ];

        $response = $this->search_patron($token, $index, $search, $params);

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

    public function authenticate_patron_id ($token = null, $patron_id = null, $password = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_id', $patron_id, 'r:#^[A-Z0-9]{6,20}$#');
        $this->validate('password', $password, 's:20');

        $patron_key = 0;

        $action = "/user/patron/authenticate";
        $json = json_encode(['barcode' => $patron_id, 'password' => $password]);

        $response = $this->send_query("$this->base_url/$action", $token, $json, 'POST');

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

    public function get_patron_attributes ($token = null, $patron_key = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        
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

        $response = $this->send_get("$this->base_url/user/patron/key/$patron_key", $token, ['includeFields' => $include_str]);

        // Extract patron attributes from the ILSWS response and assign to $attributes.
        if ( isset($response['key']) ) {
            foreach ( $include_fields as &$field ) {

                if ( $field == 'address1' ) {
                    if ( isset($response['fields']['address1']) ) {
                        foreach ($response['fields']['address1'] as &$i) {
                            if ( $i['fields']['code']['key'] == 'EMAIL' ) {
                                $attributes['email'] = $i['fields']['data'];
                            } elseif ( $i['fields']['code']['key'] == 'CITY/STATE' ) {
                                $parts = preg_split("/,\s*/", $i['fields']['data']);
                                $attributes['city'] = $parts[0];
                                $attributes['state'] = $parts[1];
                            } elseif ( $i['fields']['code']['key'] == 'ZIP' ) {
                                $attributes['zip'] = $i['fields']['data'];
                            } elseif ( $i['fields']['code']['key'] == 'PHONE' ) {
                                $attributes['telephone'] = $i['fields']['data'];
                            }
                        }
                    }
                } elseif ( isset($response['fields'][$field]['key']) ) {
                    $attributes[$field] = $response['fields'][$field]['key'];
                } elseif ( isset($response['fields'][$field]) ) {
                    $attributes[$field] = $response['fields'][$field];
                } else {
                    $attributes[$field] = '';
                }
            }
        }
        // Generate a displayName
        if ( isset($response['fields']['lastName']) && isset($response['fields']['firstName']) ) {
            $attributes['displayName'] = $response['fields']['firstName'] . ' ' . $response['fields']['lastName'];
        }
        // Generate a commonName
        if ( isset($response['fields']['lastName']) && isset($response['fields']['firstName']) ) {
            if ( isset($response['fields']['middleName']) ) {
                $attributes['commonName'] = $response['fields']['lastName'] 
                  . ', ' 
                  . $response['fields']['firstName'] 
                  . ' ' 
                  . $response['fields']['middleName'];
            } else {
                $attributes['commonName'] = $response['fields']['lastName'] 
                  . ', ' 
                  . $response['fields']['firstName'];
            }
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

    public function authenticate_patron ($token = null, $patron_id = null, $password = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_id', $patron_id, 'r:#^[A-Z0-9]{6,20}$#');

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

    public function describe_patron ($token) 
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        return $this->send_get("$this->base_url/user/patron/describe", $token);
    }

    /** 
     * Search for patron by any valid single field
     *
     * @param  string $token    The session token returned by ILSWS
     * @param  string $index    The index to search
     * @param  string $value    The value to search for
     * @param  object $params   Associative array of optional parameters
     * @return object           Associative array containing search results
     */

    public function search_patron ($token = null, $index = null, $value = null, $params = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('value', $value, 's:40');

        if ( $this->config['symphony']['validate_patron_indexes'] ) {
            $indexes = $this->get_patron_indexes($token);
            $this->validate('index', $index, 'v:' . implode('|', $indexes));
        } else {
            $this->validate('index', $index, 'r:#^[A-Z0-9a-z_]{2,9}$#');
        }

        /** 
         * Valid incoming params are: 
         * ct            = number of results to return,
         * rw            = row to start on (so you can page through results),
         * j             = boolean AND or OR to use with multiple search terms, and
         * includeFields = fields to return in result.
         *
         * Any incoming q will be replaced by the values $index and $value.
         */

        $params = [
            'q'             => "$index:$value",
            'ct'            => $params['ct'] ?? '1000',
            'rw'            => $params['rw'] ?? '1',
            'j'             => $params['j'] ?? 'AND',
            'includeFields' => $params['includeFields'] ?? $this->config['symphony']['default_patron_include_fields'],
            ];

        return $this->send_get("$this->base_url/user/patron/search", $token, $params);
    }

    /**
     * Search by alternate ID number
     * 
     * @param  string $token  The session token returned by ILSWS
     * @param  string $alt_id The user's alternate ID number
     * @param  string $count  How many records to return per page
     * @return object         Associative array containing search results
     */

    public function search_patron_alt_id ($token = null, $alt_id = null, $count = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('alt_id', $alt_id, 'i:1,99999999');
        $this->validate('count', $count, 'i:1,1000');

        return $this->search_patron($token, 'ALT_ID', $alt_id, ['ct' => $count]);
    }

    /**
     * Search for patron by ID (barcode)
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $patron_id The user's alternate ID number
     * @param  string $count     How many records to return per page
     * @return object            Associative array containing search results
     */

    public function search_patron_id ($token = null, $patron_id = null, $count = null) 
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_id', $patron_id, 'r:#^[A-Z0-9]{6,20}$#');
        $this->validate('count', $count, 'i:1,1000');

        return $this->search_patron($token, 'ID', $patron_id, ['ct' => $count]);
    }

    /**
     * Uses a birth day to determine what profile the patron should receive, assuming
     * a profile has not been set in the incoming data
     *
     * @access private
     * @param  object  $patron
     * @return string  $profile The profile
     */
    private function get_profile ($patron)
    {
        // Look in all the places we might find an incoming profile
        $profile = '';
        if (!empty($patron['profile']) ) {
            $profile = $patron['profile'];
        } elseif ( !empty($this->config['symphony']['new_fields']['alias']) 
            && !empty($patron[$this->config['symphony']['new_fields']['alias']]) ) {
            $profile = $patron[$this->config['symphony']['new_fields']['alias']];
        } elseif ( !empty($this->config['symphony']['overlay_fields']['alias']) 
            && !empty($patron[$this->config['symphony']['overlay_fields']['alias']]) ) {
            $profile = $patron[$this->config['symphony']['overlay_fields']['alias']];
        }
            
        // If we found an incoming profile, it takes precedence, so return it.
        if ( $profile ) {
            return $profile;
        }

        // Check everywhere we might find a birth date
        $dob = '';
        if ( !empty($patron['birthDate']) ) {
            $dob = $this->create_field_date('birthDate', $patron['birthDate']);
        } elseif ( !empty($this->config['symphony']['new_fields']['birthDate']['alias']) 
            && !empty($patron[$this->config['symphony']['new_fields']['birthDate']['alias']]) ) {
            $dob = $this->create_field_date('birthDate', $patron[$this->config['symphony']['new_fields']['birthDate']['alias']]);
        } elseif ( !empty($this->config['symphony']['overlay_fields']['birthDate']['alias']) 
            && !empty($patron[$this->config['symphony']['overlay_fields']['birthDate']['alias']]) ) {
            $dob = $this->create_field_date('birthDate', $patron[$this->config['symphony']['overlay_fields']['birthDate']['alias']]);
        }

        // If we got a birth date, calculate the age
        $age = 0;
        if ( $dob )  {
            $today = date('Y-m-d');
            $d1 = new DateTime($today);
            $d2 = new DateTime($dob);
            $diff = $d2->diff($d1);
            $age = $diff->y;
        }

        // Check if the age fits into a range
        if ( $age && !empty($this->config['symphony']['age_ranges']) ) {

            $ranges = $this->config['symphony']['age_ranges'];
            foreach ($ranges as $range => $value) {
                list($min, $max) = preg_split('/-/', $range);
                if ( $age >= $min && $age <= $max ) {
                    $profile = $ranges[$range];
                }
            }
        }

        return $profile;
    }

    /**
     * Check for field aliases
     *
     * @param object $patron Associative array of patron data elements
     * @param  array $fields Associative array field data from YAML configuration
     * @return array $patron Associative array of patron data elements
     */

    private function check_aliases ($patron, $fields)
    {
        foreach ($fields as $field => $value) {

            // Check if the data is coming in with a different field name (alias)
            if ( !empty($fields[$field]['alias']) && isset($patron[$fields[$field]['alias']]) ) {
                $patron[$field] = $patron[$fields[$field]['alias']];
            }
        }

        return $patron;
    }

    /**
     * Check for defaults and required fields and validate field values
     * 
     * @param object $patron Associative array of patron data elements
     * @param  array $fields Associative array field data from YAML configuration
     * @return array $patron Associative array of patron data elements
     */

    private function check_fields ($patron, $fields)
    {
        // Loop through each field
        foreach ($fields as $field => $value) {

            // Assign default values to empty fields, where appropriate
            if ( empty($patron[$field]) && !empty($fields[$field]['default']) ) {
                $patron[$field] = $fields[$field]['default'];
            }

            // Check for missing required fields
            if ( empty($patron[$field]) && !empty($fields[$field]['required']) && $fields[$field]['required'] === 'true' ) {
                throw new Exception ("The $field field is required");
            }

            // Validate
            if ( !empty($patron[$field]) && !empty($fields[$field]['validation']) ) {
                $this->validate($field, $patron[$field], $fields[$field]['validation']);
            }
        }

        return $patron;
    }

    /**
     * Create new field structures for JSON
     * 
     * @param  object  $patron     Associative array of patron data elements
     * @param  array   $fields     Associative array field data from YAML configuration
     * @param  integer $addr_num   Address number 1, 2, or 3
     * @param  integer $patron_key Internal SirsiDynix patron key
     * @return object  $new        Patron data structure for conversion to JSON
     */

    private function create_fields ($patron, $fields, $addr_num)
    {
        $new = [];
        $new['fields']["address$addr_num"] = [];

        // Loop through each field
        foreach ($fields as $field => $value) {
            if ( !empty($patron[$field]) ) {
                if ( !empty($fields[$field]['type']) && $fields[$field]['type'] === 'address' ) {
                    array_push($new['fields']["address$addr_num"], $this->create_field($field, $patron[$field], 'list', $addr_num, $fields[$field]));
                } elseif ( $field != 'phoneList' ) {
                    $new['fields'][$field] = $this->create_field($field, $patron[$field], $this->field_desc[$field]['type'], $addr_num);
                }
            }
        }
    
        return $new;
    }

    /**
     * Create patron data structure for overlays (overlay_fields)
     *
     * @param  object $patron     Associative array of patron data elements
     * @param  string $token      The sessions key returned by ILSWS
     * @param  string $patron_key Optional patron key to include if updating existing record
     * @return string $json       Complete Symphony patron record JSON
     */

    private function create_update_json ($patron, $token = null, $patron_key = null, $addr_num = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        $this->validate('addr_num', $addr_num, 'i:1,3');

        // Go get field descriptions if they aren't already available
        if ( empty($this->field_desc) ) {
            $this->get_field_desc($token, 'patron');
        }

        // Extract the field definitions from the configuration
        $fields = $this->config['symphony']['overlay_fields'];

        // Create the field structure
        $new = $this->create_fields($patron, $fields, $addr_num);
        $new['resource'] = '/user/patron';
        $new['key'] = $patron_key;

        // Return a JSON string suitable for use in patron_create
        return json_encode($new, JSON_PRETTY_PRINT);
    }

    /**
     * Validates and formats fields based on their type
     * 
     * @param  string $name  The field to be processed
     * @param  string $value The value to checked
     * @param  string $type  The type of field to be processed
     * @return               The output of the appropriate function
     */

    private function create_field ($field, $value, $type, $addr_num = 1, $field_data = null)
    {
        switch ($type) {
            case 'boolean':
                return $this->create_field_boolean($field, $value);
            case 'date':
                return $this->create_field_date($field, $value);
            case 'resource':
                return $this->create_field_resource($field, $value);
            case 'set':
                return $this->create_field_set($field, $value);
            case 'string':
                return $this->create_field_string($field, $value);
            case 'list':
                return $this->create_field_address($field, $value, $field_data, $addr_num);
        }
    }

    /**
     * Process generic set fields
     * 
     * @access private
     * @param  string $name  The name of the field
     * @param  string $value The incoming value to be processed
     * @return string        The validated field value
     */
 
    private function create_field_set ($name, $value)
    {
        foreach ($this->field_desc[$name]['setMembers'] as $allowed) {
            if ( $value === $allowed ) {
                return $value;
            }
        }

        # If we got here, we didn't match any of the acceptable values
        throw new Exception ("Invalid set member \"$value\" in $name");
    }

    /**
     * Process generic boolean fields
     * 
     * @access private
     * @param  string $name  The name of the field
     * @param  string $value The incoming value to be processed
     * @return string        "true" or "false"
     */

    private function create_field_boolean ($name, $value)
    {
        if ( is_bool($value) ) {
            return (boolval($value) ? 'true' : 'false');
        } elseif ( preg_match('/^true$/i', $value) ) {
            return 'true';
        } elseif ( preg_match('/^false$/i', $value) ) {
            return 'false';
        }
    }

    /**
     * Process date for generic date field. Converts incoming strings
     * in any supported format (see $supported_formats) into Symphony's 
     * preferred YYYY-MM-DD format.
     * 
     * @access private
     * @param  string $name   The name of the field
     * @param  string $value  The incoming value
     * @return string $date   The outgoing validated date string
     */

    private function create_field_date ($name, $value)
    {
        $date = '';

        $supported_formats = [
            'YYYYMMDD',
            'YYYY-MM-DD',
            'YYYY/MM/DD',
            'MM-DD-YYYY',
            'MM/DD/YYYY',
            ];

        foreach ($supported_formats as $format) {
            $date = $this->dh->validate_date($value, $format);
            if ( $date ) {
                break;
            }
        }
        
        if ( !$date ) {
            throw new Exception ("Invalid date format: \"$value\" in $name field");
        }

        return $date;
    }

    /**
     * Create structure for a generic resource field
     *
     * @access private
     * @param  string $name   The name of the field
     * @param  string $value  The incoming value
     * @return object $object The outgoing associative array object
     */

    private function create_field_resource ($name, $key, $data = '')
    {
        $object['resource'] = $this->field_desc[$name]['uri'];
        $object['key'] = $key;

        // Not all resource fields have data
        if ( $data ) {
            $object['data'] = $data;
        }

        return $object;
    }

    /**
     * Create structure for a generic string field
     *
     * @access private
     * @param  string $name   The name of the field
     * @param  string $value  The incoming value
     * @return string $value  The outgoing value
     */

    private function create_field_string ($name, $value)
    {
        $length = strlen($value);
        if ( $length < intval($this->field_desc[$name]['min']) && $length > intval($this->field_desc[$name]['max']) ) {
            throw new Exception("Invalid field length $length in $name field");
        }

        return $value;
    }

    /**
     * Create phone structure for use in patron_update, when a phoneList is supplied for use
     * with SMS messaging. Note: this function only supports a single number for SMS, although
     * individual types of messages may be turned on or off.
     *
     * @access private
     * @param  string $patron_key The patron key
     * @param  array  $params     SMS message types with which to use this number
     * @return object $structure  Associative array containing result
     */

    private function create_field_phone ($patron_key = null, $params = null)
    {

        // Remove non-digit characters from the number
        $telephone = preg_replace('/\D/', '', $params['number']);

        // Validate everthing!
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        $this->validate('telephone', $telephone, 'i:1000000000,9999999999');
        $this->validate('countryCode', $params['countryCode'], 'r:/^[A-Z]{2}$/');
        $this->validate('bills', $params['bills'], 'o');
        $this->validate('general', $params['general'], 'o');
        $this->validate('holds', $params['holds'], 'o');
        $this->validate('manual', $params['manual'], 'o');
        $this->validate('overdues', $params['overdues'], 'o');

        // Assign default values as needed
        $params = [
            'countryCode' => $params['countryCode'] ?? 'US',
            'bills'       => $params['bills'] ?? true,
            'general'     => $params['general'] ?? true,
            'holds'       => $params['holds'] ?? true,
            'manual'      => $params['manual'] ?? true,
            'overdues'    => $params['overdues'] ?? true,
            ];

        // Create the phoneList structure required by Symphony 
        $structure = [
            'resource' => '/user/patron/phone',
            'fields' => [
                'patron' => [
                    'resource' => '/user/patron',
                    'key' => $patron_key,
                    ],
                'countryCode' => [
                    'resource' => '/policy/countryCode',
                    'key' => $params['countryCode'],
                    ],
                'number' => $telephone,
                'bills' => $params['bills'],
                'general' => $params['general'],
                'holds' => $params['holds'],
                'manual' => $params['manual'],
                'overdues' => $params['overdues'],
                ],
            ];

        return $structure;
    }

    /**
     * Create address structure for use in patron update
     * 
     * @access private
     * @param  string $field   Name of address field
     * @return object $address Address object for insertion into a patron record
     */

    private function create_field_address ($field, $field_value, $fields, $addr_num)
    {
        foreach ($fields as $subfield => $value) {

            // Check if the data is coming in with a different field name (alias)
            if ( empty($patron[$subfield]) && !empty($fields[$field][$subfield]['alias']) ) {
                $patron[$subfield] = $patron[$fields[$field][$subfield]['alias']];
            }

            // Assign default values where appropriate
            if ( empty($patron[$subfield]) && !empty($fields[$field][$subfield]['default']) ) {
                $patron[$subfield] = $fields[$field][$subfield]['default'];
            }

            // Check for missing required fields
            if ( empty($patron[$subfield]) && !empty($fields[$subfield]['required']) && boolval($fields[$subfield]['required']) ) {
                throw new Exception ("The $field $subfield field is required");
            }

            // Create address structure
            $address = [];
            $address['resource'] = "/user/patron/address$addr_num";
            $address['fields']['code']['resource'] = "/policy/patronAddress$addr_num";
            $address['fields']['code']['key'] = $field;
            $address['fields']['data'] = $field_value;

            // Add this subfield to the address one array
            return $address;
        }
    }

    /**
     * Calculate expiration date based on configuration
     * 
     * @param  integer $days            Number of days to add to today's date to calculate expiration
     * @return date    $expiration_date Todays date plus the online account expiration (days)
     */

    public function get_expiration ($days = null)
    {
        $expiration = null;

        if ( $days ) {
            $today = date('Y-m-d');
            $expiration = date('Y-m-d', strtotime($today . " + $days day"));
        }

        return $expiration;
    }

    /**
     * Create patron data structure required by the patron_register
     * function to create the JSON patron data structure required by the API
     *
     * @param  object  $patron     Associative array of patron data elements
     * @param  string  $token      The session key returned by ILSWS
     * @param  string  $patron_key Symphony patron key
     * @param  integer $addr_num   Optional Address number to update (1, 2, or 3, defaults to 1)
     * @return string  $new        Symphony patron record JSON
     */

    private function create_register_json ($patron, $token = null, $addr_num = 1)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('addr_num', $addr_num, 'r:#^[123]{1}$#');

        // Get patron profile based on age of patron
        if ( empty($patron['profile']) ) {
            $patron['profile'] = $this->get_profile($patron);
        }

        // Get or generate the patron barcode
        $patron['barcode'] = empty($patron['barcode']) ? $this->gen_temp_barcode($patron['lastName'], $patron['firstName'], $patron['street']) : $patron['barcode'];

        // Create the data structure
        $new = $this->create_fields($patron, $this->config['symphony']['new_fields'], $addr_num, null);
        $new['resource'] = '/user/patron';
        if ( $patron['profile'] === 'ONLINE' ) {
            $new['fields']['privilegeExpiresDate'] = $this->get_expiration($this->config['symphony']['online_account_expiration']);
        }
         
        // Return a JSON string suitable for use in patron_register
        return json_encode($new, JSON_PRETTY_PRINT);
    }

    /**
     * Register a new patron and send welcome email to patron. Defaults to
     * English, but supports alternate language templates.
     * 
     * @param  object  $patron     Associative array containing patron data
     * @param  string  $token      The session token returned by ILSWS
     * @param  integer $addr_num   Optional Address number to update (1, 2, or 3, defaults to 1)
     * @param  array   $options    Associative array of options (role, client_id, template, subject)
     * @return object  $response   Associative array containing response from ILSWS
     */

    public function register_patron ($patron, $token = null, $addr_num = null, $options = [])
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('addr_num', $addr_num, 'r:#^[123]{1}$#');

        $role = !empty($options['role']) ? $options['role'] : 'PATRON';
        $this->validate('role', $role, 'v:STAFF|PATRON|GUEST');

        $client_id = !empty($options['client_id']) ? $options['client_id'] : $this->config['ilsws']['client_id'];
        $this->validate('client_id', $client_id, 'r:#^[A-Za-z]{4,20}$#');

        $template = !empty($options['template']) ? $options['template'] : '';
        $this->validate('template', $template, 'r:#^([a-zA-Z0-9]{1,40})(\.)(html|text)(\.)(twig)$#');

        $subject = !empty($options['subject']) ? $options['subject'] : '';
        $this->validate('subject', $subject, 's:20');

        $response = [];

        // Get field metadata from Symphony and config
        $this->get_field_desc($token, 'patron');
        $fields = $this->config['symphony']['new_fields'];

        // Convert aliases to Symphony fields
        $patron = $this->check_aliases($patron, $fields);

        // Check fields for required and default values and validate
        $patron = $this->check_fields($patron, $fields);

        // Determine the language code to use for the template
        $languages = [
            'CHINESE' => 'zh-hans',
            'DUTCH' => 'nl',
            'ENGLISH' => 'en',
            'FRENCH' => 'fr',
            'FRENCH-AF' => 'fr',
            'GERMAN' => 'de',
            'HUNGARIAN' => 'hu',
            'JAPANESE' => 'jp',
            'ROMANIAN' => 'ro',
            'RUSSIAN' => 'ru',
            'SOMALI' => 'so',
            'SPANISH' => 'es',
            'VIETNAMESE' => 'vi',
            ];
        $language = !empty($patron['language']) ? $languages[$patron['language']] : 'en';

        if ( $template ) {
            if ( is_readable($this->config['symphony']['template_path'] . '/' . $template . '.' . $language) ) {
                $template = $template . '.' . $language;
            } else {
                if ( is_readable($this->config['symphony']['template_path'] . '/' . $template . '.' . 'en') ) {
                    $template = $template . '.' . 'en';
                } else {
                    throw new Exception("Missing or unreadable template file: $template");
                }
            }
        }

        // Create the required record structure for a registration
        $json = $this->create_register_json($patron, $token, $addr_num);
        if ( $this->config['debug']['register'] ) {
            error_log("DEBUG_REGISTER $json", 0);
        }

        // Send initial registration (and generate email)
        $options = [];
        $options['role'] = $role;
        $options['client_id'] = $client_id;
        $response = $this->send_query("$this->base_url/user/patron", $token, $json, 'POST', $options);

        if ( !empty($response['key']) ) { 
            $patron_key = $response['key'];

            // If the barcode doesn't look like a real 14-digit barcode then change it to the patron key
            if ( empty($patron['barcode']) || !preg_match('/^\d{14}$/', $patron['barcode']) ) {

                // Assign the patron_key from the initial registration to the update array
                $patron['barcode'] = $patron_key;
                if ( !$this->change_barcode($token, $patron_key, $patron_key, $options) ) {
                    throw new Exception('Unable to set barcode to patron key');
                }
            }

            if ( !empty($patron['phoneList']) ) {
                if ( !$this->update_phone_list($patron['phoneList'], $token, $patron_key, $options) ) {
                    throw new Exception('SMS phone list update failed');
                }
            }

            if ( $template && $this->validate('EMAIL', $patron['EMAIL'], 'e') ) {
                if ( !$subject ) {
                    $subject = !empty($this->config['smtp']['smtp_default_subject']) ? $this->config['smtp']['smtp_default_subject'] : '';
                }
                if ( !$this->email_template($patron, $this->config['smtp']['smtp_from'], $patron['EMAIL'], $subject, $template) ) {
                    throw new Exception('Email to patron failed');
                }
            }
        }

        return $response;
    }

    /**
     * Update existing patron record
     *
     * @param  string $token The session token returned by ILSWS
     * @param  string $json  Complete JSON of patron record including barcode
     * @return object        Associative array containing result
     */

    public function update_patron ($patron, $token = null, $patron_key = null, $addr_num = 1) 
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^\d{1,6}$#');
        $this->validate('addr_num', $addr_num, 'i:1,3');

        $response = [];

        // Get field metadata from Symphony and config
        $this->get_field_desc($token, 'patron');
        $fields = $this->config['symphony']['overlay_fields'];

        // Convert aliases to Symphony fields
        $patron = $this->check_aliases($patron, $fields);

        // Check fields for required and default values and validate
        $patron = $this->check_fields($patron, $fields);

        // Create the JSON data structure
        $json = $this->create_update_json($patron, $token, $patron_key, $addr_num);

        if ( $this->config['debug']['update'] ) {
            error_log("DEBUG_UPDATE $json", 0);
        }

        $response = $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json, 'PUT');

        if ( !empty($patron['phoneList']) ) {
            if ( !$this->update_phone_list($patron['phoneList'], $token, $patron_key) ) {
                throw new Exception('SMS phone list update failed');
            }
        }

        return $response;
    }

    /**
     * Update patron extended information fields related to user IDs, specifically 
     * ACTIVEID, INACTVID, PREV_ID, PREV_ID2, and STUDENT_ID
     * 
     * Please note: this function does not test to see if these fields are defined
     * in your Symphony configuration. It will throw errors if they are not.
     * 
     * @param  string  $token      The sesions token returned by ILSWS
     * @param  string  $patron_key Primary key of the patron record to be modified
     * @param  string  $patron_id  The patron ID (barcode)
     * @param  string  $option     Single character option:
     *                               a = Add active ID
     *                               i = Add inactive ID
     *                               d = Delete an ID from the ACTIVEID, INACTVID, PREV_ID, PREV_ID2, or STUDENT_ID
     * @return integer $retval     Return value:
     *                               1 = Success
     *                               0 = Failure
     */

    public function update_patron_activeid ($token = null, $patron_key = null, $patron_id = null, $option = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        $this->validate('patron_id', $patron_id, 'r:#^[A-Z0-9]{6,20}$#');
        $this->validate('option', $option, 'v:a|i|d');

        $retval = 0;
        $custom = [];

        // Get the current customInformation from the patron record
        $res = $this->send_get("$this->base_url/user/patron/key/$patron_key", $token, ['includeFields' => 'customInformation{*}']);

        if ( $res ) {
            if ( $option == 'a' ) {
                if ( !empty($res['fields']['customInformation']) ) {
                    $custom = $res['fields']['customInformation'];
                    for ( $i = 0; $i < count($custom); $i++ ) {
                        if ( $custom[$i]['fields']['code']['key'] == 'ACTIVEID' && $custom[$i]['fields']['data'] ) {
                            $values = preg_split("/,/", $custom[$i]['fields']['data']);
                            array_push($values, $patron_id);
                            $custom[$i]['fields']['data'] = implode(',', $values);
                        }
                    }
                }

            } elseif ( $option == 'i' ) {

                if ( !empty($res['fields']['customInformation']) ) {
                    $custom = $res['fields']['customInformation'];
                    for ( $i = 0; $i < count($custom); $i++ ) {
                        if ( $custom[$i]['fields']['code']['key'] == 'INACTVID' && $custom[$i]['fields']['data'] ) {
                            $values = preg_split("/,/", $custom[$i]['fields']['data']);
                            array_push($values, $patron_id);
                            $custom[$i]['fields']['data'] = implode(',', $values);
                        }
                    }
                }

            } elseif ( $option == 'd' ) {

                if ( !empty($res['fields']['customInformation']) ) {
                    $custom = $res['fields']['customInformation'];
                    for ( $i = 0; $i < count($custom); $i++ ) {
                        $fields = array('ACTIVEID','INACTVID','PREV_ID','PREV_ID2','STUDENT_ID');
                        if ( in_array($custom[$i]['fields']['code']['key'], $fields) && $custom[$i]['fields']['data'] ) {
                            $values = preg_split("/,/", $custom[$i]['fields']['data']);
                            $new_values = [];
                            foreach ( $values as $value ) {
                                if ( $value != $patron_id ) {
                                    array_push($new_values, $value);
                                }
                            }
                            $custom[$i]['fields']['data'] = implode(',', $new_values);
                        }
                    }
                }
            }

            $patron = [];
            $patron['resource'] = '/user/patron';
            $patron['key'] = $patron_key;
            $patron['fields']['customInformation'] = $custom;
 
            // Update the patron
            $res = $this->update_patron($patron, $token, $patron_key);
 
            if ( $res ) {
                $retval = 1;
            }
        }

        return $retval;
    }

    /**
     * Update the patron lastActivityDate
     *
     * @param  string $token     The session token returned by ILSWS
     * @param  string $patron_id The patron ID (barcode)
     * @return object            Associative array containing result
     */

    public function update_patron_activity ($token = null, $patron_id = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_id', $patron_id, 'r:#^[A-Z0-9]{6,20}$#');

        $json = "{\"patronBarcode\": \"$patron_id\"}";

        return $this->send_query("$this->base_url/user/patron/updateActivityDate", $token, $json, 'POST');
    }

    /**
     * Get access-point field metadata from Symphony
     * 
     * @param string $name The access point metadata to retrieve
     * @return             Updates $this->field_desc
     */

    private function get_field_desc ($token, $name) 
    {
        $field_arrays = [];
        if ( $name === 'patron' ) {
            $field_arrays = $this->describe_patron($token);
            $type = 'fields';
        } else {
            $field_arrays = $this->send_get("$this->base_url/user/patron/$name/describe", $token, []);
            $type = 'params';
        }

        // Make the fields descriptions accessible by name
        foreach ($field_arrays[$type] as $object) {
            $name = $object['name'];
            foreach ($object as $key => $value) {
                $this->field_desc[$name][$key] = $object[$key];
            }
        }

        if ( $this->config['debug']['fields'] ) {
            $json = json_encode($this->field_desc, JSON_PRETTY_PRINT);
            error_log("DEBUG_FIELDS $json", 0);
        }
    }

    /**
     * Change a patron barcode
     * 
     * @param  string  $token       The session token returned by ILSWS
     * @param  integer $patron_key  The Symphony patron key
     * @param  string  $patron_id   The new Symphony barcode (patron ID)
     * @return integer $return_code 1 for success, 0 for failure
     */

    public function change_barcode ($token = null, $patron_key = null, $patron_id = null, $options = [])
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        $this->validate('patron_id', $patron_id, 'r:#^[0-9A-Z]{6,20}$#');

        $new = [];
        $new['resource'] = '/user/patron';
        $new['key'] = $patron_key;
        $new['fields']['barcode'] = $patron_id;

        $json = json_encode($new, JSON_PRETTY_PRINT);
        $response = $this->send_query($this->base_url . "/user/patron/key/$patron_key", $token, $json, 'PUT', $options);

        $return_code = 0;
        if ( !empty($response['fields']['barcode']) && $response['fields']['barcode'] == $patron_id ) {
            $return_code = 1;
        }

        return $return_code;
    }

    /**
     * Update the SMS phone list
     * 
     * @param  array   $phone_list     Elements to include in the phone list
     * @param  string  $token          The session token returned by ILSWS
     * @param  integer $patron_key     The Symphony patron key
     * @return integer $return_code    1 for success, 0 for failure
     */

    public function update_phone_list ($phone_list, $token = null, $patron_key = null, $options = [])
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');

        $new = [];
        $new['resource'] = '/user/patron';
        $new['key'] = $patron_key;
        $new['fields']['phoneList'] = [];
        array_push($new['fields']['phoneList'], $this->create_field_phone($patron_key, $phone_list));

        $json = json_encode($new, JSON_PRETTY_PRINT);
        $response = $this->send_query($this->base_url . "/user/patron/key/$patron_key", $token, $json, 'PUT', $options);

        if ( $this->config['debug']['update'] ) {
            error_log('DEBUG_UPDATE ' . json_encode($response, JSON_PRETTY_PRINT), 0);
        }

        $return_code = 0;
        if ( !empty($response['key']) && $response['key'] === $patron_key ) {
            $return_code = 1;
        }

        return $return_code;
    }

    /**
     * Get patron custom information
     *
     * @param integer $patron_key Patron key
     * @return string $custom     Associative array of custom keys and values
     */

    public function get_patron_custom_info ($token = null, $patron_key = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        
        $response = $this->send_get("$this->base_url/user/patron/key/$patron_key", $token, ['includeFields' => 'customInformation{*}']);

        $custom = [];
        if ( !empty($response['fields']['customInformation']) ) {
            $custom = $response['fields']['customInformation'];
        }

        return $custom;
    }

    /**
     * Set all matching custom info array to a value (be careful!)
     * 
     * @param  string  $token      The session token returned by ILSWS
     * @param  integer $patron_key The Symphony patron key
     * @param  string  $key        Key of the array entry we want to modify
     * @param  string  $value      Value to put into the data field
     * @return integer $ret_val    1 for success, 0 for failure
     */ 

    public function mod_patron_custom_info ($token = null, $patron_key = null, $key = null, $value = null)
    {
        $ret_val = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        $this->validate('key', $key, 's:255');
        $this->validate('value', $value, 's:255');

        $custom = $this->get_patron_custom_info($token, $patron_key);

        $found = 0;
        $new = [];
        foreach ( $custom as $r ) {
            if ( $r['fields']['code']['key'] == $key ) {
                $r['fields']['data'] = $value;
                $found = 1;
            }
            array_push($new, $r);
        }
      
        $patron = []; 
        if ( $found ) {
            $patron['resource'] = '/user/patron';
            $patron['key'] = $patron_key;
            $patron['fields']['customInformation'] = $new;

            // Update the patron
            $json_str = json_encode($patron, 6);
            $response = $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json_str, 'PUT');
        }

        if ( $response['key'] == $patron_key ) {
            $ret_val = 1;
        }

        return $ret_val;
    }

    /**
     * Add a custom information to the patron record
     *
     * @param  string  $token      The session token returned by ILSWS
     * @param  integer $patron_key The Symphony patron key
     * @param  string  $key        Key of the array entry we want to modify
     * @param  string  $value      Value to put into the data field
     * @return integer $ret_val    1 for success, 0 for failure
     */

    public function add_patron_custom_info ($token = null, $patron_key = null, $key = null, $value = null)
    {
        $ret_val = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        $this->validate('key', $key, 's:255');
        $this->validate('value', $value, 's:255');

        $custom = $this->get_patron_custom_info($token, $patron_key);

        if ( !empty($custom) ) {
            foreach ( $custom as $r ) {
                if ( $r['fields']['data'] == $value ) {

                    // The value already exists, so return for success
                    return 1;
                }
            }
        }

        // Get the maximum index key value
        $i = 1;
        if ( !empty($custom) ) {
            foreach ( $custom as $r ) {
                if ( $r['key'] > $i ) {
                    $i = $r['key'];
                }
            }
        }
        $i++;
            
        $new = [];
        $new = [
            'resource' => '/user/patron/customInformation',
            'key' => $i,
            'fields' => [
                'code' => [
                    'resource' => '/policy/patronExtendedInformation',
                    'key' => "$key"
                ],
                'data' => $value
            ]
        ];

        array_push($custom, $new);

        $patron = []; 
        $patron['resource'] = '/user/patron';
        $patron['key'] = $patron_key;
        $patron['fields']['customInformation'] = $custom;

        // Update the patron
        $json_str = json_encode($patron, 6);
        $response = $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json_str, 'PUT');

        if ( $response['key'] == $patron_key ) {
            $ret_val = 1;
        }

        return $ret_val;
    }
        
    /**
     * Delete custom information from the patron record. Be careful this will delete all matching keys.
     *
     * @param  string  $token      The session token returned by ILSWS
     * @param  integer $patron_key The Symphony patron key
     * @param  string  $key        Key of the array entry we want to delete
     * @return integer $ret_val    1 for success, 0 for failure
     */

    public function del_patron_custom_info ($token = null, $patron_key = null, $key = null)
    {
        $ret_val = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'r:#^d{1,6}$#');
        $this->validate('key', $key, 's:255');

        $custom = $this->get_patron_custom_info($token, $patron_key);

        $new = [];
        if ( !empty($custom) ) {
            foreach ( $custom as $r ) {
                if ( $r['fields']['code']['key'] != $key ) {
                    array_push($new, $r);
                }
            }
        }

        $patron = []; 
        $patron['resource'] = '/user/patron';
        $patron['key'] = $patron_key;
        $patron['fields']['customInformation'] = $new;

        // Update the patron
        $json_str = json_encode($patron, 6);
        $response = $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json_str, 'PUT');

        if ( $response['key'] == $patron_key ) {
            $ret_val = 1;
        }

        return $ret_val;
    }

    /**
     * Return a unique temporary barcode
     * 
     * @param  string $last_name  Last name of patron
     * @param  string $first_name First name of patron
     * @param  string $street     Street address of patron
     * @return string             Temporary barcode
     */

    private function gen_temp_barcode ($last_name, $first_name, $street)
    {
        $last_name = substr($last_name, 0, 4);
        $first_name = substr($first_name, 0, 2);
        $num = rand(1,99999);

        // Extract the street name from the street address
        $words = preg_split('/\s+/', $street);
        foreach ($words as $word) {
            if ( preg_match('/^(N|NW|NE|S|SW|SE|E|W|\d+)$/', $word) ) {
                continue;
            } else {
                $street = substr($word, 0, 4);
            }
        }

        return $last_name . $first_name . $street . $num;
    }

    /**
     * Email text message from template
     *
     * @param  array  $patron    Array of patron fields to use in template
     * @param  string $to        Email address to send to
     * @param  string $from      Email address from which to send
     * @param  string $subject   Subject of email
     * @param  string $template  Template filename
     * @return string $message   Result string
     */

    public function email_template ($patron, $from, $to, $subject, $template)
    {
        $result = 0;

        // Fill template
        $loader = new \Twig\Loader\FilesystemLoader($this->config['symphony']['template_path']);
        $twig = new \Twig\Environment($loader, ['cache' => $this->config['symphony']['template_cache']]);
        $body = $twig->render($template, ['patron' => $patron]);

        // Initialize mailer
        $mail = new PHPMailer(true);

        try {
            // Server settings
            if ( $this->config['debug']['smtp'] ) {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;                // Enable verbose debug output
            }

            $mail->isSMTP();                                          // Send using SMTP
            $mail->CharSet = 'UTF-8';                                 // Use unicode
            $mail->Encoding = 'base64';                               // Encode test in base64

            $mail->Host = $this->config['smtp']['smtp_host'];         // Set the SMTP server to send through

            // If we've got email account credentials, use them
            if ( !empty($this->config['smtp']['smtp_username']) && !empty($this->config['smtp']['smtp_password']) ) {
                $mail->SMTPAuth = true;                                   // Enable SMTP authentication
                $mail->Username = $this->config['smtp']['smtp_username']; // SMTP username
                $mail->Password = $this->config['smtp']['smtp_password']; // SMTP password
            } else {
                $mail->SMTPAuth = false;
            }

            if ( !empty($this->config['smtp']['smtp_protocol']) && $this->config['smtp']['smtp_protocol'] === 'tls' ) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;      // Enable implicit TLS encryption
            }

            // TCP port to connect to. Use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`
            $mail->Port = $this->config['smtp']['smtp_port'];

            // Set from address
            $mail->setFrom($from, $this->config['smtp']['smtp_fromname']);

            // Set recipients
            $addresses = preg_split('/,/', $to);
            foreach ( $addresses as $address ) {
                 $mail->addAddress(trim($address));                                   //Name is optional
            }

            // Reply-to
            if ( !empty($this->config['smtp']['smtp_replyto']) ) {
                $mail->addReplyTo($this->config['smtp']['smtp_replyto']);
            }

            //Content
            if ( !empty($this->config['smtp']['smtp_allowhtml']) && $this->config['smtp']['smtp_allowhtml'] === 'true' ) {
                $mail->isHTML(true);                                  //Set email format to HTML
            }

            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = 'Welcome to Multnomah County Library. Your card number is ' . $patron['barcode'];

            $mail->send();
            $result = 1;

        } catch (MailerException $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }
    
        return $result;
    }

// End of class
}

// EOF
