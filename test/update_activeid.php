<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 4 ) {
    print "Syntax: php $argv[0] PATRON_KEY PATRON_ID a|i|d\n";
    exit;
}

$patron_key = $argv[1];
$patron_id = $argv[2];
$option =$argv[3];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->update_patron_activeid($token, $patron_key, $patron_id, $option);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
