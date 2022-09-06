<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] CALL_KEY FIELD_LIST\n";
    exit;
}

$call_key = $argv[1];
$field_list = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_call_number($token, $call_key, $field_list);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
