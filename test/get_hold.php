<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 2) {
    echo "Syntax: php {$argv[0]} HOLD_KEY\n";
    exit;
}

$holdKey = $argv[1];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->getHold($token, $holdKey);
print_r($response);
