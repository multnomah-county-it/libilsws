<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 2) {
    echo "Syntax: php {$argv[0]} PATRON_KEY\n";
    exit;
}

$patronKey = $argv[1];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

$phone = [
    'number' => '215-534-6821',
    'countryCode' => 'US',
    'bills' => true,
    'general' => true,
    'holds' => true,
    'manual' => true,
    'overdues' => true,
];

/**
 * Change barcode returns 1 for success or 0 for failure. NOTE:
 * this script only updates the SMS number, not the telephone
 * in the address.
 */
$response = $ilsws->updatePhoneList($phone, $token, $patronKey);
echo "{$response}\n";
