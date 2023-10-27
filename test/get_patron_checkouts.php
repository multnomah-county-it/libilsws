<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] PATRON_KEY\n";
    exit;
}

$key = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe call record
// $response = $ilsws->send_get("$ilsws->base_url/symws/user/patron/key/$key?includeFields=*,blockList{*},circRecordList{*}", $token, []);
$response = $ilsws->get_patron_checkouts($token, $key);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
