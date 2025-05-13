<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] PATRON_ID PASSWORD\n";
    exit;
}

$patron_id = $argv[1];
$password = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->authenticate_patron_id($token, $patron_id, $password);

print_r($response);

// EOF
