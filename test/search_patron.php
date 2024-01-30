<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] INDEX SEARCH\n";
    exit;

}

$index = $argv[1];
$search = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */
$params = [ 
    'ct'            => '50',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'key,firstName,middleName,lastName,language,profile,category01,category02,category03,category05,category06,category11,address1{*},phoneList{*}',
    ];
$response = $ilsws->search_patron($token, $index, $search, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
