<?php

require_once 'vendor/autoload.php';

$field_list = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();
print "token: $token\n";

$response = $ilsws->describe_bib($token);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
