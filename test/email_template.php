<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 6) {
    echo "Syntax: php {$argv[0]} BARCODE TO FROM SUBJECT TEMPLATE\n";
    exit;
}

$patron['barcode'] = $argv[1];
$to = $argv[2];
$from = $argv[3];
$subject = $argv[4];
$template = $argv[5];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Change barcode returns 1 for success or 0 for failure
$response = $ilsws->emailTemplate($patron, $to, $from, $subject, $template);
echo "{$response}\n";
