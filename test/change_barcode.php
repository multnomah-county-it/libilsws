<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} PATRON_KEY NEW_BARCODE\n";
    exit;
}

$patronKey = $argv[1];
$patronId = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Change barcode returns 1 for success or 0 for failure
$response = $ilsws->changeBarcode($token, $patronKey, $patronId);
echo "{$response}\n";
