<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] INDEX SEARCH\n";
    exit 0;
} 

$index = $argv[1];
$search = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */
$params = [ 
    'ct'            => '50',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'key,author,title,650_a,650_b,650_c,650_d,650_v,650_x,650_y,650_z,856_u'
    ];
$response = $ilsws->search_bib($token, $index, $search, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
