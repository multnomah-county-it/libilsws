<?php

declare(strict_types=1);

namespace Libilsws;

/**
 * Multnomah County Library
 * SirsiDynix ILSWS API Support
 * Copyright (c) 2024 Multnomah County (Oregon)
 *
 * John Houser
 * john.houser@multco.us
 */

use Curl\Curl;
use DateTime;
use Exception;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Symfony\Component\Yaml\Yaml;

/**
 * Custom API exception.
 */
class APIException extends Exception
{
    /**
     * Handles API errors that should be logged.
     *
     * @param string $error The error message.
     * @param int $code The HTTP status code.
     * @return string The formatted error message.
     */
    public function errorMessage(string $error = '', int $code = 0): string
    {
        $message = '';
        $errMessage = json_decode($error, true, 10, JSON_OBJECT_AS_ARRAY);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (!empty($errMessage['messageList'][0]['message'])) {
                $error = $errMessage['messageList'][0]['message'];
            }
        } else {
            $error = 'HTML error';
        }

        switch ($code) {
            case 400:
                $message = "HTTP {$code}: Bad Request";
                break;
            case 401:
                $message = "HTTP {$code}: Unauthorized";
                break;
            case 403:
                $message = "HTTP {$code}: Forbidden";
                break;
            case 404:
                $message = "HTTP {$code}: Not Found";
                break;
            case (preg_match('/^5\d\d$/', (string) $code) ? true : false):
                $message = "HTTP {$code}: SirsiDynix Web Services unavailable";
                break;
            default:
                $message = "HTTP {$code}: {$error}";
        }

        return $message;
    }
}

class Libilsws
{
    /**
     * Public variable to share error information.
     *
     * @var string
     */
    public $error;

    /**
     * Public variable to HTML return code.
     *
     * @var int
     */
    public $code;

    /**
     * Base URL constructed from config for convenience.
     *
     * @var string
     */
    public $baseUrl;

    /**
     * The ILSWS connection parameters and Symphony field configuration.
     *
     * @var array
     */
    public $config;

    /**
     * Data handler instance.
     *
     * @var DataHandler
     */
    public $dh;

    /**
     * ILSWS patron field description information.
     *
     * @var array
     */
    public $fieldDesc = [];

    /**
     * Constructor for this class.
     *
     * @param string $yamlFile The path to the YAML configuration file.
     * @throws Exception If the YAML file is bad.
     */
    public function __construct(string $yamlFile)
    {
        $this->dh = new DataHandler();

        // Read the YAML configuration file and assign private variables
        if (filesize($yamlFile) > 0 && substr($yamlFile, -4, 4) == 'yaml') {
            $this->config = Yaml::parseFile($yamlFile);

            if ($this->config['debug']['config']) {
                error_log('DEBUG_CONFIG ' . json_encode($this->config, JSON_PRETTY_PRINT), 0);
            }
        } else {
            throw new Exception("Bad YAML file: {$yamlFile}");
        }

        $this->baseUrl = 'https://'
            . $this->config['ilsws']['hostname']
            . ':'
            . $this->config['ilsws']['port']
            . '/'
            . $this->config['ilsws']['webapp'];
    }

    /**
     * Handles exceptions, logging to STDERR in CLI or error log in web mode.
     *
     * @param string $message The exception message.
     * @return void
     */
    private function handleException(string $message = ''): void
    {
        if (php_sapi_name() === 'cli' || PHP_SAPI === 'cli') {
            // Running in CLI mode: print to STDERR
            fwrite(STDERR, "Error: {$message}" . PHP_EOL);
        } else {
            // Running in web mode: log the error
            error_log($message);
        }
    }

    /**
     * Validate call or item input fields using the API describe function.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $type Type of catalog item ('item' or 'call').
     * @param string $fieldList Comma-delimited list of fields to be validated.
     * @return int Always returns 1, if it doesn't throw an exception.
     * @throws Exception If validation fails.
     */
    private function validateFields(?string $token = null, ?string $type = null, string $fieldList = ''): int
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('type', $type, 'v:item|call');

        if ($type == 'item' && preg_match('/call{.*}/', $fieldList)) {
            $calls = preg_replace('/^(.*)(call{.*})(.*)$/', '$2', $fieldList);
            $this->validateFields($token, 'call', $calls);
            $fieldList = preg_replace('/^(.*)(call{.*})(.*)$/', '$1,$3', $fieldList);
            $fieldList = preg_replace('/,{2}/', ',', $fieldList);
        }

