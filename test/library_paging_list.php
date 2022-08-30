<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();
print "token: $token\n";

// Describe patron register function
$response = $ilsws->library_paging_list($token, $argv[1]);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
