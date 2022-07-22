<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {

    print "Syntax: php $argv[0] INDEX SEARCH\n";
    exit;

} else {

    $index = $argv[1];
    $search = $argv[2];
}

// Initialize
print "Initializing\n\n";
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
print "Connecting\n\n";
$token = $ilsws->connect();

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */
print "patron_search\n";
$params = [ 
    'ct'            => '50',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'key,firstName,middleName,lastName',
    ];
$response = $ilsws->patron_search($token, $index, $search, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
