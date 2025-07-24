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

$response = $ilsws->addPatronCustomInfo($token, $patronKey, $key, $value);
echo "{$response}\n";
