<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 2) {
    echo "Syntax: php {$argv[0]} ITEM_BARCODE\n";
    exit;
}

$barcode = $argv[1];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

$json = "{\"itemBarcode\":\"{$barcode}\"}";

// Add header and role required for this API endpoint
$options = [];
$options['role'] = 'STAFF';

// Describe patron register function
$response = $ilsws->sendQuery("{$ilsws->baseUrl}/circulation/itemCircInfo/advise", $token, $json, 'POST', $options);
print_r($response);
