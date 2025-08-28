<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} VALIDATION_RULE STRING\n";
    exit;
}

$rule = $argv[1];
$stringToValidate = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

/**
 * Validates various types of incoming field data.
 * Sample fields hash with validation rules:
 *
 * 'blank'      => 'b',                  // must be blank
 * 'boolean'    => 'o',                  // 1|0
 * 'date1'      => 'd:YYYY-MM-DD',
 * 'date2'      => 'd:YYYY/MM/DD',
 * 'date3'      => 'd:MM-DD-YYYY',
 * 'date4'      => 'd:MM/DD/YYYY',
 * 'email'      => 'e',
 * 'timestamp1' => 'd:YYYY/MM/DD HH:MM',
 * 'timestamp2' => 'd:YYYY-MM-DD HH:MM',
 * 'integer'    => 'i:1,99999999',       // integer between 1 and 99999999
 * 'JSON'       => 'j',                  // JSON
 * 'number'     => 'n:1,999',            // decimal number between 1 and 999
 * 'regex'      => 'r:/^[A-Z]{2,4}$/',   // Regular expression pattern
 * 'string'     => 's:256',              // string of length <= 256
 * 'url'        => 'u',                  // URL
 * 'list'       => 'v:01|11',            // list('01', '11')
 */

try {
    $ilsws->validate('Test', $stringToValidate, $rule);
    print "String \"$stringToValidate\" valid\n";
} catch (Exception $e) {
    // Code to handle the exception
    print $e->getMessage() . "\n";
}
