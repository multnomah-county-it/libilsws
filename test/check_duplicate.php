<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 5) { 
    echo "Syntax: php {$argv[0]} INDEX SEARCH INDEX2 SEARCH2 PARAM5\n";
    exit;
}

$index = $argv[1];
$search = $argv[2];
$index2 = $argv[3];
$search2 = $argv[4];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the
 * includeFields, which is configured in the libilsws.yaml file.
 */
$response = $ilsws->checkDuplicate($token, $index, $search, $index2, $search2);
echo "{$response}\n";
