<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 4 ) {
    print "Syntax: php $argv[0] PATRON_KEY KEY VALUE\n";
    exit;
}

$patron_key = $argv[1];
$key = $argv[2];
$value = $argv[3];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

return $ilsws->add_patron_custom_info($token, $patron_key, $key, $value);

// EOF
