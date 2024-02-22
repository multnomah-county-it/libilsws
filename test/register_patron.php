<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

/**
 * Not all of these are actually required. See the YAML configuration file to determine
 * which fields are required.
 */
$patron = [
    'birthDate' => '1962-03-07',
    'city_state' => 'Portland, OR',
    'county' => '0_MULT',
    'email' => 'johnchouser@gmail.com',
    'firstName' => 'Bogus',
    'friends_notices' => 'YES',
    'home_library' => 'CEN',
    'lastName' => 'Bogart',
    'library_news' => 'YES',
    'language' => 'SOMALI',
    'middleName' => 'T',
    'notice_type' => 'PHONE',
    'pin' => 'Waffles125',
    'postal_code' => '97209',
    'profile' => 'ONLINE',
    'patron_id' => '99999999998',
    'street' => '925 NW Hoyt St Apt 406',
    'telephone' => '215-534-6821',
    'sms_phone' => [
        'number' => '215-534-6821',
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
$options['template'] = 'registration_email.html.twig';
$options['role'] = 'STAFF';      // Used in the SD-Preferred-Role HTTP header
$options['client_id'] = 'QUIPU'; // Used in the x-sirs-clientID HTTP header
$options['subject'] = 'Waffles are good';

$response = $ilsws->register_patron($patron, $token, $addr_num, $options);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
