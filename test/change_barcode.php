<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] PATRON_KEY NEW_BARCODE\n";
    exit;
}

$patron_key = $argv[1];
$patron_id = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Change barcode returns 1 for success or 0 for failure
$response = $ilsws->change_barcode($token, $patron_key, $patron_id);

print "$response\n";
// EOF
