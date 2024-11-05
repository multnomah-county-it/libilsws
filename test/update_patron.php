<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 2 ) {
    print "Syntax: php $argv[0] PATRON_KEY\n";
    exit;
}

$patron_key = $argv[1];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

/**
 * Not all of these are actually required. See the YAML configuration file to determine
 * which fields are required.
 */
$patron = [
    'patron_id' => '21168045918653',
    'birthDate' => '1962-03-07',
    'city_state' => 'Astoria, OR',
    'county' => '0_MULT',
    'email' => 'johnchouser@gmail.com',
    'firstName' => 'John',
    'friends_notices' => 'YES',
    'home_library' => 'CEN',
    'language' => 'ENGLISH',
    'lastName' => 'Houser',
    'library_news' => 'YES',
    'middleName' => 'C',
    'notice_type' => 'EMAIL',
    'postal_code' => '97209',
    'street' => '225 Alameda Ave Apt 2',
    'telephone' => 'a123',
    'sms_phone' => [
        'number' => 'abc',
        'countryCode' => 'US',
        'bills'       => true,
        'general'     => true,
        'holds'       => true,
        'manual'      => true,
        'overdues'    => true,
        ],
    ];

$addr_num = 1;

$options = [];
$options['role'] = 'PATRON';
$options['client_id'] = 'SymWSTestClient';

$response = $ilsws->update_patron($patron, $token, $patron_key, $addr_num);
print_r($response);

// EOF
