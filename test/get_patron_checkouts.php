<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 2) {
    echo "Syntax: php {$argv[0]} PATRON_KEY\n";
    exit;
}

$key = $argv[1];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe call record
// $response = $ilsws->sendGet("{$ilsws->baseUrl}/symws/user/patron/key/{$key}?includeFields=*,blockList{*},circRecordList{*}", $token, []);
$response = $ilsws->getPatronCheckouts($token, $key);
print_r($response);
