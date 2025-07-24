<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 5) {
    echo "Syntax: php {$argv[0]} INDEX SEARCH LIBRARY INCLUDE_FIELDS\n";
    echo "Please supply include fields in a comma-delimited list.\n";
    exit;
}

$index = $argv[1];
$search = $argv[2];
$library = $argv[3];
$includeFields = $argv[4];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Remove unwanted characters from search string
$search = $ilsws->prepareSearch($search);

$search = "{$index}:{$search},library:{$library}";
echo "{$search}\n";

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the
 * includeFields, which is configured in the libilsws.yaml file.
 */
$params = [
    'q' => $search,
    'ct' => $params['ct'] ?? '1000',
    'rw' => $params['rw'] ?? '1',
    'j' => $params['j'] ?? 'AND',
    'includeFields' => $includeFields,
];

$response = $ilsws->sendGet("{$ilsws->baseUrl}/catalog/bib/search", $token, $params);
print_r($response);
