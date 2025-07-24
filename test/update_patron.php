<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 2) {
    echo "Syntax: php {$argv[0]} PATRON_KEY\n";
    exit;
}

$patronKey = $argv[1];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

/**
 * Not all of these are actually required. See the YAML configuration file to determine
 * which fields are required.
 */
$patron = [
    'patron_id' => '21168045918653',
    'birth_date' => '1962-03-07',
    'city_state' => 'Astoria, OR',
    'county' => '0_MULT',
    'email' => 'johnchouser@gmail.com',
    'first_name' => 'John',
    'friends_notices' => 'YES',
    'home_library' => 'CEN',
    'language' => 'ENGLISH',
    'last_name' => 'Houser',
    'library_news' => 'YES',
    'middle_name' => 'C',
    'notice_type' => 'EMAIL',
    'postal_code' => '97209',
    'street' => '225 Alameda Ave Apt 2',
    'telephone' => '800-555-1212',
    'sms_phone' => [
        'number' => '800-555-1212',
        'countryCode' => 'US',
        'bills' => true,
        'general' => true,
        'holds' => true,
        'manual' => true,
        'overdues' => true,
    ],
];

$addrNum = 1;

$options = [];
$options['role'] = 'PATRON';
$options['clientId'] = 'SymWSTestClient';

$response = $ilsws->updatePatron($patron, $token, $patronKey, $addrNum);
print_r($response);
