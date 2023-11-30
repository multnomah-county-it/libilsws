<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 5 ) {
    print "Syntax: php $argv[0] INDEX SEARCH LIBRARY INCLUDE_FIELDS\n";
    print "Please supply include fields in a comma-delimited list.\n";
    exit;
} 

$index = $argv[1];
$search = $argv[2];
$library = $argv[3];
$include_fields = $argv[4];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Remove unwanted characters from search string
$search = $ilsws->prepare_search($search);

$search = "$index:$search,library:$library"; 
print "$search\n";

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */

$params = [
    'q'             => $search,
    'ct'            => $params['ct'] ?? '1000',
    'rw'            => $params['rw'] ?? '1',
    'j'             => $params['j'] ?? 'AND',
    'includeFields' => $include_fields
    ];
 
$response = $ilsws->send_get("$ilsws->base_url/catalog/bib/search", $token, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
