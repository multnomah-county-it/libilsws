<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] LIBRARY_CODE\n";
    exit;
}

$library_code = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_library_paging_list($token, $library_code);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
