<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

/**
 * Not all of these are actually required. See the YAML configuration file to determine
 * which fields are required.
 */
$patron = [
    'birth_date' => '1962-03-07',
    'city_state' => 'Portland, OR',
    'county' => '0_MULT',
    'profile' => '0_MULT',
    'patron_id' => '99999999999923',
    'email' => 'johnchouser@gmail.com',
    'first_name' => 'Bogus',
    'friends_notices' => 'YES',
    'home_library' => 'CEN',
    'language' => 'ENGLISH',
    'last_name' => 'Bogart',
    'library_news' => 'YES',
    'middle_name' => 'T',
    'notice_type' => 'PHONE',
    'pin' => 'Waffles125',
    'postal_code' => '97209',
    'street' => '925 NW Hoyt St Apt 406',
    'telephone' => '215-534-6821',
    'sms_phone' => [
        'number' => '215-534-6821',
        'countryCode' => 'US',
        'bills' => true,
        'general' => true,
        'holds' => true,
        'manual' => true,
        'overdues' => true,
    ],
];

$addrNum = 1;

/**
 * Template file minus the language extension. Don't include the path, that must be
 * defined in the libilsws.yaml configuration file.
 */
$options = [];
$options['template'] = 'registration_email.html.twig';
$options['role'] = 'STAFF';     // Used in the SD-Preferred-Role HTTP header
$options['clientId'] = 'QUIPU'; // Used in the x-sirs-clientID HTTP header
$options['subject'] = 'Welcome to our library!';

$response = $ilsws->registerPatron($patron, $token, $addrNum, $options);
print_r($response);
