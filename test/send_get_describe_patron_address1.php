<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe call record
$response = $ilsws->send_get("$ilsws->base_url/user/patron/address1/describe", $token, []);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
