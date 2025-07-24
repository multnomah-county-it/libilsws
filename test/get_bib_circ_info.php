<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 2) {
    echo "Syntax: php {$argv[0]} BIB_KEY\n";
    exit;
}

$bibKey = $argv[1];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->getBibCircInfo($token, $bibKey);
print_r($response);
