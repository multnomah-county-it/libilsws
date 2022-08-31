<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {

    print "Syntax: php $argv[0] INDEX SEARCH\n";
    exit;

} else {

    $index = $argv[1];
    $search = $argv[2];
}

// Initialize
print "Initializing\n\n";
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
print "Connecting\n\n";
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
$response = $ilsws->catalog_search($token, $index, $search, $params);

$records = [];
foreach ($response as $record) {
    $record['items'] = $ilsws->get_bib_items($token, $record['key']);
    array_push($records, $record);
}

$json = json_encode($records, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
