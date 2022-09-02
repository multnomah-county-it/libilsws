<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 7 ) {
    print "Syntax: php $argv[0] EMAIL TELEPHONE BARCODE ALT_ID PATRON_KEY PASSWORD\n";
    exit 0;

} 

$email = $argv[1];
$telephone = $argv[2];
$patron_id = $argv[3];
$alt_id = $argv[4];
$patron_key = $argv[5];
$password = $argv[6];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
$token = $ilsws->connect();

/**
 * Authenticate via patron ID (barcode)
 *
 * Note: the ILSWS function this calls expects a full-sized barcode (14 digits)
 */
print "authenticate_patron_id\n";
$returned_patron_key = $ilsws->authenticate_patron_id($token, $patron_id, $password);
print "patron_key: $returned_patron_key\n\n";

// Authenticate search
print "search_authenticate\n";
$returned_patron_key = $ilsws->search_authenticate($token, 'EMAIL', $email, $password);
print "patron_key: $returned_patron_key\n\n";

// Get patron attributes
print "get_patron_attributes\n";
$attributes = $ilsws->get_patron_attributes($token, $patron_key);
$json = json_encode($attributes, JSON_PRETTY_PRINT);
print "$json\n\n";

// Update patron last activity date
print "update_patron_activity\n";
$response = $ilsws->update_patron_activity($token, $patron_id);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

/**
 * Search for patron by Alt ID
 *
 * Note: count is records per page, max 1000
 */
print "search_patron_alt_id\n";
$count = 1000;
$response = $ilsws->search_patron_alt_id($token, $alt_id, $count);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Patron authenticate
print "authenticate_patron\n";
$response = $ilsws->authenticate_patron($token, $patron_id, $password);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Search for patron by ID (barcode)
print "search_patron_id\n";
$count = 1000;
$response = $ilsws->search_patron_id($token, $patron_id, $count);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Describe the patron record
print "describe_patron\n";
$response = $ilsws->describe_patron($token);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */
print "search_patron\n";
$index = 'EMAIL';
$params = [ 
    'ct'            => '1',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'key,firstName,middleName,lastName',
    ];
$response = $ilsws->search_patron($token, $index, $email, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Create patron record JSON
print "create_patron_json overlay structure\n";
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
$json = $ilsws->create_patron_json($patron, 'overlay_fields', $token, $patron_key);
print "$json\n";

// Patron update from JSON
print "update_patron from JSON\n";
$response = $ilsws->update_patron($token, $json, $patron_key);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// See test/*.php for other examples

// EOF
