<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] ITEM_BARCODE\n";
    exit;
} 

$barcode = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$json = "{\"itemBarcode\":\"$barcode\"}";

// Add header and role required for this API endpoint
$options = [];
$options['role'] = 'STAFF';
     
// Describe patron register function
$response = $ilsws->send_query("$ilsws->base_url/circulation/itemCircInfo/advise", $token, $json, 'POST', $options);

$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
