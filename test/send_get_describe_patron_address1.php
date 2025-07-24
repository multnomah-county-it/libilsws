<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

// Describe call record
$response = $ilsws->sendGet("{$ilsws->baseUrl}/user/patron/address1/describe", $token, []);
print_r($response);
