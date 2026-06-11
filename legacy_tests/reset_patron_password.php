<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} BARCODE CALLBACK_URL\n";
    exit;
}

$barcode = $argv[1];
$url = $argv[2];
# Example callback URL: 'https://multcolib.org/contact/reset_password?token=<RESET_PASSWORD_TOKEN>'

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->resetPatronPassword($token, $barcode, $url);
print_r($response);
