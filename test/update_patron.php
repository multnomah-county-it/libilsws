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
    'language' => 'SPANISH',
    'lastName' => 'Bogart',
    'library_news' => 'YES',
    'middleName' => 'T',
    'notice_type' => 'PHONE',
    'postal_code' => '97209',
    'street' => '925 NW Hoyt St Apt 401',
    'telephone' => '215-534-6821',
    'sms_phone_list' => [
        'number' => '215-534-6821',
        'countryCode' => 'US',
        'bills'       => true,
        'general'     => true,
        'holds'       => true,
        'manual'      => true,
        'overdues'    => true,
        ],
    ];

$patron_key = '782439';
$addr_num = 1;

$json = $ilsws->update_patron_json($patron, $token, $patron_key);
print "$json\n\n";
$response = $ilsws->update_patron($token, $json, $patron_key);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

$json = $ilsws->update_patron_address_json($patron, $token, $patron_key, $addr_num);
print "$json\n\n";
$response = $ilsws->update_patron($token, $json, $patron_key);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
