<?php

require_once 'vendor/autoload.php';
// error_reporting(E_ALL ^ E_WARNING);

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

// Describe patron register function
$params = array();
$response = $ilsws->send_get("$ilsws->base_url/user/patron/register/describe", $token, $params);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

$patron = array(
    'firstName' => 'Bogus',
    'middleName' => 'T',
    'lastName' => 'Bogart',
    'birthDate' => '1962-03-07',
    'home_library' => 'CEN',
    'county' => '0_MULT',
    'notice_type' => 'PHONE',
    'library_news' => 'YES',
    'friends_notices' => 'YES',
    'online_update' => 'YES',
    'street' => '925 NW Hoyt St Apt 406',
    'city_state' => 'Portland, OR',
    'postal_code' => '97209',
    'email' => 'johnchouser@gmail.com',
    'telephone' => '215-534-6821',
    );

$response = $ilsws->patron_register($token, $patron);
print json_encode($response, JSON_PRETTY_PRINT) . "\n";

// EOF
