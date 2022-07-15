<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 7 ) {

    print "Syntax: php $argv[0] EMAIL TELEPHONE BARCODE ALT_ID PATRON_KEY PASSWORD\n";
    exit;

} else {

    $email = $argv[1];
    $telephone = $argv[2];
    $patron_id = $argv[3];
    $alt_id = $argv[4];
    $patron_key = $argv[5];
    $password = $argv[6];
}

// Initialize
print "Initializing\n\n";
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
print "Connecting\n\n";
$token = $ilsws->connect();

/**
 * Authenticate via patron ID (barcode)
 *
 * Note: the ILSWS function this calls expects a full-sized barcode (14 digits)
 */
print "authenticate_patron_id\n";
$returned_patron_key = $ilsws->authenticate_patron_id($token, $patron_id, $password);
print "patron_key: $returned_patron_key\n\n";

// Get patron attributes
print "authenticate_search\n";
$returned_patron_key = $ilsws->authenticate_search($token, 'EMAIL', $email, $password);
print "patron_key: $returned_patron_key\n\n";

// Get patron attributes
print "get_patron_attributes\n";
$attributes = $ilsws->get_patron_attributes($token, $patron_key);
$json = json_encode($attributes, JSON_PRETTY_PRINT);
print "$json\n\n";

// Update patron last activity date
print "patron_activity_update\n";
$response = $ilsws->patron_activity_update($token, $patron_id);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

/**
 * Search for patron by Alt ID
 *
 * Note: count is records per page, max 1000
 */
print "patron_alt_id_search\n";
$count = 1000;
$response = $ilsws->patron_alt_id_search($token, $alt_id, $count);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Patron authenticate
print "patron_authenticate\n";
$response = $ilsws->patron_authenticate($token, $patron_id, $password);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Search for patron by ID (barcode)
print "patron_id_search\n";
$count = 1000;
$response = $ilsws->patron_id_search($token, $patron_id, $count);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Describe the patron record
print "patron_describe\n";
$response = $ilsws->patron_describe($token);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */
print "patron_search\n";
$index = 'EMAIL';
$params = [ 
    'ct'            => '1',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'key,firstName,middleName,lastName',
    ];
$response = $ilsws->patron_search($token, $index, $email, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Create patron record JSON
print "create_patron_json overlay record\n";
$patron = [
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
    ];
$json = $ilsws->create_patron_json($patron, $patron_key);
print "$json\n";

// Patron update from JSON
print "patron_update from JSON\n";
$response = $ilsws->patron_update($token, $json, $patron_key);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// See test/register.php for patron registration example

// EOF
