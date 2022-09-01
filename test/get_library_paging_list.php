<?php

require_once 'vendor/autoload.php';

$library_code = $argv[1];
if ( ! $library_code ) {
    $library_code = 'CEN';
}

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_library_paging_list($token, $library_code);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
