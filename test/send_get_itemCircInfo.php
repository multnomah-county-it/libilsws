<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] ITEM_KEY\n";
    exit;
}

$item_key = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe call record
$response = $ilsws->send_get("$ilsws->base_url/circulation/itemCircInfo/key/$item_key", $token, []);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
