<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 4) {
    echo "Syntax: php {$argv[0]} INDEX SEARCH INCLUDE_FIELDS\n";
    echo "Please supply include fields in a comma-delimited list.\n";
    exit;
}

$index = $argv[1];
$search = $argv[2];
$includeFields = $argv[3];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Remove unwanted characters from search string
$search = $ilsws->prepareSearch($search);

echo "{$search}\n";

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the
 * includeFields, which is configured in the libilsws.yaml file.
 */
$params = [
    'ct' => '50',
    'rw' => '1',
    'j' => 'AND',
    'includeFields' => $includeFields,
];
$response = $ilsws->searchBib($token, $index, $search, $params);
print_r($response);
