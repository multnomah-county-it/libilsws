<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 4 ) {
    print "Syntax: php $argv[0] ITEM_KEY LIBRARY WORKING_LIBRARY\n";
    exit;
}

$item_key = $argv[1];
$library = $argv[2];
$working_library = $argv[3];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Change barcode returns 1 for success or 0 for failure
$response = $ilsws->transit_item($token, $item_key, $library, $working_library);

print_r($response);
// EOF
