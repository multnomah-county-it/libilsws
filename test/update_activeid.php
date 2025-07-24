<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 4) {
    echo "Syntax: php {$argv[0]} PATRON_KEY PATRON_ID a|i|d\n";
    exit;
}

$patronKey = $argv[1];
$patronId = $argv[2];
$option = $argv[3];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->updatePatronActiveId($token, $patronKey, $patronId, $option);
echo "{$response}\n";
