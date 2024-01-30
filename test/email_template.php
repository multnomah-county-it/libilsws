<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 6 ) {
    print "Syntax: php $argv[0] BARCODE TO FROM SUBJECT TEMPLATE\n";
    exit;
}

$patron['barcode'] = $argv[1];
$to = $argv[2];
$from = $argv[3];
$subject = $argv[4];
$template = $argv[5];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Change barcode returns 1 for success or 0 for failure
$response = $ilsws->email_template($patron, $to, $from, $subject, $template);

print "$response\n";
// EOF
