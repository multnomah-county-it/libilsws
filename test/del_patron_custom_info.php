<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} PATRON_KEY KEY\n";
    exit;
}

$patronKey = $argv[1];
$key = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->delPatronCustomInfo($token, $patronKey, $key);
echo "{$response}\n";