        if ($fieldList != '*') {
            // Convert the input fields to an array
            $innerFields = preg_replace('/^(.*){(.*)}(.*)$/', '$2', $fieldList);
            $fieldList = preg_replace('/^(.*){(.*)}(.*)$/', '$1$3', $fieldList);
            $fieldList .= ",{$innerFields}";
            $inputFields = preg_split('/[,{}]+/', $fieldList, -1, PREG_SPLIT_NO_EMPTY);
            $inputFields = array_unique($inputFields, SORT_STRING);

            // Get the fields described by the API
            $fields = [];
            $describe = $this->sendGet("{$this->baseUrl}/catalog/{$type}/describe", $token, []);
            for ($i = 0; $i < count($describe['fields']); $i++) {
                array_push($fields, $describe['fields'][$i]['name']);
            }
            // Get the item fields as well, if we're validating a get_call fieldList
            if ($type == 'call') {
                $describe = $this->sendGet("{$this->baseUrl}/catalog/item/describe", $token, []);
                for ($i = 0; $i < count($describe['fields']); $i++) {
                    array_push($fields, $describe['fields'][$i]['name']);
                }
            }
            $validList = implode('|', $fields);

            foreach ($inputFields as $field) {
                $this->validate('includeFields', $field, "v:{$validList}|*");
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
     * @param string $param Name of parameter to validate.
     * @param mixed $value Value to be validated.
     * @param string $rule Rule to apply.
     * @return int Always returns 1, if it doesn't throw an exception.
     * @throws Exception If validation fails.
     */
    private function validate(string $param, $value, string $rule): int
    {
        $result = $this->dh->validate($value, $rule);
        if ($result === 0) {
            throw new Exception("Invalid {$param}: \"{$value}\" (rule: '{$rule}')");
        }

        return $result;
    }

    /**
     * Connect to ILSWS.
     *
     * @return string The x-sirs-sessionToken to be used in all subsequent headers.
     */
    public function connect(): string
    {
        $url = $this->baseUrl . '/user/staff/login';
        $queryJson = '{"login":"' . $this->config['ilsws']['username'] . '","password":"' . $this->config['ilsws']['password'] . '"}';
        $reqNum = rand(1, 1000000000);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "SD-Response-Tracker: {$reqNum}",
            'SD-Originating-App-ID: ' . $this->config['ilsws']['app_id'],
            'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYSTATUS => true,
            CURLOPT_CONNECTTIMEOUT => $this->config['ilsws']['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $queryJson,
        ];

        try {
            $ch = curl_init();
            curl_setopt_array($ch, $options);

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($this->config['debug']['connect']) {
                error_log("DEBUG_CONNECT HTTP {$this->code}: {$json}", 0);
            }

            if (!preg_match('/^2\d\d$/', (string) $this->code)) {
                $obfuscatedUrl =  $this->baseUrl . "/$action?" . preg_replace('/(password)=(.*?([;]|$))/', '${1}=***', "$params");
                $this->error = "Connect failure: {$obfuscatedUrl}: " . curl_error($ch);
                throw new APIException($this->error);
            }

            $response = json_decode((string) $json, true);
            $token = $response['sessionToken'];

            curl_close($ch);
        } catch (APIException $e) {
            $this->handleException($e->errorMessage($this->error, $this->code));
        }

        return $token;
    }

    /**
     * Create a standard GET request object. Used by most API functions.
     *
     * @param string|null $url The URL to connect with.
     * @param string|null $token The session token returned by ILSWS.
     * @param array $params Associative array of optional parameters.
     * @return array Associative array containing response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function sendGet(?string $url = null, ?string $token = null, array $params = []): ?array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('url', $url, 'u');

        // Encode the query parameters, as they will be sent in the URL
        if (!empty($params)) {
            $url .= '?';
            foreach ($params as $key => $value) {
                if (!empty($params[$key])) {
                    $url .= "{$key}=" . urlencode((string) $params[$key]) . '&';
                }
            }
            $url = substr($url, 0, -1);
        }

        $url = preg_replace('/(.*)\#(.*)/', '$1%23$2', $url);

        // Define a random request tracker. Can help when talking with SirsiDynix
        $reqNum = rand(1, 1000000000);

        /**
         * Set $error to the URL being submitted so that it can be accessed
         * in debug mode, when there is no error
         */
        if ($this->config['debug']['query']) {
            error_log("DEBUG_QUERY {$url}", 0);
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'SD-Originating-App-ID: ' . $this->config['ilsws']['app_id'],
            "SD-Request-Tracker: {$reqNum}",
            'x-sirs-clientID: ' . $this->config['ilsws']['client_id'],
            "x-sirs-sessionToken: {$token}",
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYSTATUS => true,
            CURLOPT_CONNECTTIMEOUT => $this->config['ilsws']['timeout'],
            CURLOPT_HTTPHEADER => $headers,
        ];

        try {
            $ch = curl_init();
            curl_setopt_array($ch, $options);

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($this->config['debug']['query']) {
                error_log("DEBUG_QUERY Request number: {$reqNum}", 0);
                error_log("DEBUG_QUERY HTTP {$this->code}: {$json}", 0);
            }

            // Check for errors
            if ($this->code != 200) {
                $this->error = curl_error($ch);
                if (!$this->error) {
                    $this->error = (string) $json;
                }
                throw new APIException($this->error);
            }

            curl_close($ch);
        } catch (APIException $e) {
            $this->handleException($e->errorMessage($this->error, $this->code));
        }

        return json_decode((string) $json, true);
    }

    /**
     * Create a standard POST request object. Used by most updates and creates.
     *
     * @param string|null $url The URL to connect with.
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $queryJson JSON containing the required query elements.
     * @param string|null $queryType The query type: POST or PUT.
     * @param array $options Associative array of options (role, clientId, header).
     * @return array Associative array containing the response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function sendQuery(
        ?string $url = null,
        ?string $token = null,
        ?string $queryJson = null,
        ?string $queryType = null,
        array $options = []
    ): ?array {

        $this->validate('url', $url, 'u');
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('queryType', $queryType, 'v:POST|PUT|DELETE');

        $role = !empty($options['role']) ? $options['role'] : 'PATRON';
        $this->validate('role', $role, 'v:STAFF|PATRON|GUEST');

        $clientId = !empty($options['clientId']) ? $options['clientId'] : $this->config['ilsws']['client_id'];
        $this->validate('clientId', $clientId, 'r:#^[A-Za-z]{4,20}$#');

        $header = !empty($options['header']) ? $options['header'] : '';
        $this->validate('header', $header, 's:40');

        if ($queryJson) {
            $this->validate('queryJson', $queryJson, 'j');
        }

        // Define a random request tracker
        $reqNum = rand(1, 1000000000);

        // Define the request headers
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'SD-Originating-App-Id: ' . $this->config['ilsws']['app_id'],
            "SD-Response-Tracker: {$reqNum}",
            "SD-Preferred-Role: {$role}",
            'SD-Prompt-Return: USER_PRIVILEGE_OVRCD/' . $this->config['ilsws']['user_privilege_override'],
            "x-sirs-clientID: {$clientId}",
            "x-sirs-sessionToken: {$token}",
        ];

        // Add an optional header if it exists
        array_push($headers, $header);

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $queryType,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYSTATUS => true,
            CURLOPT_CONNECTTIMEOUT => $this->config['ilsws']['timeout'],
            CURLOPT_HTTPHEADER => $headers,
        ];

        try {
            $ch = curl_init();
            curl_setopt_array($ch, $curlOptions);

            if ($queryJson) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $queryJson);
            }

            $json = curl_exec($ch);
            $this->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($this->config['debug']['query']) {
                error_log("DEBUG_QUERY Request number: {$reqNum}", 0);
                error_log("DEBUG_QUERY HTTP {$this->code}: {$json}", 0);
            }

            // Check for errors
            if (!preg_match('/^2\d\d$/', (string) $this->code)) {
                $this->error = curl_error($ch);
                if (!$this->error) {
                    $this->error = (string) $json;
                }
                throw new APIException($this->error);
            }

            curl_close($ch);
        } catch (APIException $e) {
            $this->handleException($e->errorMessage($this->error, $this->code));
        }

        return json_decode((string) $json, true);
    }

    /**
     * Get policy returns a policy record.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $policyName Policy name for policy.
     * @param string|null $policyKey Policy key for policy.
     * @return array Associative array containing the response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function getPolicy(?string $token = null, ?string $policyName = null, ?string $policyKey = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('policy_name', $policyName, 'r:#^[A-Za-z0-9]{1,20}$#');
        $this->validate('policy_key', $policyKey, 'r:#^[A-Za-z\- 0-9]{1,10}$#');

        return $this->sendGet("{$this->baseUrl}/policy/{$policyName}/key/{$policyKey}", $token, []);
    }

    /**
     * Flattens callList structure into simple hash.
     *
     * @param string $token Session token.
     * @param array $callList Complex object with call list.
     * @return array Flat associative array.
     */
    private function flattenCallList(string $token, array $callList): array
    {
        $itemList = [];

        for ($i = 0; $i < count($callList); $i++) {
            array_push($itemList, $this->flattenCall($token, $callList[$i]));
        }

        return $itemList;
    }

    /**
     * Flatten call number record into array of items.
     *
     * @param string $token Session token.
     * @param array $call Complex object with call number record.
     * @return array Flat associative array.
     */
    private function flattenCall(string $token, array $call): array
    {
        $itemList = [];

        foreach ($call['fields'] as $field => $value) {
            if (!is_array($value)) {
                $itemList[$field] = $value;
            } elseif (!empty($call['fields'][$field]['key'])) {
                $itemList[$field] = $call['fields'][$field]['key'];
            } elseif ($field == 'itemList') {
                foreach ($call['fields']['itemList'] as $item) {
                    $item = $this->flattenItem($token, $item);
                    foreach ($item as $itemField => $itemValue) {
                        $itemList[$itemField] = $itemValue;
                    }
                }
            }
        }

        return $itemList;
    }

    /**
     * Flattens item structure into simple hash.
     *
     * @param string $token Session token.
     * @param array $record Complex object with item list.
     * @return array Flat associative array.
     */
    private function flattenItem(string $token, array $record): array
    {
        $item = [];

        $item['key'] = $record['key'];

        foreach ($record['fields'] as $key => $value) {
            if ($key === 'itemCircInfo') {
                $item['itemCircInfo'] = $this->getItemCircInfo($token, $record['fields']['itemCircInfo']['key']);
            } elseif ($key === 'holdRecordList') {
                for ($i = 0; $i < count($record['fields']['holdRecordList']); $i++) {
                    if (!empty($record['fields']['holdRecordList'][$i]['key'])) {
                        $item['holdRecordList'][$i] = $this->getHold($token, $record['fields']['holdRecordList'][$i]['key']);
                    }
                }
            } elseif ($key === 'call') {
                foreach ($this->flattenCall($token, $record['fields']['call']) as $subKey => $subValue) {
                    $item[$subKey] = $subValue;
                }
            } elseif ($key === 'price') {
                $item['price'] = $record['fields']['price']['currencyCode']
                    . ' '
                    . $record['fields']['price']['amount'];
            } elseif (!empty($record['fields'][$key]['key'])) {
                $item[$key] = $record['fields'][$key]['key'];
            } else {
                $item[$key] = $value;
            }
        }

        return $item;
    }

    /**
     * Flattens bib record into simple hash.
     *
     * @param string $token Session token.
     * @param array $record Complex record object.
     * @return array Flat associative array.
     */
    private function flattenBib(string $token, array $record): array
    {
        $bib = [];

        // Extract the data from the structure so that it can be returned in a flat hash
        foreach ($record as $key => $value) {
            if ($key == 'bib') {
                for ($i = 0; $i < count($record['bib']['fields']); $i++) {
                    for ($x = 0; $x < count($record['bib']['fields'][$i]['subfields']); $x++) {
                        if ($record['bib']['fields'][$i]['subfields'][$x]['code'] === '_') {
                            $bib[$record['bib']['fields'][$i]['tag']] = $record['bib']['fields'][$i]['subfields'][$x]['data'];
                        } else {
                            $bib[$record['bib']['fields'][$i]['tag']
                                . '_'
                                . $record['bib']['fields'][$i]['subfields'][$x]['code']]
                                = $record['bib']['fields'][$i]['subfields'][$x]['data'];
                        }
                    }
                }
            } elseif ($key == 'bibCircInfo') {
                $bib['bibCircInfo'] = $this->getBibCircInfo($token, $record[$key]['key']);
            } elseif ($key == 'callList') {
                $bib['callList'] = $this->flattenCallList($token, $record['callList']);
            } elseif ($key == 'holdRecordList') {
                for ($i = 0; $i < count($record['holdRecordList']); $i++) {
                    $bib['holdRecordList'][$i] = $this->getHold($token, $record['holdRecordList'][$i]['key']);
                }
            } elseif (!empty($record[$key]['key'])) {
                $bib[$key] = $record[$key]['key'];
            } else {
                $bib[$key] = $value;
            }
        }

        return $bib;
    }

    /**
     * Get catalog search indexes.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @return array Array of valid index names.
     * @throws Exception If validation fails.
     */
    public function getCatalogIndexes(?string $token = null): array
    {
        $searchIndexes = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        $describe = $this->sendGet("{$this->baseUrl}/catalog/bib/describe", $token, []);

        foreach ($describe['searchIndexList'] as $index) {
            array_push($searchIndexes, $index['name']);
        }

        return $searchIndexes;
    }

    /**
     * Get bib MARC data.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $bibKey Bibliographic record key.
     * @return array Flat associative array with MARC record.
     * @throws Exception If validation fails.
     */
    public function getBibMarc(?string $token = null, ?string $bibKey = null): array
    {
        $bib = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('bib_key', $bibKey, 'r:#^\d{1,8}$#');

        $response = $this->sendGet("{$this->baseUrl}/catalog/bib/key/{$bibKey}", $token, []);

        if (!empty($response['fields']['bib'])) {
            $bib['key'] = $response['key'];
            foreach ($response['fields']['bib'] as $marcKey => $marcValue) {
                if (!is_array($marcValue)) {
                    $bib[$marcKey] = $marcValue;
                } else {
                    foreach ($marcValue as $tag) {
                        if (!empty($tag['tag'])) {
                            foreach ($tag['subfields'] as $subfield) {
                                if ($subfield['code'] == '_') {
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
     * Validate bib field names using the API describe functions.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string $fieldList Comma-delimited list of fields to be validated.
     * @return int Always returns 1, if it doesn't throw an exception.
     * @throws Exception If validation fails.
     */
    private function validateBibFields(?string $token = null, string $fieldList = ''): int
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        // Convert the input fields to an array
        $inputFields = preg_split("/[,{}]/", $fieldList, -1, PREG_SPLIT_NO_EMPTY);

        // Get the fields to validate against
        $bibFields = [];
        $describe = $this->sendGet("{$this->baseUrl}/catalog/bib/describe", $token, []);
        foreach ($describe['fields'] as $field) {
            array_push($bibFields, $field['name']);
        }
        array_push($bibFields, '*');
        array_push($bibFields, 'key');

        /**
         * Check if there are unvalidated fields left after checking against
         * bib fields. If there are, check against call fields, next.
         */
        $diffFields = array_diff($inputFields, $bibFields);

        $callFields = [];
        if (!empty($diffFields)) {
            $describe = $this->sendGet("{$this->baseUrl}/catalog/call/describe", $token, []);
            foreach ($describe['fields'] as $field) {
                array_push($callFields, $field['name']);
            }
        }

        /**
         * Check again. if there are still unvalidated fields after checking against
         * the call fields, check against item fields.
         */
        $diffFields = array_diff($diffFields, $callFields);

        $itemFields = [];
        if (!empty($diffFields)) {
            $describe = $this->sendGet("{$this->baseUrl}/catalog/item/describe", $token, []);
            foreach ($describe['fields'] as $field) {
                array_push($itemFields, $field['name']);
            }
        }

        /**
         * Check one last time. If there are still unvalidated fields, they should be
         * bibliographic tag fields used for filtering results. Throw an error if we find
         * anything that doesn't look like a filter field.
         */
        $diffFields = array_diff($diffFields, $itemFields);

        if (!empty($diffFields)) {
            foreach ($diffFields as $field) {
                if (!preg_match("/^\d{3}(_[a-zA-Z0-9]{1})*$/", $field)) {
                    throw new Exception("Invalid field \"{$field}\" in includeFields");
                }
            }
        }

        return 1;
    }

    /**
     * Put item into transit.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $itemKey Item record key.
     * @param string|null $newLibrary Library code.
     * @param string|null $workingLibrary Working library code.
     * @return array Response from API server.
     * @throws Exception If validation fails.
     */
    public function transitItem(
        ?string $token = null,
        ?string $itemKey = null,
        ?string $newLibrary = null,
        ?string $workingLibrary = null
    ): array {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('item_key', $itemKey, 'r:#^\d{6,8}:\d{1,2}:\d{1,2}$#');
        $this->validate('library', $newLibrary, 'r:#^[A-Z]{3,9}$#');
        $this->validate('working_library', $workingLibrary, 'r:#^[A-Z]{3,9}$#');

        $data = [
            'resource' => '/circulation/transit',
            'fields' => [
                'destinationLibrary' => ['resource' => '/policy/library', 'key' => $newLibrary],
                'item' => ['resource' => '/catalog/item', 'key' => $itemKey],
                'transitReason' => 'EXCHANGE',
            ]
        ];
        $json = json_encode($data);

        // Add header and role required for this API endpoint
        $options = [];
        $options['header'] = "SD-Working-LibraryID: {$workingLibrary}";
        $options['role'] = 'STAFF';

        // Describe patron register function
        $response = $this->sendQuery("{$this->baseUrl}/circulation/transit", $token, $json, 'POST', $options);

        return $response;
    }

    /**
     * Receive an intransit item.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param int|null $itemId Item record barcode.
     * @return array Response from API server.
     * @throws Exception If validation fails.
     */
    public function untransitItem(?string $token = null, ?int $itemId = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('item_id', $itemId, 'i:30000000000000,39999999999999');

        $json = "{\"itemBarcode\":\"{$itemId}\"}";
        $response = $this->sendQuery("{$this->baseUrl}/circulation/untransit", $token, $json, 'POST');

        return $response;
    }

    /**
     * Change item library.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $itemKey Item record key.
     * @param string|null $library Library code.
     * @return array Response from API server.
     * @throws Exception If validation fails.
     */
    public function changeItemLibrary(?string $token = null, ?string $itemKey = null, ?string $library = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('itemKey', $itemKey, 'r:#^\d{5,8}:\d{1,2}:\d{1,2}$#');
        $this->validate('library', $library, 'r:#^[A-Z]{3,9}$#');

        $json = "{\"resource\":\"/catalog/item\",\"key\":\"{$itemKey}\",\"fields\":{\"library\":{\"resource\":\"/policy/library\",\"key\":\"{$library}\"}}}";
        $response = $this->sendQuery("{$this->baseUrl}/catalog/item/key/{$itemKey}", $token, $json, 'PUT');

        return $response;
    }

    /**
     * Retrieves bib information.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $bibKey Bibliographic record key.
     * @param string $fieldList Comma or comma and space delimited list of fields to be returned.
     * @return array Flat associative array containing bib information.
     * @throws Exception If validation fails.
     */
    public function getBib(?string $token = null, ?string $bibKey = null, string $fieldList = ''): array
    {
        $bib = [];
        $fields = preg_split("/[,{}]+/", $fieldList, -1, PREG_SPLIT_NO_EMPTY);

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('bibKey', $bibKey, 'r:#^\d{1,8}$#');

        // Validate the $fieldList
        if ($this->config['symphony']['validate_catalog_fields']) {
            $this->validateBibFields($token, $fieldList);
        } else {
            $this->validate('fieldList', $fieldList, 'r:#^[A-Z0-9a-z_{},*]{2,256}$#');
        }

        $response = $this->sendGet("{$this->baseUrl}/catalog/bib/key/{$bibKey}?includeFields=" . $fieldList, $token, []);

        if (!empty($response['fields'])) {
            // Flatten the structure to a simple hash
            $temp = $this->flattenBib($token, $response['fields']);

            // Filter out empty or not requested fields

            $bib['key'] = $response['key'];
            foreach ($fields as $field) {
                if (!empty($temp[$field])) {
                    $bib[$field] = $temp[$field];
                }
            }
        }

        return $bib;
    }

    /**
     * Retrieves item information.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $itemKey Item key.
     * @param string $fieldList Comma or comma and space delimited list of fields to be returned.
     * @return array Flat associative array containing item information.
     * @throws Exception If validation fails.
     */
    public function getItem(?string $token = null, ?string $itemKey = null, string $fieldList = '*'): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('itemKey', $itemKey, 'r:#^(\d{5,8})(:\d{1,2}){0,2}$#');

        // Validate the $fieldList
        if ($this->config['symphony']['validate_catalog_fields']) {
            $this->validateFields($token, 'item', $fieldList);
        } else {
            $this->validate('fieldList', $fieldList, 'r:#^[A-Za-z0-9_{},*]{2,256}$#');
        }

        $item = $this->sendGet("{$this->baseUrl}/catalog/item/key/{$itemKey}?includeFields={$fieldList}", $token, []);

        if (!empty($item['fields'])) {
            $item = $this->flattenItem($token, $item);
        }

        return $item;
    }

    /**
     * Get a call number.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $callKey Call number key.
     * @param string $fieldList Comma or comma and space delimited list of fields to be returned.
     * @return array Flat associative array containing the response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function getCallNumber(?string $token = null, ?string $callKey = null, string $fieldList = '*'): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('callKey', $callKey, 'r:#^\d{6,8}:\d{1,2}$#');

        // Validate the $fieldList
        if ($this->config['symphony']['validate_catalog_fields']) {
            $this->validateFields($token, 'call', $fieldList);
        } else {
            $this->validate('fieldList', $fieldList, 'r:#^[A-Z0-9a-z_{},*]{2,256}$#');
        }

        $call = $this->sendGet("{$this->baseUrl}/catalog/call/key/{$callKey}?includeFields={$fieldList}", $token);

        if (!empty($call['fields'])) {
            $call = $this->flattenCall($token, $call);
        }

        return $call;
    }

    /**
     * Describes the item record (used to determine valid indexes and fields).
     *
     * @param string|null $token The session token returned by ILSWS.
     * @return array Associative array of response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function describeItem(?string $token = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        return $this->sendGet("{$this->baseUrl}/catalog/item/describe", $token, []);
    }

    /**
     * Describes the bib record (used to determine valid indexes and fields).
     *
     * @param string|null $token The session token returned by ILSWS.
     * @return array Associative array of response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function describeBib(?string $token = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        return $this->sendGet("{$this->baseUrl}/catalog/bib/describe", $token, []);
    }

    /**
     * Removes accents, punctuation, and non-ascii characters to
     * create search string acceptable to ILSWS.
     *
     * @param string|null $terms The search terms.
     * @return string The prepared search terms.
     * @throws Exception If validation fails.
     */
    public function prepareSearch(?string $terms = null): string
    {
        // Trim leading and trailing whitespace
        $terms = trim((string) $terms);

        // Validate
        $this->validate('terms', $terms, 's:256');

        // Change utf8 letters with accents to ascii characters
        setlocale(LC_ALL, 'en_US.utf8');
        $terms = iconv('utf-8', 'ASCII//TRANSLIT', $terms);

        // Remove boolean operators
        $terms = preg_replace("/(\s+)(and|or|not)(\s+)/", ' ', $terms);

        // Replace certain characters with a space
        $terms = preg_replace("/[\\\:;,\/\|]/", ' ', $terms);

        // Remove most punctuation and other unwanted characters
        $terms = preg_replace("/[!?&+=><%#\'\"\{\}\(\)\[\]]/", '', $terms);

        // Remove internal non-printing characters
        $terms = preg_replace('/[^\x20-\x7E]/', '', $terms);

        // Replace multiple spaces with a single space
        $terms = preg_replace('/\s+/', ' ', $terms);

        return $terms;
    }

    /**
     * Search the catalog for bib records.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $index The index to search.
     * @param string|null $value The value to search for.
     * @param array|null $params Associative array of optional parameters.
     * @return array Associative array containing search results.
     * @throws Exception If validation fails.
     */
    public function searchBib(?string $token = null, ?string $index = null, ?string $value = null, ?array $params = null): array
    {
        $fields = preg_split("/[,{}]+/", (string) $params['includeFields'], -1, PREG_SPLIT_NO_EMPTY);

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('value', $value, 's:40');

        if ($this->config['symphony']['validate_catalog_fields']) {
            // Validate fields and get valid search indexes
            $this->validateBibFields($token, (string) $params['includeFields']);

            // Validate the search index
            $indexList = $this->getCatalogIndexes($token);
            $this->validate('index', $index, 'v:' . implode('|', $indexList));
        } else {
            $this->validate('includeFields', $params['includeFields'], 'r:#^[A-Z0-9a-z_{},]{2,256}$#');
        }

        /**
         * Valid incoming params are:
         * ct = number of results to return,
         * rw = row to start on (so you can page through results),
         * j = boolean AND or OR to use with multiple search terms, and
         * includeFields = fields to return in result.
         *
         * Any incoming q will be replaced by the values $index and $value.
         */

        $params = [
            'q' => "{$index}:{$value}",
            'ct' => $params['ct'] ?? '1000',
            'rw' => $params['rw'] ?? '1',
            'j' => $params['j'] ?? 'AND',
            'includeFields' => $params['includeFields'],
        ];

        $response = $this->sendGet("{$this->baseUrl}/catalog/bib/search", $token, $params);

        $records = [];
        if (!empty($response['totalResults']) && $response['totalResults'] > 0) {
            for ($i = 0; $i < count($response['result']); $i++) {
                if (!is_null($response['result'][$i])) {
                    $bib = $this->flattenBib($token, $response['result'][$i]['fields']);
                    $bib['key'] = $response['result'][$i]['key'];

                    $filteredBib = [];
                    foreach ($fields as $field) {
                        if (!empty($bib[$field])) {
                            $filteredBib[$field] = $bib[$field];
                        }
                    }
                    array_push($records, $filteredBib);
                }
            }
        }

        return $records;
    }

    /**
     * Pulls list of items checked out to a patron.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param int|null $patronKey Patron key of patron whose records we need to see.
     * @param string|null $includeFields Optional.
     * @return array Associative array of item keys and libraries.
     * @throws Exception If validation fails.
     */
    public function getPatronCheckouts(?string $token = null, ?int $patronKey = null, ?string $includeFields = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');

        if (!$includeFields) {
            $includeFields = 'item,library';
        }

        $response = $this->sendGet("{$this->baseUrl}/user/patron/key/{$patronKey}?includeFields=circRecordList{*}", $token, []);
        $fields = preg_split('/,/', $includeFields);

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
     * Get bibliographic circulation statistics.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $bibKey Bibliographic record key.
     * @return array Flat associative array with circulation numbers.
     * @throws Exception If validation fails.
     */
    public function getBibCircInfo(?string $token = null, ?string $bibKey = null): array
    {
        $stats = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('bibKey', $bibKey, 'r:#^\d{1,8}$#');

        $response = $this->sendGet("{$this->baseUrl}/circulation/bibCircInfo/key/{$bibKey}", $token, []);

        if (!empty($response['fields'])) {
            foreach ($response['fields'] as $field => $value) {
                $stats[$field] = $value;
            }
        }

        return $stats;
    }

    /**
     * Get item circulation statistics.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $itemKey Item record key.
     * @return array Flat associative array with circulation numbers.
     * @throws Exception If validation fails.
     */
    public function getItemCircInfo(?string $token = null, ?string $itemKey = null): array
    {
        $stats = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('itemKey', $itemKey, 'r:#^\d{5,8}:\d{1,2}:\d{1,2}$#');

        $response = $this->sendGet("{$this->baseUrl}/circulation/itemCircInfo/key/{$itemKey}", $token, []);

        if (!empty($response['fields'])) {
            foreach ($response['fields'] as $field => $value) {
                if (!empty($response['fields'][$field]['key'])) {
                    $stats[$field] = $response['fields'][$field]['key'];
                } else {
                    $stats[$field] = $value;
                }
            }
        }

        return $stats;
    }

    /**
     * Flatten hold record.
     *
     * @param array $record Hold record object.
     * @return array Flat associative array of hold fields.
     */
    private function flattenHold(array $record): array
    {
        $hold = [];

        if (!empty($record['fields'])) {
            $hold['key'] = $record['key'];
            foreach ($record['fields'] as $field => $value) {
                if (!empty($record['fields'][$field]['key'])) {
                    $hold[$field] = $record['fields'][$field]['key'];
                } else {
                    $hold[$field] = $value;
                }
            }
        }

        return $hold;
    }

    /**
     * Get a hold record.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $holdKey Hold record key.
     * @return array Associative array containing the response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function getHold(?string $token = null, ?string $holdKey = null): array
    {
        $hold = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('holdKey', $holdKey, 'r:#^\d{6,8}$#');

        $hold = $this->sendGet("{$this->baseUrl}/circulation/holdRecord/key/{$holdKey}", $token, []);

        if (!empty($hold['fields'])) {
            $hold = $this->flattenHold($hold);
        }

        return $hold;
    }

    /**
     * Removes URLs from the trailing end of a string.
     *
     * @param string $string String to be modifed.
     * @return string Modified string.
     */
    private function removeUrl(string $string): string
    {
        $string = trim($string);

        $words = preg_split("/[\s]+/", $string);
        $new = [];

        foreach ($words as $word) {
            if (!preg_match("#^(http)(s{0,1})(://)(.*)$#", $word)) {
                array_push($new, $word);
            }
        }

        return implode(' ', $new);
    }

    /**
     * Pulls a hold list for a given library.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $libraryKey Library key (three character).
     * @return array Associative array containing the response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function getLibraryPagingList(?string $token = null, ?string $libraryKey = null): array
    {
        $list = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('libraryKey', $libraryKey, 'r:#^[A-Z0-9]{3,9}$#');

        $includeFields = 'pullList{holdRecord{holdType,status,pickupLibrary},item{barcode,itemType,currentLocation{description},bib{author,title},call{callNumber,sortCallNumber}}}';
        $response = $this->sendGet("{$this->baseUrl}/circulation/holdItemPullList/key/{$libraryKey}", $token, ['includeFields' => $includeFields]);

        if (!empty($response['fields']['pullList'])) {
            foreach ($response['fields']['pullList'] as $hold) {

                $record = [];

                $record['holdType'] = $hold['fields']['holdRecord']['fields']['holdType'];
                $record['status'] = $hold['fields']['holdRecord']['fields']['status'];
                $record['pickupLibrary'] = $hold['fields']['holdRecord']['fields']['pickupLibrary']['key'];
                $record['item'] = $hold['fields']['item']['key'];
                $record['bib'] = $hold['fields']['item']['fields']['bib']['key'];
                $record['author'] = $hold['fields']['item']['fields']['bib']['fields']['author'];
                $record['title'] = $hold['fields']['item']['fields']['bib']['fields']['title'];
                $record['callNumber'] = $hold['fields']['item']['fields']['call']['fields']['callNumber'];
                $record['sortCallNumber'] = $hold['fields']['item']['fields']['call']['fields']['sortCallNumber'];
                $record['barcode'] = $hold['fields']['item']['fields']['barcode'];
                $record['currentLocation'] = $hold['fields']['item']['fields']['currentLocation']['key'];
                $record['locationDescription'] = $hold['fields']['item']['fields']['currentLocation']['fields']['description'];
                $record['itemType'] = $hold['fields']['item']['fields']['itemType']['key'];

                // Remove URL from author field
                $record['author'] = !empty($record['author']) ? $this->removeUrl($record['author']) : '';

                array_push($list, $record);
            }
        }

        return $list;
    }

    /**
     * Deletes a patron.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The patron key of the user to delete.
     * @return int Returns 1 if successful, 0 if not.
     * @throws Exception If validation fails.
     */
    public function deletePatron(?string $token = null, ?string $patronKey = null): int
    {
        $retval = 0;
        $json = '';

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');

        $this->sendQuery("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, $json, 'DELETE');
        if ($this->code == 204) {
            $retval = 1;
        }

        return $retval;
    }

    /**
     * Resets a user password.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $json JSON containing either currentPassword and newPassword or
     * resetPasswordToken and newPassword.
     * @param array $options Associative array of options (role, clientId).
     * @return array Associative array containing response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function changePatronPassword(?string $token = null, ?string $json = null, array $options = []): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('json', $json, 'j');

        return $this->sendQuery("{$this->baseUrl}/user/patron/changeMyPassword", $token, $json, 'POST', $options);
    }

    /**
     * Resets a user password via call-back to a web application and email.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronId The patron barcode.
     * @param string|null $url The call-back URL for the web application.
     * @param string|null $email Optional email address to use and validate.
     * @return array Associative array containing response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function resetPatronPassword(?string $token = null, ?string $patronId = null, ?string $url = null, ?string $email = null): array
    {
        $data = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronId', $patronId, 'r:#[A-Z0-9]{1,20}$#');
        $this->validate('url', $url, 'u');

        $data = [
            'barcode' => $patronId,
            'resetPasswordUrl' => $url,
        ];

        if ($email) {
            $this->validate('email', $email, 'e');
            $data['email'] = $email;
        }

        $json = json_encode($data);

        return $this->sendQuery("{$this->baseUrl}/user/patron/resetMyPassword", $token, $json, 'POST');
    }

    /**
     * Get patron indexes.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @return array Array of valid patron indexes.
     * @throws Exception If validation fails.
     */
    public function getPatronIndexes(?string $token = null): array
    {
        $indexes = [];

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        $describe = $this->sendGet("{$this->baseUrl}/user/patron/describe", $token, []);

        foreach ($describe['searchIndexList'] as $index) {
            array_push($indexes, $index['name']);
        }

        return $indexes;
    }

    /**
     * Function to check for duplicate accounts by searching in two indexes
     * and comparing the resulting arrays.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $index1 First search index.
     * @param string|null $search1 First search string.
     * @param string|null $index2 Second search index.
     * @param string|null $search2 Second search string.
     * @return int Boolean 1 or 0 depending on whether a duplicate is found.
     * @throws Exception If validation fails.
     */
    public function checkDuplicate(
        ?string $token = null,
        ?string $index1 = null,
        ?string $search1 = null,
        ?string $index2 = null,
        ?string $search2 = null
    ): int {
        $duplicate = 0;
        $matches = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('search1', $search1, 's:40');
        $this->validate('search2', $search2, 's:40');

        if ($this->config['symphony']['validate_patron_indexes']) {
            $patronIndexes = $this->getPatronIndexes($token);
            $this->validate('index1', $index1, 'v:' . implode('|', $patronIndexes));
            $this->validate('index2', $index2, 'v:' . implode('|', $patronIndexes));
        } else {
            $this->validate('index1', $index1, 'r:#^[A-Z0-9a-z_]{2,9}$#');
            $this->validate('index2', $index2, 'r:#^[A-Z0-9a-z_]{2,9}$#');
        }

        if (preg_match('/street/i', (string) $index1)) {
            $search1 = preg_replace('/[^A-Za-z0-9\- ]/', '', (string) $search1);
        }
        if (preg_match('/street/i', (string) $index2)) {
            $search2 = preg_replace('/[^A-Za-z0-9\- ]/', '', (string) $search2);
        }
        if (preg_match('/date/i', (string) $index1)) {
            $search1 = preg_replace('/-/', '', $this->createFieldDate('search', (string) $search1));
        }
        if (preg_match('/date/i', (string) $index2)) {
            $search2 = preg_replace('/-/', '', $this->createFieldDate('search2', (string) $search2));
        }

        if ($this->config['debug']['query']) {
            error_log("DEBUG_QUERY {$index1}:{$search1}", 0);
            error_log("DEBUG_QUERY {$index2}:{$search2}", 0);
        }

        $result1 = $this->searchPatron($token, (string) $index1, (string) $search1, ['rw' => 1, 'ct' => 1000, 'includeFields' => 'key']);

        if (isset($result1['totalResults']) && $result1['totalResults'] >= 1) {
            $startRow = 1;
            $resultRows = 0;

            $result2 = $this->searchPatron($token, (string) $index2, (string) $search2, ['rw' => 1, 'ct' => 1000, 'includeFields' => 'key']);

            if (isset($result2['totalResults']) && $result2['totalResults'] > 1) {
                foreach (array_filter($result1['result']) as $record1) {
                    foreach (array_filter($result2['result']) as $record2) {
                        if ($record1['key'] === $record2['key']) {
                            $matches++;
                            if ($matches > 1) {
                                break;
                            }
                        }
                    }
                    if ($matches > 1) {
                        break;
                    }
                }
                if ($matches > 1) {
                    $duplicate = 1;
                }
            } else {
                $resultRows = $result2['totalResults'];
                $startRow += 1000;

                while ($resultRows >= $startRow) {
                    $result2 = $this->searchPatron($token, (string) $index2, (string) $search2, ['rw' => $startRow, 'ct' => 1000, 'includeFields' => 'key']);

                    foreach (array_filter($result1['result']) as $record1) {
                        foreach (array_filter($result2['result']) as $record2) {
                            if ($record1['key'] === $record2['key']) {
                                $matches++;
                                if ($matches > 1) {
                                    break;
                                }
                            }
                        }
                        if ($matches > 1) {
                            break;
                        }
                    }
                    if ($matches > 1) {
                        break;
                    }
                    $startRow += 1000;
                }
            }
        }

        if ($matches > 1) {
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
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $index The Symphony index to search.
     * @param string|null $search The value to search for.
     * @param string|null $password The patron password.
     * @return string The patron ID (barcode).
     * @throws Exception If validation fails.
     */
    public function searchAuthenticate(?string $token = null, ?string $index = null, ?string $search = null, ?string $password = null): string
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('search', $search, 's:40');
        $this->validate('password', $password, 's:40');

        if ($this->config['symphony']['validate_patron_indexes']) {
            $indexes = $this->getPatronIndexes($token);
            $this->validate('index', $index, 'v:' . implode('|', $indexes));
        } else {
            $this->validate('index', $index, 'r:#^[A-Z0-9a-z_]{2,9}$#');
        }

        $params = [
            'rw' => '1',
            'ct' => $this->config['ilsws']['max_search_count'],
            'j' => 'AND',
            'includeFields' => 'barcode',
        ];

        $response = $this->searchPatron($token, (string) $index, (string) $search, $params);

        if ($this->error) {
            return '0';
        }

        /**
         * Symphony Web Services' with return nulls for records that have been deleted
         * but still count them in the results. So, you can't trust the totalResults count
         * match the number of actual records returned, and you have to loop through all
         * possible result objects to see if there is data.
         */
        $patronKey = '0';
        $count = 0;
        if ($response['totalResults'] > 0 && $response['totalResults'] <= $this->config['ilsws']['max_search_count']) {
            for ($i = 0; $i <= $response['totalResults'] - 1; $i++) {
                if (isset($response['result'][$i]['fields']['barcode'])) {
                    $patronId = $response['result'][$i]['fields']['barcode'];

                    // Get the patron key from ILSWS via the patron ID and password
                    $patronKey = $this->authenticatePatronId($token, (string) $patronId, (string) $password);

                    if ($patronKey) {
                        $count++;
                    }
                }
                if ($count > 1) {
                    $patronKey = '0';
                    break;
                }
            }
        }

        return $patronKey;
    }

    /**
     * Authenticate via patronId (barcode) and password.
     *
     * On a successful login, this function should return the user's patron key. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a key of 0 is returned.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronId The patron ID (barcode).
     * @param string|null $password The patron password.
     * @return string The patron key (internal ID).
     * @throws Exception If validation fails.
     */
    public function authenticatePatronId(?string $token = null, ?string $patronId = null, ?string $password = null): string
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronId', $patronId, 'r:#^[A-Z0-9]{1,20}$#');
        $this->validate('password', $password, 's:20');

        $patronKey = '0';

        $action = '/user/patron/authenticate';
        $json = json_encode(['barcode' => $patronId, 'password' => $password]);

        $response = $this->sendQuery("{$this->baseUrl}/{$action}", $token, $json, 'POST');

        if (isset($response['patronKey'])) {
            $patronKey = $response['patronKey'];
        }

        return (string) $patronKey;
    }

    /**
     * Attempt to retrieve patron attributes.
     *
     * This function returns a patron's attributes.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The user's internal ID number.
     * @return array Associative array with the user's attributes.
     * @throws Exception If validation fails.
     */
    public function getPatronAttributes(?string $token = null, ?string $patronKey = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');

        $attributes = [];

        $includeFields = [
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

        $includeStr = implode(',', $includeFields);

        $response = $this->sendGet("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, ['includeFields' => $includeStr]);

        // Extract patron attributes from the ILSWS response and assign to $attributes.
        if (isset($response['key'])) {
            foreach ($includeFields as &$field) {
                if ($field == 'address1') {
                    if (isset($response['fields']['address1'])) {
                        foreach ($response['fields']['address1'] as &$i) {
                            if ($i['fields']['code']['key'] == 'EMAIL') {
                                $attributes['email'] = $i['fields']['data'];
                            } elseif ($i['fields']['code']['key'] == 'CITY/STATE') {
                                $parts = preg_split("/,\s*/", $i['fields']['data']);
                                $attributes['city'] = $parts[0];
                                $attributes['state'] = $parts[1];
                            } elseif ($i['fields']['code']['key'] == 'ZIP') {
                                $attributes['zip'] = $i['fields']['data'];
                            } elseif ($i['fields']['code']['key'] == 'PHONE') {
                                $attributes['telephone'] = $i['fields']['data'];
                            }
                        }
                    }
                } elseif (isset($response['fields'][$field]['key'])) {
                    $attributes[$field] = $response['fields'][$field]['key'];
                } elseif (isset($response['fields'][$field])) {
                    $attributes[$field] = $response['fields'][$field];
                } else {
                    $attributes[$field] = '';
                }
            }
        }
        // Generate a displayName
        if (isset($response['fields']['lastName']) && isset($response['fields']['firstName'])) {
            $attributes['displayName'] = $response['fields']['firstName'] . ' ' . $response['fields']['lastName'];
        }
        // Generate a commonName
        if (isset($response['fields']['lastName']) && isset($response['fields']['firstName'])) {
            if (isset($response['fields']['middleName'])) {
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
     * Authenticate a patron via ID (barcode) and password.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronId The patron's ID (barcode).
     * @param string|null $password The patron password.
     * @return array Associative array contain the response from ILSWS.
     * @throws Exception If validation fails.
     */
    public function authenticatePatron(?string $token = null, ?string $patronId = null, ?string $password = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronId', $patronId, 'r:#^[A-Z0-9]{1,20}$#');

        $json = "{ \"barcode\": \"{$patronId}\", \"password\": \"{$password}\" }";

        return $this->sendQuery("{$this->baseUrl}/user/patron/authenticate", $token, $json, 'POST');
    }

    /**
     * Describe the patron resource.
     *
     * @param string $token The session token returned by ILSWS.
     * @return array Associative array containing information about the patron record
     * structure used by SirsiDynix Symphony.
     * @throws Exception If validation fails.
     */
    public function describePatron(string $token): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');

        return $this->sendGet("{$this->baseUrl}/user/patron/describe", $token);
    }

    /**
     * Search for patron by any valid single field.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $index The index to search.
     * @param string|null $value The value to search for.
     * @param array|null $params Associative array of optional parameters.
     * @return array Associative array containing search results.
     * @throws Exception If validation fails.
     */
    public function searchPatron(?string $token = null, ?string $index = null, ?string $value = null, ?array $params = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('value', $value, 's:40');

        if ($this->config['symphony']['validate_patron_indexes']) {
            $indexes = $this->getPatronIndexes($token);
            $this->validate('index', $index, 'v:' . implode('|', $indexes));
        } else {
            $this->validate('index', $index, 'r:#^[A-Z0-9a-z_]{2,9}$#');
        }

        /**
         * Valid incoming params are:
         * ct = number of results to return,
         * rw = row to start on (so you can page through results),
         * j = boolean AND or OR to use with multiple search terms, and
         * includeFields = fields to return in result.
         *
         * Any incoming q will be replaced by the values $index and $value.
         */

        $params = [
            'q' => "{$index}:{$value}",
            'ct' => $params['ct'] ?? '1000',
            'rw' => $params['rw'] ?? '1',
            'j' => $params['j'] ?? 'AND',
            'includeFields' => $params['includeFields'] ?? $this->config['symphony']['default_patron_include_fields'],
        ];

        return $this->sendGet("{$this->baseUrl}/user/patron/search", $token, $params);
    }

    /**
     * Search by alternate ID number.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $altId The user's alternate ID number.
     * @param string|null $count How many records to return per page.
     * @return array Associative array containing search results.
     * @throws Exception If validation fails.
     */
    public function searchPatronAltId(?string $token = null, ?string $altId = null, ?string $count = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('altId', $altId, 'i:1,99999999');
        $this->validate('count', $count, 'i:1,1000');

        return $this->searchPatron($token, 'ALT_ID', (string) $altId, ['ct' => $count]);
    }

    /**
     * Search for patron by ID (barcode).
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronId The user's alternate ID number.
     * @param string|null $count How many records to return per page.
     * @return array Associative array containing search results.
     * @throws Exception If validation fails.
     */
    public function searchPatronId(?string $token = null, ?string $patronId = null, ?string $count = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronId', $patronId, 'r:#^[A-Z0-9]{1,20}$#');
        $this->validate('count', $count, 'i:1,1000');

        return $this->searchPatron($token, 'ID', (string) $patronId, ['ct' => $count]);
    }

    /**
     * Uses a birth day to determine what profile the patron should receive, assuming
     * a profile has not been set in the incoming data.
     *
     * @param array $patron Associative array of patron data elements.
     * @return string The profile.
     * @throws Exception If validation fails.
     */
    private function getProfile(array $patron): string
    {
        // Look in all the places we might find an incoming profile
        $profile = '';
        if (!empty($patron['profile'])) {
            $profile = $patron['profile'];
        } elseif (!empty($this->config['symphony']['new_fields']['alias'])
            && !empty($patron[$this->config['symphony']['new_fields']['alias']])
        ) {
            $profile = $patron[$this->config['symphony']['new_fields']['alias']];
        } elseif (!empty($this->config['symphony']['overlay_fields']['alias'])
            && !empty($patron[$this->config['symphony']['overlay_fields']['alias']])
        ) {
            $profile = $patron[$this->config['symphony']['overlay_fields']['alias']];
        }

        // If we found an incoming profile, it takes precedence, so return it.
        if ($profile) {
            return $profile;
        }

        // Check everywhere we might find a birth date
        $dob = '';
        if (!empty($patron['birthDate'])) {
            $dob = $this->createFieldDate('birthDate', $patron['birthDate']);
        } elseif (!empty($this->config['symphony']['new_fields']['birthDate']['alias'])
            && !empty($patron[$this->config['symphony']['new_fields']['birthDate']['alias']])
        ) {
            $dob = $this->createFieldDate('birthDate', $patron[$this->config['symphony']['new_fields']['birthDate']['alias']]);
        } elseif (!empty($this->config['symphony']['overlay_fields']['birthDate']['alias'])
            && !empty($patron[$this->config['symphony']['overlay_fields']['birthDate']['alias']])
        ) {
            $dob = $this->createFieldDate('birthDate', $patron[$this->config['symphony']['overlay_fields']['birthDate']['alias']]);
        }

        // If we got a birth date, calculate the age
        $age = 0;
        if ($dob) {
            $today = date('Y-m-d');
            $d1 = new DateTime($today);
            $d2 = new DateTime($dob);
            $diff = $d2->diff($d1);
            $age = $diff->y;
        }

        // Check if the age fits into a range
        if ($age && !empty($this->config['symphony']['age_ranges'])) {
            $ranges = $this->config['symphony']['age_ranges'];
            foreach ($ranges as $range => $value) {
                list($min, $max) = preg_split('/-/', $range);
                if ($age >= $min && $age <= $max) {
                    $profile = $ranges[$range];
                }
            }
        }

        return $profile;
    }

    /**
     * Check for field aliases.
     *
     * @param array $patron Associative array of patron data elements.
     * @param array $fields Associative array field data from YAML configuration.
     * @return array Associative array of patron data elements.
     */
    private function checkAliases(array $patron, array $fields): array
    {
        foreach ($fields as $field => $value) {
            // Check if the data is coming in with a different field name (alias)
            if (!empty($fields[$field]['alias']) && isset($patron[$fields[$field]['alias']])) {
                $patron[$field] = $patron[$fields[$field]['alias']];
            }
        }

        return $patron;
    }

    /**
     * Check for defaults and required fields and validate field values.
     *
     * @param array $patron Associative array of patron data elements.
     * @param array $fields Associative array field data from YAML configuration.
     * @return array Associative array of patron data elements.
     * @throws Exception If a required field is missing or validation fails.
     */
    private function checkFields(array $patron, array $fields): array
    {
        // Loop through each field
        foreach ($fields as $field => $value) {
            // Assign default values to empty fields, where appropriate
            if (empty($patron[$field]) && !empty($fields[$field]['default'])) {
                $patron[$field] = $fields[$field]['default'];
            }

            // Check for missing required fields
            if (empty($patron[$field]) && !empty($fields[$field]['required']) && $fields[$field]['required'] === 'true') {
                throw new Exception("The {$field} field is required");
            }

            // Validate
            if (!empty($patron[$field]) && !empty($fields[$field]['validation'])) {
                $this->validate($field, $patron[$field], $fields[$field]['validation']);
            }
        }

        return $patron;
    }

    /**
     * Create new field structures for JSON.
     *
     * @param array $patron Associative array of patron data elements.
     * @param array $fields Associative array field data from YAML configuration.
     * @param int $addrNum Address number 1, 2, or 3.
     * @return array Patron data structure for conversion to JSON.
     */
    private function createFields(array $patron, array $fields, int $addrNum): array
    {
        $new = [];
        $new['fields']["address{$addrNum}"] = [];

        // Loop through each field
        foreach ($fields as $field => $value) {
            if (!empty($patron[$field])) {
                if (!empty($fields[$field]['type']) && $fields[$field]['type'] === 'address') {
                    array_push($new['fields']["address{$addrNum}"], $this->createField($field, $patron[$field], 'list', $addrNum, $fields[$field]));
                } elseif ($field != 'phoneList' && $field != 'key') {
                    $new['fields'][$field] = $this->createField($field, $patron[$field], $this->fieldDesc[$field]['type'], $addrNum);
                }
            }
        }

        return $new;
    }

    /**
     * Create patron data structure for overlays (overlay_fields).
     *
     * @param array $patron Associative array of patron data elements.
     * @param string|null $token The sessions key returned by ILSWS.
     * @param string|null $patronKey Optional patron key to include if updating existing record.
     * @param int|null $addrNum Address number to update (1, 2, or 3).
     * @return string Complete Symphony patron record JSON.
     * @throws Exception If validation fails.
     */
    private function createUpdateJson(?array $patron, ?string $token = null, ?string $patronKey = null, ?int $addrNum = null): string
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
        $this->validate('addrNum', $addrNum, 'i:1,3');

        // Go get field descriptions if they aren't already available
        if (empty($this->fieldDesc)) {
            $this->getFieldDesc($token, 'patron');
        }

        // Extract the field definitions from the configuration
        $fields = $this->config['symphony']['overlay_fields'];

        // Create the field structure
        $new = $this->createFields((array) $patron, $fields, (int) $addrNum);
        $new['resource'] = '/user/patron';
        $new['key'] = $patronKey;

        // Return a JSON string suitable for use in patron_create
        return json_encode($new, JSON_PRETTY_PRINT);
    }

    /**
     * Validates and formats fields based on their type.
     *
     * @param string $field The field to be processed.
     * @param mixed $value The value to checked.
     * @param string $type The type of field to be processed.
     * @param int $addrNum Address number 1, 2, or 3.
     * @param array|null $fieldData Associative array field data from YAML configuration.
     * @return mixed The output of the appropriate function.
     * @throws Exception If the field type is unknown.
     */
    private function createField(string $field, $value, string $type, int $addrNum = 1, ?array $fieldData = null) 
    {
        switch ($type) {
            case 'boolean':
                return $this->createFieldBoolean($field, $value);
            case 'date':
                return $this->createFieldDate($field, $value);
            case 'resource':
                return $this->createFieldResource($field, $value);
            case 'set':
                return $this->createFieldSet($field, $value);
            case 'string':
                return $this->createFieldString($field, $value);
            case 'list':
                return $this->createFieldAddress($field, $value, (array) $fieldData, $addrNum);
            case 'numeric':
                return $this->createFieldNumeric($field, $value);
            default:
                throw new Exception("Unknown field type: {$type}");
        }
    }

    /**
     * Process generic numeric (integer) fields
     * 
     * @param string $name The name of the field.
     * @param string $value The incoming value to be processed.
     * @return string The validated field value.
     * @throws Exception If the value is not an integer.
     */
    private function createFieldNumeric(string $name, int $value): string
    {
        if (is_int($value)) {
            return $value;
        }

        // If we got here, we weren't passed an integer
        throw new Exception("Invalid integer \"{$value}\" in {$name}");
    }

    /**
     * Process generic set fields.
     *
     * @param string $name The name of the field.
     * @param string $value The incoming value to be processed.
     * @return string The validated field value.
     * @throws Exception If the value is not an allowed set member.
     */
    private function createFieldSet(string $name, string $value): string
    {
        foreach ($this->fieldDesc[$name]['setMembers'] as $allowed) {
            if ($value === $allowed) {
                return $value;
            }
        }

        // If we got here, we didn't match any of the acceptable values
        throw new Exception("Invalid set member \"{$value}\" in {$name}");
    }

    /**
     * Process generic boolean fields.
     *
     * @param string $name The name of the field.
     * @param mixed $value The incoming value to be processed.
     * @return string "true" or "false".
     */
    private function createFieldBoolean(string $name, $value): string
    {
        if (is_bool($value)) {
            return (boolval($value) ? 'true' : 'false');
        } elseif (preg_match('/^true$/i', (string) $value)) {
            return 'true';
        } elseif (preg_match('/^false$/i', (string) $value)) {
            return 'false';
        }
        return 'false'; // Default for invalid input
    }

    /**
     * Process date for generic date field. Converts incoming strings
     * in any supported format (see $supported_formats) into Symphony's
     * preferred YYYY-MM-DD format.
     *
     * @param string $name The name of the field.
     * @param string $value The incoming value.
     * @return string The outgoing validated date string.
     * @throws Exception If the date format is invalid.
     */
    private function createFieldDate(string $name, string $value): string
    {
        $date = '';

        $supportedFormats = [
            'YYYYMMDD',
            'YYYY-MM-DD',
            'YYYY/MM/DD',
            'MM-DD-YYYY',
            'MM/DD/YYYY',
        ];

        foreach ($supportedFormats as $format) {
            $date = $this->dh->validateDate($value, $format);
            if ($date) {
                break;
            }
        }

        if (!$date) {
            throw new Exception("Invalid date format: \"{$value}\" in {$name} field");
        }

        return $date;
    }

    /**
     * Create structure for a generic resource field.
     *
     * @param string $name The name of the field.
     * @param string $key The key for the resource.
     * @param string $data Optional additional data for the resource.
     * @return array The outgoing associative array object.
     */
    private function createFieldResource(string $name, string $key, string $data = ''): array
    {
        $object['resource'] = $this->fieldDesc[$name]['uri'];
        $object['key'] = $key;

        // Not all resource fields have data
        if ($data) {
            $object['data'] = $data;
        }

        return $object;
    }

    /**
     * Create structure for a generic string field.
     *
     * @param string $name The name of the field.
     * @param string $value The incoming value.
     * @return string The outgoing value.
     * @throws Exception If the field length is invalid.
     */
    private function createFieldString(string $name, string $value): string
    {
        $length = strlen($value);
        if ($length < (int) $this->fieldDesc[$name]['min'] || $length > (int) $this->fieldDesc[$name]['max']) {
            throw new Exception("Invalid field length {$length} in {$name} field");
        }

        return $value;
    }

    /**
     * Create phone structure for use in patron_update, when a phoneList is supplied for use
     * with SMS messaging. Note: this function only supports a single number for SMS, although
     * individual types of messages may be turned on or off.
     *
     * @param string|null $patronKey The patron key.
     * @param array|null $params SMS message types with which to use this number.
     * @return array Associative array containing result.
     * @throws Exception If validation fails.
     */
    private function createFieldPhone(?string $patronKey = null, ?array $params = null): array
    {
        // Remove non-digit characters from the number
        $telephone = preg_replace('/\D/', '', (string) $params['number']);

        // Validate everything!
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
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
            'bills' => $params['bills'] ?? true,
            'general' => $params['general'] ?? true,
            'holds' => $params['holds'] ?? true,
            'manual' => $params['manual'] ?? true,
            'overdues' => $params['overdues'] ?? true,
        ];

        // Create the phoneList structure required by Symphony
        $structure = [
            'resource' => '/user/patron/phone',
            'fields' => [
                'patron' => [
                    'resource' => '/user/patron',
                    'key' => $patronKey,
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
     * Create address structure for use in patron update.
     *
     * @param string $field Name of address field.
     * @param mixed $fieldValue The value for the address field.
     * @param array $fields Associative array field data from YAML configuration.
     * @param int $addrNum Address number for the field.
     * @return array Address object for insertion into a patron record.
     * @throws Exception If a required address subfield is missing.
     */
    private function createFieldAddress(string $field, $fieldValue, array $fields, int $addrNum): array
    {
        $patron = []; // Initialize patron for this scope

        foreach ($fields as $subfield => $value) {
            // Check if the data is coming in with a different field name (alias)
            if (empty($patron[$subfield]) && !empty($fields[$field][$subfield]['alias'])) {
                $patron[$subfield] = $patron[$fields[$field][$subfield]['alias']];
            }

            // Assign default values where appropriate
            if (empty($patron[$subfield]) && !empty($fields[$field][$subfield]['default'])) {
                $patron[$subfield] = $fields[$field][$subfield]['default'];
            }

            // Check for missing required fields
            if (empty($patron[$subfield]) && !empty($fields[$subfield]['required']) && boolval($fields[$subfield]['required'])) {
                throw new Exception("The {$field} {$subfield} field is required");
            }

            // Create address structure
            $address = [];
            $address['resource'] = "/user/patron/address{$addrNum}";
            $address['fields']['code']['resource'] = "/policy/patronAddress{$addrNum}";
            $address['fields']['code']['key'] = $field;
            $address['fields']['data'] = $fieldValue;

            // Add this subfield to the address one array
            return $address;
        }
        return []; // Should not be reached if fields is not empty
    }

    /**
     * Calculate expiration date based on configuration.
     *
     * @param int|null $days Number of days to add to today's date to calculate expiration.
     * @return string|null Today's date plus the online account expiration (days), or null if $days is null.
     */
    public function getExpiration(?int $days = null): ?string
    {
        $expiration = null;

        if ($days) {
            $today = date('Y-m-d');
            $expiration = date('Y-m-d', strtotime($today . " + {$days} day"));
        }

        return $expiration;
    }

    /**
     * Create patron data structure required by the patron_register
     * function to create the JSON patron data structure required by the API.
     *
     * @param array $patron Associative array of patron data elements.
     * @param string|null $token The session key returned by ILSWS.
     * @param int $addrNum Optional Address number to update (1, 2, or 3, defaults to 1).
     * @return string Symphony patron record JSON.
     * @throws Exception If validation fails or patron profile/barcode cannot be determined.
     */
    private function createRegisterJson(array $patron, ?string $token = null, int $addrNum = 1): string
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('addrNum', $addrNum, 'r:#^[123]{1}$#');

        // Get patron profile based on age of patron
        if (empty($patron['profile'])) {
            $patron['profile'] = $this->getProfile($patron);
        }

        // Get or generate the patron barcode
        $patron['barcode'] = empty($patron['barcode']) ?
            $this->genTempBarcode($patron['lastName'], $patron['firstName'], $patron['street']) : $patron['barcode'];

        // Create the data structure
        $new = $this->createFields($patron, $this->config['symphony']['new_fields'], $addrNum);
        $new['resource'] = '/user/patron';
        if ($patron['profile'] === 'ONLINE') {
            $new['fields']['privilegeExpiresDate'] = $this->getExpiration($this->config['symphony']['online_account_expiration']);
        }

        // Return a JSON string suitable for use in patron_register
        return json_encode($new, JSON_PRETTY_PRINT);
    }

    /**
     * Register a new patron and send welcome email to patron. Defaults to
     * English, but supports alternate language templates.
     *
     * @param array $patron Associative array containing patron data.
     * @param string|null $token The session token returned by ILSWS.
     * @param int|null $addrNum Optional Address number to update (1, 2, or 3, defaults to 1).
     * @param array $options Associative array of options (role, clientId, template, subject).
     * @return array Associative array containing response from ILSWS.
     * @throws Exception If validation fails, barcode cannot be set, SMS update fails, or email fails.
     */
    public function registerPatron(array $patron, ?string $token = null, ?int $addrNum = null, array $options = []): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('addrNum', $addrNum, 'r:#^[123]{1}$#');

        $role = !empty($options['role']) ? $options['role'] : 'PATRON';
        $this->validate('role', $role, 'v:STAFF|PATRON|GUEST');

        $clientId = !empty($options['clientId']) ? $options['clientId'] : $this->config['ilsws']['clientId'];
        $this->validate('clientId', $clientId, 'r:#^[A-Za-z]{4,20}$#');

        $template = !empty($options['template']) ? $options['template'] : '';
        $this->validate('template', $template, 'r:#^([a-zA-Z0-9\-_]{1,40})(\.)(html|text)(\.)(twig)$#');

        $subject = !empty($options['subject']) ? $options['subject'] : '';
        $this->validate('subject', $subject, 's:20');

        $response = [];

        // Get field metadata from Symphony and config
        $this->getFieldDesc($token, 'patron');
        $fields = $this->config['symphony']['new_fields'];

        // Convert aliases to Symphony fields
        $patron = $this->checkAliases($patron, $fields);

        // Check fields for required and default values and validate
        $patron = $this->checkFields($patron, $fields);

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

        if ($template) {
            if (is_readable($this->config['symphony']['template_path'] . '/' . $template . '.' . $language)) {
                $template = $template . '.' . $language;
            } else {
                if (is_readable($this->config['symphony']['template_path'] . '/' . $template . '.' . 'en')) {
                    $template = $template . '.' . 'en';
                } else {
                    throw new Exception("Missing or unreadable template file: {$template}");
                }
            }
        }

        // Create the required record structure for a registration
        $json = $this->createRegisterJson($patron, $token, (int) $addrNum);
        if ($this->config['debug']['register']) {
            error_log("DEBUG_REGISTER {$json}", 0);
        }

        // Send initial registration (and generate email)
        $requestOptions = [];
        $requestOptions['role'] = $role;
        $requestOptions['clientId'] = $clientId;
        $response = $this->sendQuery("{$this->baseUrl}/user/patron", $token, $json, 'POST', $requestOptions);

        if (!empty($response['key'])) {
            $patronKey = $response['key'];

            // If the barcode doesn't look like a real 14-digit barcode then change it to the patron key
            if (empty($patron['barcode']) || !preg_match('/^\d{14}$/', (string) $patron['barcode'])) {
                // Assign the patronKey from the initial registration to the update array
                $patron['barcode'] = $patronKey;
                if (!$this->changeBarcode($token, (string) $patronKey, (string) $patronKey, $requestOptions)) {
                    throw new Exception('Unable to set barcode to patron key');
                }
            }

            if (!empty($patron['phoneList'])) {
                if (!$this->updatePhoneList($patron['phoneList'], $token, (string) $patronKey, $requestOptions)) {
                    throw new Exception('SMS phone list update failed');
                }
            }

            if ($template && $this->validate('EMAIL', $patron['EMAIL'], 'e')) {
                if (!$subject) {
                    $subject = !empty($this->config['smtp']['smtp_default_subject']) ? $this->config['smtp']['smtp_default_subject'] : '';
                }
                if (!$this->emailTemplate(
                    $patron,
                    $this->config['smtp']['smtp_from'],
                    $patron['EMAIL'],
                    $subject,
                    $template
                )) {
                    throw new Exception('Email to patron failed');
                }
            }
        }

        return $response;
    }

    /**
     * Update existing patron record.
     *
     * @param array $patron Associative array containing patron data.
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The Symphony patron key.
     * @param int $addrNum Address number to update (1, 2, or 3, defaults to 1).
     * @return array Associative array containing result.
     * @throws Exception If validation fails or SMS update fails.
     */
    public function updatePatron(array $patron, ?string $token = null, ?string $patronKey = null, int $addrNum = 1): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
        $this->validate('addrNum', $addrNum, 'i:1,3');

        $response = [];

        // Get field metadata from Symphony and config
        $this->getFieldDesc($token, 'patron');
        $fields = $this->config['symphony']['overlay_fields'];

        // Convert aliases to Symphony fields
        $patron = $this->checkAliases($patron, $fields);

        // Check fields for required and default values and validate
        $patron = $this->checkFields($patron, $fields);

        // Create the JSON data structure
        $json = $this->createUpdateJson($patron, $token, $patronKey, $addrNum);

        if ($this->config['debug']['update']) {
            error_log("DEBUG_UPDATE {$json}", 0);
        }

        $response = $this->sendQuery("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, $json, 'PUT');

        if (!empty($patron['phoneList'])) {
            if (!$this->updatePhoneList($patron['phoneList'], $token, (string) $patronKey)) {
                throw new Exception('SMS phone list update failed');
            }
        }

        return $response;
    }

    /**
     * Update patron extended information fields related to user IDs, specifically
     * ACTIVEID, INACTVID, PREV_ID, PREV_ID2, and STUDENT_ID.
     *
     * Please note: this function does not test to see if these fields are defined
     * in your Symphony configuration. It will throw errors if they are not.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey Primary key of the patron record to be modified.
     * @param string|null $patronId The patron ID (barcode).
     * @param string|null $option Single character option:
     * a = Add active ID
     * i = Add inactive ID
     * d = Delete an ID from the ACTIVEID, INACTVID, PREV_ID, PREV_ID2, or STUDENT_ID.
     * @return int Return value: 1 = Success, 0 = Failure.
     * @throws Exception If validation fails.
     */
    public function updatePatronActiveId(?string $token = null, ?string $patronKey = null, ?string $patronId = null, ?string $option = null): int 
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
        $this->validate('patronId', $patronId, 'r:#^[A-Z0-9]{1,20}$#');
        $this->validate('option', $option, 'v:a|i|d');

        $retval = 0;
        $custom = [];

        // Get the current customInformation from the patron record
        $res = $this->sendGet("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, ['includeFields' => 'customInformation{*}']);

        if ($res) {
            if ($option == 'a') {
                if (!empty($res['fields']['customInformation'])) {
                    $custom = $res['fields']['customInformation'];
                    for ($i = 0; $i < count($custom); $i++) {
                        if ($custom[$i]['fields']['code']['key'] == 'ACTIVEID' && $custom[$i]['fields']['data']) {
                            $values = preg_split("/,/", $custom[$i]['fields']['data']);
                            array_push($values, (string) $patronId);
                            $custom[$i]['fields']['data'] = implode(',', $values);
                        }
                    }
                }
            } elseif ($option == 'i') {
                if (!empty($res['fields']['customInformation'])) {
                    $custom = $res['fields']['customInformation'];
                    for ($i = 0; $i < count($custom); $i++) {
                        if ($custom[$i]['fields']['code']['key'] == 'INACTVID' && $custom[$i]['fields']['data']) {
                            $values = preg_split("/,/", $custom[$i]['fields']['data']);
                            array_push($values, (string) $patronId);
                            $custom[$i]['fields']['data'] = implode(',', $values);
                        }
                    }
                }
            } elseif ($option == 'd') {
                if (!empty($res['fields']['customInformation'])) {
                    $custom = $res['fields']['customInformation'];
                    for ($i = 0; $i < count($custom); $i++) {
                        $fields = ['ACTIVEID', 'INACTVID', 'PREV_ID', 'PREV_ID2', 'STUDENT_ID'];
                        if (in_array($custom[$i]['fields']['code']['key'], $fields) && $custom[$i]['fields']['data']) {
                            $values = preg_split("/,/", $custom[$i]['fields']['data']);
                            $newValues = [];
                            foreach ($values as $value) {
                                if ($value != $patronId) {
                                    array_push($newValues, $value);
                                }
                            }
                            $custom[$i]['fields']['data'] = implode(',', $newValues);
                        }
                    }
                }
            }

            $patron = [];
            $patron['resource'] = '/user/patron';
            $patron['key'] = $patronKey;
            $patron['fields']['customInformation'] = $custom;

            // Update the patron
            $jsonStr = json_encode($patron, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $res = $this->updatePatron($patron, $token, (string) $patronKey);

            if ($res) {
                $retval = 1;
            }
        }

        return $retval;
    }

    /**
     * Update the patron lastActivityDate.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronId The patron ID (barcode).
     * @return array Associative array containing result.
     * @throws Exception If validation fails.
     */
    public function updatePatronActivity(?string $token = null, ?string $patronId = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronId', $patronId, 'r:#^[A-Z0-9]{1,20}$#');

        $json = "{\"patronBarcode\": \"{$patronId}\"}";

        return $this->sendQuery("{$this->baseUrl}/user/patron/updateActivityDate", $token, $json, 'POST');
    }

    /**
     * Get access-point field metadata from Symphony.
     *
     * @param string $token The session token returned by ILSWS.
     * @param string $name The access point metadata to retrieve.
     * @return void
     */
    private function getFieldDesc(string $token, string $name): void
    {
        $fieldArrays = [];
        if ($name === 'patron') {
            $fieldArrays = $this->describePatron($token);
            $type = 'fields';
        } else {
            $fieldArrays = $this->sendGet("{$this->baseUrl}/user/patron/{$name}/describe", $token, []);
            $type = 'params';
        }

        // Make the fields descriptions accessible by name
        foreach ($fieldArrays[$type] as $object) {
            $fieldName = $object['name'];
            foreach ($object as $key => $value) {
                $this->fieldDesc[$fieldName][$key] = $object[$key];
            }
        }

        if ($this->config['debug']['fields']) {
            $json = json_encode($this->fieldDesc, JSON_PRETTY_PRINT);
            error_log("DEBUG_FIELDS {$json}", 0);
        }
    }

    /**
     * Change a patron barcode.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The Symphony patron key.
     * @param string|null $patronId The new Symphony barcode (patron ID).
     * @param array $options Optional array of options.
     * @return int 1 for success, 0 for failure.
     * @throws Exception If validation fails.
     */
    public function changeBarcode(?string $token = null, ?string $patronKey = null, ?string $patronId = null, array $options = []): int
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
        $this->validate('patronId', $patronId, 'r:#^[0-9A-Z]{1,20}$#');

        $new = [];
        $new['resource'] = '/user/patron';
        $new['key'] = $patronKey;
        $new['fields']['barcode'] = $patronId;

        $json = json_encode($new, JSON_PRETTY_PRINT);
        $response = $this->sendQuery("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, $json, 'PUT', $options);

        $returnCode = 0;
        if (!empty($response['fields']['barcode']) && $response['fields']['barcode'] == $patronId) {
            $returnCode = 1;
        }

        return $returnCode;
    }

    /**
     * Update the SMS phone list.
     *
     * @param array $phoneList Elements to include in the phone list.
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The Symphony patron key.
     * @param array $options Optional array of options.
     * @return int 1 for success, 0 for failure.
     * @throws Exception If validation fails.
     */
    public function updatePhoneList(array $phoneList, ?string $token = null, ?string $patronKey = null, array $options = []): int
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');

        $new = [];
        $new['resource'] = '/user/patron';
        $new['key'] = $patronKey;
        $new['fields']['phoneList'] = [];
        array_push($new['fields']['phoneList'], $this->createFieldPhone((string) $patronKey, $phoneList));

        $json = json_encode($new, JSON_PRETTY_PRINT);
        $response = $this->sendQuery($this->baseUrl . "/user/patron/key/{$patronKey}", $token, $json, 'PUT', $options);

        if ($this->config['debug']['update']) {
            error_log('DEBUG_UPDATE ' . json_encode($response, JSON_PRETTY_PRINT), 0);
        }

        $returnCode = 0;
        if (!empty($response['key']) && $response['key'] === $patronKey) {
            $returnCode = 1;
        }

        return $returnCode;
    }

    /**
     * Get patron custom information.
     *
     * @param string|null $token Session token returned by ILSWS.
     * @param string|null $patronKey Patron key.
     * @return array Associative array of custom keys and values.
     * @throws Exception If validation fails.
     */
    public function getPatronCustomInfo(?string $token = null, ?string $patronKey = null): array
    {
        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');

        $response = $this->sendGet("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, ['includeFields' => 'customInformation{*}']);

        $custom = [];
        if (!empty($response['fields']['customInformation'])) {
            $custom = $response['fields']['customInformation'];
        }

        return $custom;
    }

    /**
     * Set all matching custom info array to a value (be careful!).
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The Symphony patron key.
     * @param string|null $key Key of the array entry we want to modify.
     * @param string|null $value Value to put into the data field.
     * @return int 1 for success, 0 for failure.
     * @throws Exception If validation fails.
     */
    public function modPatronCustomInfo(
        ?string $token = null,
        ?string $patronKey = null,
        ?string $key = null,
        ?string $value = null
    ): int {
        $retVal = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
        $this->validate('key', $key, 's:255');
        $this->validate('value', $value, 's:255');

        $custom = $this->getPatronCustomInfo($token, (string) $patronKey);

        $found = 0;
        $new = [];
        foreach ($custom as $r) {
            if ($r['fields']['code']['key'] == $key) {
                $r['fields']['data'] = $value;
                $found = 1;
            }
            array_push($new, $r);
        }

        $patron = [];
        if ($found) {
            $patron['resource'] = '/user/patron';
            $patron['key'] = $patronKey;
            $patron['fields']['customInformation'] = $new;

            // Update the patron
            $jsonStr = json_encode($patron, JSON_UNESCAPED_SLASHES);
            $response = $this->sendQuery("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, $jsonStr, 'PUT');
        }

        if (!empty($response['key']) && $response['key'] == $patronKey) {
            $retVal = 1;
        }

        return $retVal;
    }

    /**
     * Add a custom information to the patron record.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The Symphony patron key.
     * @param string|null $key Key of the array entry we want to modify.
     * @param string|null $value Value to put into the data field.
     * @return int 1 for success, 0 for failure.
     * @throws Exception If validation fails.
     */
    public function addPatronCustomInfo(
        ?string $token = null,
        ?string $patronKey = null,
        ?string $key = null,
        ?string $value = null
    ): int {
        $retVal = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
        $this->validate('key', $key, 's:255');
        $this->validate('value', $value, 's:255');

        $custom = $this->getPatronCustomInfo($token, (string) $patronKey);

        if (!empty($custom)) {
            foreach ($custom as $r) {
                if ($r['fields']['data'] == $value) {
                    // The value already exists, so return for success
                    return 1;
                }
            }
        }

        // Get the maximum index key value
        $i = 1;
        if (!empty($custom)) {
            foreach ($custom as $r) {
                if ($r['key'] > $i) {
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
                    'key' => "{$key}"
                ],
                'data' => $value
            ]
        ];

        array_push($custom, $new);

        $patron = [];
        $patron['resource'] = '/user/patron';
        $patron['key'] = $patronKey;
        $patron['fields']['customInformation'] = $custom;

        // Update the patron
        $jsonStr = json_encode($patron, JSON_UNESCAPED_SLASHES);
        $response = $this->sendQuery("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, $jsonStr, 'PUT');

        if ($response['key'] == $patronKey) {
            $retVal = 1;
        }

        return $retVal;
    }

    /**
     * Delete custom information from the patron record. Be careful this will delete all matching keys.
     *
     * @param string|null $token The session token returned by ILSWS.
     * @param string|null $patronKey The Symphony patron key.
     * @param string|null $key Key of the array entry we want to delete.
     * @return int 1 for success, 0 for failure.
     * @throws Exception If validation fails.
     */
    public function delPatronCustomInfo(?string $token = null, ?string $patronKey = null, ?string $key = null): int
    {
        $retVal = 0;

        $this->validate('token', $token, 'r:#^[a-z0-9\-]{36}$#');
        $this->validate('patronKey', $patronKey, 'r:#^\d{1,6}$#');
        $this->validate('key', $key, 's:255');

        $custom = $this->getPatronCustomInfo($token, (string) $patronKey);

        $new = [];
        if (!empty($custom)) {
            foreach ($custom as $r) {
                if ($r['fields']['code']['key'] != $key) {
                    array_push($new, $r);
                }
            }
        }

        $patron = [];
        $patron['resource'] = '/user/patron';
        $patron['key'] = $patronKey;
        $patron['fields']['customInformation'] = $new;

        // Update the patron
        $jsonStr = json_encode($patron, JSON_UNESCAPED_SLASHES);
        $response = $this->sendQuery("{$this->baseUrl}/user/patron/key/{$patronKey}", $token, $jsonStr, 'PUT');

        if ($response['key'] == $patronKey) {
            $retVal = 1;
        }

        return $retVal;
    }

    /**
     * Return a unique temporary barcode.
     *
     * @param string $lastName Last name of patron.
     * @param string $firstName First name of patron.
     * @param string $street Street address of patron.
     * @return string Temporary barcode.
     */
    private function genTempBarcode(string $lastName, string $firstName, string $street): string
    {
        $lastName = substr($lastName, 0, 4);
        $firstName = substr($firstName, 0, 2);
        $num = rand(1, 99999);

        // Extract the street name from the street address
        $words = preg_split('/\s+/', $street);
        foreach ($words as $word) {
            if (preg_match('/^(N|NW|NE|S|SW|SE|E|W|\d+)$/', $word)) {
                continue;
            } else {
                $street = substr($word, 0, 4);
            }
        }

        return $lastName . $firstName . $street . $num;
    }

    /**
     * Email text message from template.
     *
     * @param array $patron Array of patron fields to use in template.
     * @param string $from Email address from which to send.
     * @param string $to Email address to send to.
     * @param string $subject Subject of email.
     * @param string $template Template filename.
     * @return int Result: 1 for success, 0 for failure.
     */
    public function emailTemplate(array $patron, string $from, string $to, string $subject, string $template): int
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
            if ($this->config['debug']['smtp']) {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable verbose debug output
            }

            $mail->isSMTP(); // Send using SMTP
            $mail->CharSet = 'UTF-8'; // Use unicode
            $mail->Encoding = 'base64'; // Encode test in base64

            $mail->Host = $this->config['smtp']['smtp_host']; // Set the SMTP server to send through

            // If we've got email account credentials, use them
            if (!empty($this->config['smtp']['smtp_username']) && !empty($this->config['smtp']['smtp_password'])) {
                $mail->SMTPAuth = true; // Enable SMTP authentication
                $mail->Username = $this->config['smtp']['smtp_username']; // SMTP username
                $mail->Password = $this->config['smtp']['smtp_password']; // SMTP password
            } else {
                $mail->SMTPAuth = false;
            }

            if (!empty($this->config['smtp']['smtp_protocol']) && $this->config['smtp']['smtp_protocol'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Enable implicit TLS encryption
            }

            // TCP port to connect to. Use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`
            $mail->Port = $this->config['smtp']['smtp_port'];

            // Set from address
            $mail->setFrom($from, $this->config['smtp']['smtp_fromname']);

            // Set recipients
            $addresses = preg_split('/,/', $to);
            foreach ($addresses as $address) {
                $mail->addAddress(trim($address)); //Name is optional
            }

            // Reply-to
            if (!empty($this->config['smtp']['smtp_replyto'])) {
                $mail->addReplyTo($this->config['smtp']['smtp_replyto']);
            }

            //Content
            if (!empty($this->config['smtp']['smtp_allowhtml']) && $this->config['smtp']['smtp_allowhtml'] === 'true') {
                $mail->isHTML(true); //Set email format to HTML
            }

            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = 'Welcome to Multnomah County Library. Your card number is ' . $patron['barcode'];

            $mail->send();
            $result = 1;
        } catch (MailerException $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }

        return $result;
    }
}
