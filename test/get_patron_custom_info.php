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
$response = $ilsws->getPatronCustomInfo($token, $key);

if (count($response)) {
    foreach ($response as $rec) {
        $code = $rec['fields']['code']['key'];
        $data = $rec['fields']['data'];
        echo "{$code}: {$data}\n";
    }
} else {
    echo "Nothing found\n";
}
