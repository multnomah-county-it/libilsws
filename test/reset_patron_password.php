<?php

require_once 'vendor/autoload.php';

$barcode = $argv[1];
$email = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$url = 'https://multcolib.io/contact/reset_password?token=<RESET_PASSWORD_TOKEN>';
$response = $ilsws->reset_patron_password($token, $barcode, $url, $email);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// EOF
