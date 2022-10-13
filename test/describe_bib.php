<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$response = $ilsws->describe_bib($token);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
