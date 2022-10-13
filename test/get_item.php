<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] ITEM_KEY FIELD_LIST\n";
    print "Use php test/describe_item.php to description of available fields\n";
    exit;
}

$item_key = $argv[1];
$field_list = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_item($token, $item_key, $field_list);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
