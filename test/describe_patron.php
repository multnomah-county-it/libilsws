<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->describePatron($token);
print_r($response);
