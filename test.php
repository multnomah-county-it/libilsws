<?php

require_once 'vendor/autoload.php';

if ( ! $argv[1] || ! $argv[2] ) {
    print "Syntax: php $argv[0] EMAIL TELEPHONE BARCODE ALT_ID PASSWORD\n";
    exit;
}

$email = $argv[1];
$telepone = $argv[2];
$barcode = $argv[3];
$alt_id = $argv[4];
$password = $argv[5];

// Initialize
$ilsws = new Libilsws\Libilsws();

// Connect and get token
$token = $ilsws->connect();

// Get patron attributes
$attributes = $ilsws->get_patron($token);
print $attributes . "\n";

// Update patron last activity date
$patron_id = $barcode;
$response = $ilsws->patron_activity_update($token, $patron_id);
print $response . "\n";

// Search for patron by Alt ID
// Count is records per page, max 1000
$count = '1000';
$response = $ilsws->patron_alt_id_search($token, $alt_id, $count)
print $response . "\n";

// Patron authenticate
$response = $ilsws->patron_authenticate($token, $patron_id, $password);
print $response . "\n";

// Search for patron by barcode
$count = '1000';
$response = $ilsws->patron_barcode_search($token, $patron_id, $count);
print $response . "\n";

// Create a new patron record. Have fun with this one.
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
$response = $ilsws->patron_create($token, $json);
print $response . "\n";

// Describe the patron record
$response = $ilsws->patron_describe($token);
print $response . "\n";

/*
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied.
 */
$index = 'EMAIL';
$value = $email;
$params = array(
    q  => "$index:$value",
    ct => $params->ct ?: '1000',
    rw => $params->rw ?: '1',
    j  => $params->j ?: 'AND',
    include_fields => 'key,firstName,lastName'
    );
$response = $ilsws->patron_search($token, $index, $value, $params);
print $response . "\n";

/* Update a patron record. Note that the data structure is the same
 * as for updating a patron. So to update, you generally have to retrieve
 * the entire structure, modify it, then update.
 */
$json = "{
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
          data: '215-534-6820'
        }
      }
    ]
  }
}";
$response = $ilsws->patron_update($token, $json, $patron_key);
print $response . "\n";

