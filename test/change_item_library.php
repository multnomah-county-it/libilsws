<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] ITEM_KEY NEW_LIBRARY\n";
    exit;
}

$item_key = $argv[1];
$new_library = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->change_item_library($token, $item_key, $new_library);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
