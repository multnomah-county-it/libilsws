<?php

require_once 'vendor/autoload.php';
error_reporting(E_ALL ^ E_WARNING);

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
$ilsws = new Libilsws\Libilsws();

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

// Typical new patron record. For complete description see the output from patron_describe
$json = 
"{
  resource: '/user/patron',
  key: '591418',
  fields: {
    barcode: '21168045918653',
    birthDate: '1962-03-07',
    firstName: 'John',
    language: { resource: '/policy/language', key: 'ENGLISH' },
    lastName: 'Houser',
    library: { resource: '/policy/library', key: 'CEN' },
    middleName: 'Clark',
    privilegeExpiresDate: null,
    profile: { resource: '/policy/userProfile', key: '0_MULT' },
    category01: { resource: '/policy/patronCategory01', key: '0_MULT' },
    category02: { resource: '/policy/patronCategory02', key: 'PHONE' },
    category05: { resource: '/policy/patronCategory05', key: 'YES' },
    category06: { resource: '/policy/patronCategory06', key: 'YES' },
    category11: { resource: '/policy/patronCategory11', key: 'YES' },
    address1: [
      {
        resource: '/user/patron/address1',
        key: '13',
        fields: {
          code: { resource: '/policy/patronAddress1', key: 'STREET' },
          data: '925 NW Hoyt St Apt 406'
        }
      },
      {
        resource: '/user/patron/address1',
        key: '2',
        fields: {
          code: { resource: '/policy/patronAddress1', key: 'CITY/STATE' },
          data: 'Portland, OR'
        }
      },
      {
        resource: '/user/patron/address1',
        key: '12',
        fields: {
          code: { resource: '/policy/patronAddress1', key: 'ZIP' },
          data: '97209'
        }
      },
      {
        resource: '/user/patron/address1',
        key: '11',
        fields: {
          code: { resource: '/policy/patronAddress1', key: 'EMAIL' },
          data: 'johnchouser@gmail.com'
        }
      },
      {
        resource: '/user/patron/address1',
        key: '7',
        fields: {
          code: { resource: '/policy/patronAddress1', key: 'PHONE' },
          data: '215-534-6821'
        }
      }
    ]
  }
}";

/**
 * Code example to create new patron record
 *
 * $response = $ilsws->patron_create($token, $json);
 * $json = json_encode($response, JSON_PRETTY_PRINT);
 * print "$json\n\n";
 */

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

