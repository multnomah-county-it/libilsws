<?php

require_once 'vendor/autoload.php';
// error_reporting(E_ALL ^ E_WARNING);

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$params = array();
$response = $ilsws->send_get("$ilsws->base_url/user/patron/register/describe", $token, $params);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
