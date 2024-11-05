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
    'profile' => '0_MULT',
    'patron_id' => '99999999999922',
    'email' => 'johnchouser@gmail.com',
    'firstName' => 'Bogus',
    'friends_notices' => 'YES',
    'home_library' => 'CEN',
    'language' => 'ENGLISH',
    'lastName' => 'Bogart',
    'library_news' => 'YES',
    'middleName' => 'T',
    'notice_type' => 'PHONE',
    'pin' => 'Waffles125',
    'postal_code' => '97209',
    'street' => '925 NW Hoyt St Apt 406',
    'telephone' => '215-534-6821',
    'phoneList' => [
        'number' => '215-534-6821',
        'countryCode' => 'US',
        'bills'       => TRUE,
        'general'     => TRUE,
        'holds'       => TRUE,
        'manual'      => TRUE,
        'overdues'    => TRUE,
        ],
    ];

$addr_num = 1;

$options = [];
$options['role'] = 'STAFF';
$options['client_id'] = 'QUIPU';

$response = $ilsws->register_patron($patron, $token, $addr_num, $options);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
