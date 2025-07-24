<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} BIB_KEY FIELD_LIST\n";
    exit;
}

$bibKey = $argv[1];
$fieldList = $argv[2];
# Example field list: 'author,title,650_a,650_z'

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->getBib($token, $bibKey, $fieldList);
print_r($response);
