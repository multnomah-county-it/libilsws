<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 4) {
    echo "Syntax: php {$argv[0]} ITEM_KEY LIBRARY WORKING_LIBRARY\n";
    exit;
}

$itemKey = $argv[1];
$library = $argv[2];
$workingLibrary = $argv[3];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Change barcode returns 1 for success or 0 for failure
$response = $ilsws->transitItem($token, $itemKey, $library, $workingLibrary);
print_r($response);
