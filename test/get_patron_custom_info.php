<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] PATRON_KEY\n";
    exit;
}

$key = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe call record
$response = $ilsws->get_patron_custom_info($token, $key);

if ( count($response) ) {
    foreach ($response as $rec) {
        $code = $rec['fields']['code']['key'];
        $data = $rec['fields']['data'];
        print "$code: $data\n";
    }
} else {
    print "Nothing found\n";
}

// EOF
