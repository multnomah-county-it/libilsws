<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] BIB_KEY\n";
    exit;
}

$bib_key = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$response = $ilsws->get_bib_marc($token, $bib_key);
foreach ($response as $tag => $value) {
    print "$tag $value\n";
}

// EOF
