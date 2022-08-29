<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();
print "token: $token\n";

// Describe patron register function
$library_key = 'CEN';
$response = $ilsws->circulation_library_pull_list($token, $library_key);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
