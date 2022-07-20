<?php

require_once 'vendor/autoload.php';

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$params = [];
$response = $ilsws->send_get("$ilsws->base_url/user/patron/register/describe", $token, $params);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

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
    'language' => 'ENGLISH',
    'lastName' => 'Bogart',
    'library_news' => 'YES',
    'middleName' => 'T',
    'notice_type' => 'PHONE',
    'postal_code' => '97209',
    'street' => '925 NW Hoyt St Apt 406',
    'telephone' => '215-534-6821',
    'sms_phone_list' => [
        'number' => '215-534-6820',
        'countryCode' => 'US',
        'bills'       => true,
        'general'     => true,
        'holds'       => true,
        'manual'      => true,
        'overdues'    => true,
        ],
    ];

$response = $ilsws->patron_register($patron, $token);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
