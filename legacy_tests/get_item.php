<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} ITEM_KEY FIELD_LIST\n";
    echo "Use php test/describe_item.php to description of available fields\n";
    exit;
}

$itemKey = $argv[1];
$fieldList = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->getItem($token, $itemKey, $fieldList);
print_r($response);
