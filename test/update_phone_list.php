<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] PATRON_KEY\n";
    exit;
}

$patron_key = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$phone = [
    'number' => '215-534-6821',
    'countryCode' => 'US',
    'bills'       => true,
    'general'     => true,
    'holds'       => true,
    'manual'      => true,
    'overdues'    => true
    ];

// Change barcode returns 1 for success or 0 for failure
$response = $ilsws->update_phone_list($phone, $token, $patron_key);

print "$response\n";
// EOF
