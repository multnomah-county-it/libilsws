<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] BIB_KEY FIELD_LIST\n";
    exit;
}

$bib_key = $argv[1];
$field_list = $argv[2];
# Use a field list of 'raw' to see unfiltered and unformatted output.

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_bib($token, $bib_key, $field_list);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
