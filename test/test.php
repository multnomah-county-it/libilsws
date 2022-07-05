<?php

require_once 'vendor/autoload.php';

if ( ! $argv ) {
    print "Syntax: php $argv[0] EMAIL TELEPHONE BARCODE ALT_ID PASSWORD\n";
    exit;
}

$email = $argv[1];
$telephone = $argv[2];
$patron_id = $argv[3];
$alt_id = $argv[4];
$password = $argv[5];

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
$patron_key = $ilsws->authenticate_patron_id($token, $patron_id, $password);
print "patron_id: $patron_id\n\n";

// Get patron attributes
print "authenticate_search\n";
$patron_key = $ilsws->authenticate_search($token, 'PHONE', $telephone, $password);
print "patron_id: $patron_key\n\n";

print "get_patron\n";
if ( $patron_key ) {
    $attributes = $ilsws->get_patron_attributes($token, $patron_key);
    $json = json_encode($attributes, JSON_PRETTY_PRINT);
    print "$json\n\n";
}

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
$params = array(
    'ct'            => '1000',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'key,firstName,middleName,lastName',
    );
$response = $ilsws->patron_search($token, $index, $email, $params);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

// Create patron record JSON
print "create_patron_json new record\n";
$patron = array(
    'firstName' => 'John',
    'lastName' => 'Houser',
    'birthDate' => '1962-03-07',
    'home_library' => 'CEN',
    'middleName' => 'Clark',
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
// Second parameter is the $patron_key. Set to 0 to create new record.
$json = $ilsws->create_patron_json($patron, 0);
print "$json\n";

// Supply a patron key to get JSON to modify a record
print "create_patron_json overlay record\n";
$patron = array(
    'firstName' => 'Bogus',
    'middleName' => 'T',
    'lastName' => 'Bogart',
    'birthDate' => '1962-03-07',
    'home_library' => 'CEN',
    'notice_type' => 'PHONE',
    'library_news' => 'YES',
    'friends_notices' => 'YES',
    'street' => '925 NW Hoyt St Apt 406',
    'city_state' => 'Portland, OR',
    'postal_code' => '97209',
    'email' => 'johnchouser@gmail.com',
    'telephone' => '215-534-6821',
    );
$patron_key = 591418;
$json = $ilsws->create_patron_json($patron, $patron_key);
print "$json\n";

/**
 * Code example to create new patron record
 * from a patron JSON object, as created by
 * create_patron_json
 */
$response = $ilsws->patron_create($token, $json);
$json = json_encode($response, JSON_PRETTY_PRINT);
print "$json\n\n";

/** 
 * Code example to update a patron record. Note that the data structure is the same
 * as for updating a patron. So to update, you generally have to retrieve
 * the entire structure for a given patron, modify it, then update.
 * 
 * $patron_key = '591418';
 * $includeFields = "barcode,birthDate,firstName,language,lastName,library,middleName,privilegeExpiresDate,profile,category01,category02,category05,category06,category11,address1";
 * $patron = $ilsws->send_get("$this->base_url/user/patron/key/$patron_key", $token, array('includeFields' => $include_str));
 * 
 * [Modify the data structure]
 * 
 * $response = $ilsws->patron_update($token, $json, $patron_key);
 * $json = json_encode($response, JSON_PRETTY_PRINT);
 * print "$json\n\n";
 */

