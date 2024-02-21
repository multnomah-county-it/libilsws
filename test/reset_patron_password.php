<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] BARCODE CALLBACK_URL\n";
    exit;
}

$barcode = $argv[1];
$url = $argv[2];
# Example callback URL: 'https://multcolib.io/contact/reset_password?token=<RESET_PASSWORD_TOKEN>'

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->reset_patron_password($token, $barcode, $url);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
