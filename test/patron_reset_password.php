<?php

require_once 'vendor/autoload.php';

$barcode = $argv[1];
$email = $argv[2];

// Initialize
print "Initializing\n\n";
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
print "Connecting\n\n";
$token = $ilsws->connect();

print "patron_reset_password\n\n";
$url = 'https://multcolib.io/contact/reset_password?token=<RESET_PASSWORD_TOKEN>';
$response = $ilsws->patron_reset_password($token, $barcode, $url, $email);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
