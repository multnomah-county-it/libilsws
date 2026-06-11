<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} PATRON_ID PASSWORD\n";
    exit;
}

$patronId = $argv[1];
$password = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->authenticatePatronId($token, $patronId, $password);
echo "{$response}\n";
