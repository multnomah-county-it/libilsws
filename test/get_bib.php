<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] BIB_KEY FIELD_LIST\n";
    exit 0;
}

$bib_key = $argv[1];
$field_list = $argv[2];
# Example field list: 'author,title,650_a,650_z'

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_bib($token, $bib_key, $field_list);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
