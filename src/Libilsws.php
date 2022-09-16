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
    public function errorMessage ($error = "", $code = 0) 
    {
        $message = '';

        switch ($code) {
            case 400:
                $message .= "HTTP $code: Bad Request";
                break;
            case 401:
                $message .= "HTTP $code: Unauthorized";
                break;
            case 403:
                $message .= "HTTP $code: Forbidden";
                break;
            case 404:
                $message .= "HTTP $code: Not Found";
                break;
            case 500:
                $message .= "HTTP $code: Internal Server Error";
                break;
        }

        $err_message = json_decode($error, true);
        if ( json_last_error() === JSON_ERROR_NONE ) {
            if ( ! empty($err_message['messageList'][0]['message']) ) {
                $error = $err_message['messageList'][0]['message'];
                $message .= ": $error";
            }
        }

        if ( ! $message ) {
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

    // The ILSWS connection parameters and Symphony field configuration
    private $config;

    // Data handler instance
    private $dh;

    // ILSWS patron field description information
    private $field_desc = [];
    
    // Constructor for this class
    public function __construct($yaml_file)
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
        if ( ! $this->dh->validate($value, $rule) ) {
            throw new Exception ("Invalid $param: \"$value\" (rule: '$rule')");
        }

        return 1;
    }

    /**
     * Connect to ILSWS
     * 
     * @return string $token The x-sirs-sessionToken to be used in all subsequent headers
     */
    public function connect ()
    {

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

        $options = [
            CURLOPT_URL              => "$this->base_url/$action?$params",
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

            if ( $this->config['debug']['connect'] ) {
                error_log("DEBUG_CONNECT HTTP $this->code: $json", 0);
            }

            if ( ! preg_match('/^2\d\d$/', $this->code) ) {
                $obfuscated_url =  $this->base_url . "/$action?" . preg_replace('/(password)=(.*?([;]|$))/', '${1}=***', "$params");
                $this->error = "Connect failure: $obfuscated_url: " . curl_error($ch);
                throw new APIException($this->error);
            }

            $response = json_decode($json, true);
            $token = $response['sessionToken'];

            curl_close($ch);

        } catch (APIException $e) {

            echo $e->errorMessage($this->error, $this->code), "\n";
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

    public function send_get ($url = null, $token = null, $params = null) 
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('url', $url, 'u');
 
        // Encode the query parameters, as they will be sent in the URL
        if ( ! empty($params) ) {
            $url .= "?";
            foreach ($params as $key => $value) {
                if ( ! empty($params[$key]) ) {
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
                if ( ! $this->error ) {
                    $this->error = $json;
                }
                throw new APIException($this->error);
            }

            curl_close($ch);

        } catch (APIException $e) {

            echo $e->errorMessage($this->error, $this->code), "\n";
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
     * @return object $response   Associative array containing the response from ILSWS 
     */

    public function send_query ($url = null, $token = null, $query_json = null, $query_type = null)
    {
        $this->validate('url', $url, 'u');
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('query_type', $query_type, 'v:POST|PUT|DELETE');

        if ( $query_json ) {
            $this->validate('query_json', $query_json, 'j');
        }

        $role = 'STAFF';
        if ( preg_match('/patron\/register/', $url) ) {
            $role = 'PATRON';
        } elseif ( preg_match('/patron\/changeMyPassword/', $url) ) {
            $role = 'PATRON';
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
            'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
            "x-sirs-sessionToken: $token",
            ];

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
            if ( ! preg_match('/^2\d\d$/', $this->code) ) {
                $this->error = curl_error($ch);
                if ( ! $this->error ) {
                    $this->error = $json;
                }
                throw new APIException($this->error);
            }

            curl_close($ch);

        } catch (APIException $e) {

            echo $e->errorMessage($this->error, $this->code), "\n";
        }
        
        return json_decode($json, true);
    }

    /**
     * Flattens callList structure into simple hash
     * 
     * @access private 
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

            if ( ! is_array($value) ) {
                $item_list[$field] = $value;
            } elseif ( ! empty($call['fields'][$field]['key']) ) {
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
                    if ( ! empty($record['fields']['holdRecordList'][$i]['key']) ) {
                        $item['holdRecordList'][$i] = $this->get_hold($token, $record['fields']['holdRecordList'][$i]['key']);
                    }
                }
            } elseif ( $key === 'price' ) {
                $item['price'] = $record['fields']['price']['currencyCode'] 
                    . ' ' 
                    . $record['fields']['price']['amount'];
            } elseif ( ! empty($record['fields'][$key]['key']) ) {
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

            } elseif ( ! empty($record[$key]['key']) ) {

                $bib[$key] = $record[$key]['key'];

            } else {

                $bib[$key] = $value;
            }
        }

        return $bib;
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

        if ( $field_list != '*' ) {

            // Convert the input fields to an array
            $input_fields = preg_split('/[,{}]+/', $field_list, -1, PREG_SPLIT_NO_EMPTY);

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
     * Get catalog search indexes
     *
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
        if ( ! empty($diff_fields) ) {
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
        if ( ! empty($diff_fields) ) {
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

        if ( ! empty($diff_fields) ) {
            foreach ($diff_fields as $field) {
                if ( ! preg_match("/^\d{3}(_[a-zA-Z0-9]{1})*$/", $field) ) {
                    throw new Exception ("Invalid field \"$field\" in includeFields");
                }
            }
        }

        return 1;
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

        if ( ! empty($response['fields']['bib']) ) {
            $bib['key'] = $response['key'];
            foreach ($response['fields']['bib'] as $marc_key => $marc_value) {
                if ( ! is_array($marc_value) ) {
                    $bib[$marc_key] = $marc_value;
                } else {
                    foreach ($marc_value as $tag) {
                        if ( ! empty($tag['tag']) ) {
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

        if ( ! empty($response['fields']) ) {
            foreach ($response['fields'] as $field => $value) {
                $stats[$field] = $value;
            }
        }

        return $stats;
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

        if ( ! empty($response['fields']) ) {
   
            // Flatten the structure to a simple hash 
            $temp = $this->flatten_bib($token, $response['fields']);

            // Filter out empty or not requested fields 
            
            $bib['key'] = $response['key'];
            foreach ($fields as $field) {
                if ( ! empty($temp[$field]) ) {
                    $bib[$field] = $temp[$field];
                }
            }
        }

        return $bib;
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

        if ( ! empty($response['fields']) ) {
            foreach ($response['fields'] as $field => $value) {
                if ( ! empty($response['fields'][$field]['key']) ) {
                    $stats[$field] = $response['fields'][$field]['key'];
                } else {
                    $stats[$field] = $value;
                }
            }
        }

        return $stats;
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

        if ( ! empty($item['fields']) ) {
            $item = $this->flatten_item($token, $item);
        }

        return $item;
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

        if ( ! empty($record['fields']) ) {
            $hold['key'] = $record['key'];
            foreach ($record['fields'] as $field => $value) {
                if ( ! empty($record['fields'][$field]['key']) ) {
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

        if ( ! empty($hold['fields']) ) {
            $hold = $this->flatten_hold($hold);
        }

        return $hold;
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

        if ( ! empty($call['fields']) ) {
            $call = $this->flatten_call($token, $call);
        }

        return $call;
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

        $response = $this->send_get("$this->base_url/circulation/holdItemPullList/key/$library_key", $token);
        
        if ( ! empty($response['fields']['pullList']) ) {
            foreach ($response['fields']['pullList'] as $list_hold) {

                print_r($list_hold);

                $record = [];

                $bib_key = preg_replace("/^(\d{1,8})(.*)$/", "$1", $list_hold['fields']['item']['key']);
                $bib = $this->get_bib($token, $bib_key, 'author,title,holdRecordList,callList{callNumber,itemList{barcode,currentLocation,itemType}}');

                if ( ! empty($bib['author']) ) {
                    $record['author'] = $bib['author'];
                }
                $record['title'] = $bib['title'];

                if ( ! empty($bib['holdRecordList']) ) {
                    for ($i = 0; $i < count($bib['holdRecordList']); $i++) {
                        if ( ! empty($bib['holdRecordList'][$i]['key']) ) {
                            if ( $bib['holdRecordList'][$i]['key'] == $list_hold['fields']['holdRecord']['key'] ) {
                                $hold = $bib['holdRecordList'][$i];
                                if ( $hold['status'] != 'EXPIRED' ) {
                                    $record['holdType'] = $hold['holdType'];
                                    $record['pickupLibrary'] = $hold['pickupLibrary'];
                                    $record['placedLibrary'] = $hold['placedLibrary'];
                                    $record['status'] = $hold['status'];
                                }
                            }
                        }
                    }
                }
                
                if ( ! empty($bib['callList']) ) {
                    for ($i = 0; $i < count($bib['callList']); $i++) {
                        if ( ! empty($bib['callList'][$i]['key']) ) {    
                            if ( $bib['callList'][$i]['key'] == $list_hold['fields']['item']['key'] ) {
                                $item = $bib['callList'][$i];

                                $record['callNumber'] = $item['callNumber'];
                                $record['barcode'] = $item['barcode'];
                                $record['currentLocation'] = $item['currentLocation'];
                                $record['itemType'] = $item['itemType'];

                                $location = $this->get_policy($token, 'location', $record['currentLocation']);
                                $record['locationDescription'] = $location['fields']['description'];
                            }
                        }
                    }
                }

                array_push($list, $record);
            }
        }

        return $list;
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
        if ( ! empty($response['totalResults']) && $response['totalResults'] > 0 ) {

            for ($i = 0; $i < count($response['result']); $i++) {

                if ( ! is_null($response['result'][$i]) ) {

                    $bib = $this->flatten_bib($token, $response['result'][$i]['fields']);

                    $filtered_bib = [];
                    foreach ($fields as $field) {
                        if ( ! empty($bib[$field]) ) {
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
        $this->validate('patron_key', $patron_key, 'i:1,999999');

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
     * @return object             Associative array containing response from ILSWS
     */

    public function change_patron_password ($token = null, $json = null)
    {

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('json', $json, 'j');

        return $this->send_query("$this->base_url/user/patron/changeMyPassword", $token, $json, 'POST');
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
        $this->validate('patron_id', $patron_id, 'i:100000,29999999999999');
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
        $this->validate('patron_id', $patron_id, 'i:100000,29999999999999');
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
        $this->validate('patron_key', $patron_key, 'i:1,999999');
        
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
        $this->validate('patron_id', $patron_id, 'i:20000000000000,29999999999999');

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

        return $this->send_get("$this->base_url/user/patron/describe", $token, []);
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
        $this->validate('patron_id', $patron_id, 'i:20000000000000,29999999999999');
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
        if (! empty($patron['profile']) ) {
            $profile = $patron['profile'];
        } elseif ( ! empty($this->config['symphony']['new_fields']['alias']) 
            && ! empty($patron[$this->config['symphony']['new_fields']['alias']]) ) {
            $profile = $patron[$this->config['symphony']['new_fields']['alias']];
        } elseif ( ! empty($this->config['symphony']['overlay_fields']['alias']) 
            && ! empty($patron[$this->config['symphony']['overlay_fields']['alias']]) ) {
            $profile = $patron[$this->config['symphony']['overlay_fields']['alias']];
        }
            
        // If we found an incoming profile, it takes precedence, so return it.
        if ( $profile ) {
            return $profile;
        }

        // Check everywhere we might find a birth date
        $dob = '';
        if ( ! empty($patron['birthDate']) ) {
            $dob = $this->create_field_date('birthDate', $patron['birthDate']);
        } elseif ( ! empty($this->config['symphony']['new_fields']['birthDate']['alias']) 
            && ! empty($patron[$this->config['symphony']['new_fields']['birthDate']['alias']]) ) {
            $dob = $this->create_field_date('birthDate', $patron[$this->config['symphony']['new_fields']['birthDate']['alias']]);
        } elseif ( ! empty($this->config['symphony']['overlay_fields']['birthDate']['alias']) 
            && ! empty($patron[$this->config['symphony']['overlay_fields']['birthDate']['alias']]) ) {
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
        if ( $age && ! empty($this->config['symphony']['age_ranges']) ) {

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
     * Create patron data structure required by the patron_update function
     *
     * @param  object $patron     Associative array of patron data elements
     * @param  string $mode       new_fields or overlay_fields
     * @param  string $token      The sessions key returned by ILSWS
     * @param  string $patron_key Optional patron key to include if updating existing record
     * @return string $json       Complete Symphony patron record JSON
     */

    public function create_patron_json ($patron, $mode = null, $token = null, $patron_key = null)
    {
        $this->validate('mode', $mode, 'v:overlay_fields|new_fields');
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patron_key', $patron_key, 'i:1,999999');

        // Go get field descriptions if they aren't already available
        if ( empty($this->field_desc) ) {
            $this->get_field_desc($token, 'patron');
        }

        // Start building the object
        $new['resource'] = '/user/patron';
        $new['key'] = $patron_key;

        // Extract the field definitions from the configuration
        $fields = $this->config['symphony'][$mode];

        /**
         * Get the patron profile based on (in priority order) the incoming value, the 
         * birthDate and age ranges defined in the YAML configuration.
         */
        $patron['profile'] = $this->get_profile($patron);

        // Convert aliases to Symphony fields
        foreach ($fields as $field => $value) {

            // Check if the data is coming in with a different field name (alias)
            if ( ! empty($fields[$field]['alias']) && isset($patron[$fields[$field]['alias']]) ) {
                $patron[$field] = $patron[$fields[$field]['alias']];
            }
        }

        // Loop through each field
        foreach ($fields as $field => $value) {

            // Assign default values to empty fields, where appropriate
            if ( empty($patron[$field]) && ! empty($fields[$field]['default']) ) {
                $patron[$field] = $fields[$field]['default'];
            }

            // Check for missing required fields
            if ( empty($patron[$field]) && ! empty($fields[$field]['required']) && $fields[$field]['required'] === 'true' ) {
                throw new Exception ("The $field field is required");
            }

            // Validate
            if ( ! empty($patron[$field]) && ! empty($fields[$field]['validation']) ) {
                $this->validate($field, $patron[$field], $fields[$field]['validation']);
            }

            if ( ! empty($patron[$field]) ) {

                if ( isset($this->field_desc[$field]) ) {

                    // If this is not a list type field, we can use a generic function to process it
                    if ( $this->field_desc[$field]['type'] !== 'list' ) {

                        $new['fields'][$field] = $this->create_field($field, $patron[$field], $this->field_desc[$field]['type']);

                    } else {

                        // $field is a list so we have specific functions for the types we support, address# and phoneList
                        if ( preg_match('/^address\d{1}$/', $field) && ! empty($patron[$field]) ) {

                            $new['fields'][$field] = $this->create_field_address($field, $fields[$field], $patron[$field]);

                        } elseif ( $field === 'phoneList' ) {

                            $new['fields'][$field] = $this->create_field_phone($patron_key, $patron[$field]);

                        } else {
                            throw new Exception ("Unknown list type: $field");
                        }
                    }
                }
            }
        }

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

    private function create_field ($name, $value, $type)
    {
        switch ($type) {
            case 'boolean':
                return $this->create_field_boolean($name, $value);
            case 'date':
                return $this->create_field_date($name, $value);
            case 'resource':
                return $this->create_field_resource($name, $value);
            case 'set':
                return $this->create_field_set($name, $value);
            case 'string':
                return $this->create_field_string($name, $value);
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
        
        if ( ! $date ) {
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
        $this->validate('patron_key', $patron_key, 'i:1,999999');
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
        $structure = [[
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
            ]];

        return $structure;
    }

    /**
     * Create address structure for use in patron update
     * 
     * @access private
     * @param  string $field   Name of address field
     * @return object $address Address object for insertion into a patron record
     */

    private function create_field_address ($field, $fields, $patron)
    {
        // Determine the address number
        $num = substr($field, -1, 1);

        foreach ($fields as $subfield => $value) {

            // Check if the data is coming in with a different field name (alias)
            if ( empty($patron[$subfield]) && ! empty($fields[$field][$subfield]['alias']) ) {
                $patron[$subfield] = $patron[$fields[$field][$subfield]['alias']];
            }

            // Assign default values where appropriate
            if ( empty($patron[$subfield]) ) {
                $patron[$subfield] = $fields[$field][$subfield]['default'];
            }

            // Check for missing required fields
            if ( empty($patron[$subfield]) && ! empty($fields[$subfield]['required']) && boolval($fields[$subfield]['required']) ) {
                throw new Exception ("The $field $subfield field is required");
            }

            // Create address structure
            $address = [];
            $address['resource'] = "/user/patron/$field";
            $address['fields']['code']['resource'] = "/policy/patronAddress$num";
            $address['fields']['code']['key'] = $subfield;
            $address['fields']['data'] = $patron[$subfield];

            // Add this subfield to the address one array
            return $address;
        }
    }

    /**
     * Create patron data structure required by the patron_register
     * function
     *
     * @param  object $patron Associative array of patron data elements
     * @return string         Complete Symphony patron record JSON
     */

    public function create_register_json ($patron, $token = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        $new = [];

        // Go get field descriptions if they aren't already available
        if ( empty($this->field_desc) ) {
            $this->get_field_desc($token, 'patron');
        }

        // Get additional field metadata from Symphony
        $this->get_field_desc($token, 'register');

        /**
         * Get the patron profile based on (in priority order) the incoming value, the 
         * birthDate and age ranges defined in the YAML configuration.
         */
        $patron['profile'] = $this->get_profile($patron);

        # Extract the field definitions from the configuration
        $fields = $this->config['symphony']['new_fields'];

        // Convert aliases to Symphony fields
        foreach ($fields as $field => $value) {

            // Check if the data is coming in with a different field name (alias)
            if ( ! empty($fields[$field]['alias']) && ! empty($patron[$fields[$field]['alias']]) ) {
                $patron[$field] = $patron[$fields[$field]['alias']];
            }
        }

        foreach ($fields as $field => $value) {

            if ( $this->config['debug']['register'] && ! empty($patron[$field]) ) {
                error_log("DEBUG_REGISTER $field: $patron[$field]", 0);
            }

            // Assign default values to empty fields, where appropriate
            if ( empty($patron[$field]) && ! empty($fields[$field]['default']) ) {
                $patron[$field] = $fields[$field]['default'];
            }

            // Check for missing required fields
            if ( empty($patron[$field]) && boolval($fields[$field]['required']) ) {
                throw new Exception ("The $field field is required");
            }

            // Validate
            if( ! empty($patron[$field]) && ! empty($fields[$field]['validation']) ) {
                $this->validate($field, $patron[$field], $fields[$field]['validation']);
            }
         
            if ( ! empty($this->field_desc[$field]) ) {

                // if we have a value in $patron for this field, Create field structure based on field type
                if ( ! empty($patron[$field]) ) {
                    $new[$field] = $this->create_field($field, $patron[$field], $this->field_desc[$field]['type']);
                }

            } else {
                throw new Exception ("Unknown field: $field");
            }
        }

        // Return a JSON string suitable for use in patron_register
        return json_encode($new, JSON_PRETTY_PRINT);
    }

    /**
     * Register a new patron (with email response and duplicate checking)
     * 
     * The initial registration can't update all the fields we want to be able
     * set, so we do the initial registration, then take the patron key returned
     * and perform an update of the remaining fields.
     *
     * @param  object $patron Associative array containing patron data
     * @param  string $token The session token returned by ILSWS
     * @return object $response Associative array containing response from ILSWS
     */

    public function register_patron ($patron, $token = null)
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        $response = [];

        // Create the required record structure for a registration
        $json = $this->create_register_json($patron, $token);
        if ( $this->config['debug']['register'] ) {
            error_log("DEBUG_REGISTER $json", 0);
        }
        $response = $this->send_query("$this->base_url/user/patron/register", $token, $json, 'POST');

        // Assign the patron_key from the initial registration to the update array
        $patron_key = $response['patron']['key'];

        if ( strlen($patron_key) > 0 ) { 

            // Create a record structure with the update fields 
            $json = $this->create_patron_json($patron, 'new_fields', $token, $patron_key);
            if ( $this->config['debug']['register'] ) {
                error_log("DEBUG_REGISTER $json", 0);
            }

            // Update Symphony
            $response = $this->update_patron($token, $json, $patron_key);
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

    public function update_patron ($token = null, $json = null, $patron_key = null) 
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('json', $json, 'j');
        $this->validate('patron_key', $patron_key, 'i:1,999999');

        if ( $this->config['debug']['update'] ) {
            error_log("DEBUG_UPDATE $json", 0);
        }
        
        return $this->send_query("$this->base_url/user/patron/key/$patron_key", $token, $json, 'PUT');
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
        $this->validate('patron_id', $patron_id, 'r:#^\d{6,14}$#');

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

// End of class
}

// EOF
