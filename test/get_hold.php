<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] HOLD_KEY\n";
    exit;
}

$hold_key = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_hold($token, $hold_key);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
