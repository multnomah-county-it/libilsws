<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 4) {
    echo "Syntax: php {$argv[0]} PATRON_KEY KEY VALUE\n";
    exit;
}

$patronKey = $argv[1];
$key = $argv[2];
$value = $argv[3];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe call record
$response = $ilsws->modPatronCustomInfo($token, $patronKey, $key, $value);

if (!$response) {
    echo "Error updating patron extended information\n";
    exit;
}
echo "{$response}\n";
