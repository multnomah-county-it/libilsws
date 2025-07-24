<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} CALL_KEY FIELD_LIST\n";
    exit;
}

$callKey = $argv[1];
$fieldList = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->getCallNumber($token, $callKey, $fieldList);
print_r($response);
