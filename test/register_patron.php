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
    'email' => 'johnchouser@gmail.com',
    'first_name' => 'Bogus',
    'friends_notices' => 'YES',
    'home_library' => 'CEN',
    'last_name' => 'Bogart',
    'library_news' => 'YES',
    'language' => 'SOMALI',
    'middle_name' => 'T',
    'notice_type' => 'PHONE',
    'pin' => 'Waffles126',
    'postal_code' => '97209',
    'profile' => 'ONLINE',
    'street' => '925 NW Hoyt St Apt 408',
    'telephone' => '215-534-6820',
    'sms_phone' => [
        'number' => '215-534-6820',
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

/**
 * Template file minus the language extension. Don't include the path, that must be
 * defined in the libilsws.yaml configuration file.
 */
$options['template'] = 'registration_email.html.twig';
$options['role'] = 'STAFF';     // Used in the SD-Preferred-Role HTTP header
$options['clientId'] = 'QUIPU'; // Used in the x-sirs-clientID HTTP header
$options['subject'] = 'Welcome to our library!';

$response = $ilsws->registerPatron($patron, $token, $addrNum, $options);
print_r($response);
