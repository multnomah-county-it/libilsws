<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} ITEM_KEY NEW_LIBRARY\n";
    exit;
}

$itemKey = $argv[1];
$newLibrary = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->changeItemLibrary($token, $itemKey, $newLibrary);
print_r($response);
