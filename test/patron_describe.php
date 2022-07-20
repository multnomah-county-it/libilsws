<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();
print "token: $token\n";

// Describe patron register function
$params = [];
$response = $ilsws->send_get("$ilsws->base_url/user/patron/describe", $token, $params);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
