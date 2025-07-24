<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 2) {
    echo "Syntax: php {$argv[0]} BIB_KEY\n";
    exit;
}

$bibKey = $argv[1];
# Use a field list of 'raw' to see unfiltered and unformatted output.

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->sendGet("{$ilsws->baseUrl}/catalog/bib/key/{$bibKey}", $token, []);
print_r($response);
