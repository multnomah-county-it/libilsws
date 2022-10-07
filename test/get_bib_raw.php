<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] BIB_KEY\n";
    exit;
}

$bib_key = $argv[1];
# Use a field list of 'raw' to see unfiltered and unformatted output.

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->send_get("$ilsws->base_url/catalog/bib/key/$bib_key", $token, []);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
