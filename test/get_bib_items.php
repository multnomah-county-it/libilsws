<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

$bib_key = '1051686';
$field_list = 'author,title,callNumber,650_a,650_z';
$response = $ilsws->get_bib_items($token, $bib_key, $field_list);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
