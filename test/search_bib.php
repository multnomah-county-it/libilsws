<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 4 ) {
    print "Syntax: php $argv[0] INDEX SEARCH INCLUDE_FIELDS\n";
    print "Please supply include fields in a comma-delimited list.\n";
    exit;
} 

$index = $argv[1];
$search = $argv[2];
$include_fields = $argv[3];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Remove unwanted characters from search string
$search = $ilsws->prepare_search($search);

print "$search\n";

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */
$params = [ 
    'ct'            => '50',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => $include_fields
    ];
$response = $ilsws->search_bib($token, $index, $search, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
