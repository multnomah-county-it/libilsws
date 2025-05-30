# LibILSWS

PHP package to support use of the SirsiDynix Symphony Web Services API (ILSWS) for patron functions

John Houser
john.houser@multco.us

# Design Goals
- Validate all inputs to public functions
- Produce clean, clear error messages
- Prevent or replace SirsiDynix error messages (which are sometimes obscure) as much as possible
- Provide easy, high-level functions for creating, modifying, searching for, and authenticating patrons
- Provide code examples for all functions
- Support easy reconfiguration without code changes to mirror changes to the Symphony configuration
- Support patron registrations or updates of any valid Symphony patron field without code changes
- Allow easy adaptation by other libraries

# Public Functions

## Low-level 
These functions can be used with any valid ILSWS access point. They will
throw exceptions on error.

- connect ()
- send_get($url, $token, $params) 
- send_query($url, $token, $query_json, $query_type)

## Convenience Functions
These functions correspond with ILSWS access points, but
they valididate all inputs and will throw exceptions
if presented with inappropriate inputs.

- authenticate_patron($token, $patron_id, $password)
- change_barcode($token, $patron_key, $patron_id, $options)<br>
  Options array may include: role, client_id
- change_item_library($token, $item_key, $library)
- change_patron_password($token, $json, $options)<br>
  Options array may include: role, client_id
- delete_patron($token, $patron_key)
- describe_bib($token) 
- describe_item($token) 
- describe_patron($token) 
- get_expiration($days)
- get_policy($token, $policy_key)
- search_patron($token, $index, $search, $params)
- search_patron_alt_id($token, $alt_id, $count)
- search_patron_id($token, $patron_id, $count) 
- transit_item($token, $item_key, $new_library, $working_library)
- untransit_item($token, $item_id)
- update_patron_activity($token, $patron_id)

## High-level
These functions offer functionality not directly supported by
ILSWS by performing multiple queries or by combining, manipulating
or evaluating data from the Symphony system.

- add_patron_custom_info($token, $patron_key, $key, $data)
- authenticate_patron_id($token, $patron_id, $password)
- check_duplicate($token, $index1, $search1, $index2, $search2)
- del_patron_custom_info($token, $patron_key, $key)
- email_template($patron, $to, $from, $subject, $template)
- get_bib($token, $bib_key, $field_list)
- get_bib_circ_info($token, $bib_key)
- get_bib_marc($token, $bib_key)
- get_call_number($token, $call_key, $field_list)
- get_catalog_indexes($token)
- get_hold($token, $hold_key)
- get_item($token, $item_key, $field_list)
- get_item_circ_info($token, $item_key)
- get_library_paging_list($token, $library_key)
- get_patron_attributes($token, $patron_key)
- get_patron_checkouts($token, $patron_key, $include_fields)
- get_patron_custom_info($token, $patron_key, $key, $data)
- get_patron_indexes($token)
- mod_patron_custom_info($token, $patron_key, $key, $data)
- prepare_search($terms)
- register_patron($patron, $token, $addr_num, $options)<br>
  Options array may include: role, client_id, template, subject
- reset_patron_password($token, $patron_id, $url, $email)<br>
  Optional: email
- search_authenticate($token, $index, $search, $password)
- search_bib($token, $index, $value, $params)<br>
  Params array may include: q, ct, rw, j, includeFields
- update_patron($patron, $token, $patron_key, $addr_num) 
- update_patron_activeid($token, $patron_key, $patron_id, $option)<br>
  Option may be: a, i, d
- update_phone_list($phone_list, $token, $patron_key, $options)<br>
  Options array may include: role, client_id

## Date and Telephone Number Formats
For the convenience of developers, the code library accepts
dates in the following formats, wherever a date is accepted as a
parameter: YYYYMMDD, YYYY-MM-DD, YYYY/MM/DD, MM-DD-YYYY, or 
MM/DD/YYYY.

The validation rules for telephone numbers are currently
set to expect a string of digits with no punctuation. However,
it would be easy to modify the validation rules at the top
of any public function to accept punctuation in telephone
numbers.

## Examples

### Initialize and Connect to ILSWS
```
require_once 'vendor/autoload.php';

// Initialize and load configuration from YAML configuration file
$ilsws = new Libilsws\Libilsws('./libilsws.yaml');

// Connect to ILSWS with configuration loaded from YAML file
$token = $ilsws->connect();
```

### Search for a Patron
```
/** 
 * Valid incoming params are: 
 * ct            = number of results to return,
 * rw            = row to start on (so you can page through results),
 * j             = boolean AND or OR to use with multiple search terms, and
 * includeFields = fields to return in result.
 */

$index = 'EMAIL';
$search = 'john.houser@multco.us';

// Prepare search parameters, including fields to return
$options = [
    'rw' => 1, 
    'ct' => 10, 
    'j' => 'AND', 
    'includeFields' => 'key,barcode']
    ];

// Run search
$response = $ilsws->search_patron($token, $index, $search, $options);
```

### Get Patron Attributes
```
$response = $ilsws->get_patron_attributes($token, $patron_key);
```
### Register New Patron
```
/**
 * The order of the fields doesn't matter. Not all of these are actually required. 
 * See the YAML configuration file to determine which fields are required. If an
 * email template name is included in the options array, an email will be sent to the 
 * patron. Actual template files must include a language extension (for example .en for
 * English. The system will look for template that matches the patrons language
 * preference. If one is found, it will use that, otherwise it will attempt to
 * find and use an English template.
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
    'patron_id' => '21168045918653',
    'postal_code' => '97209',
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
$options['role'] = 'STAFF';
$options['client_id'] = 'StaffClient';
$options['template'] = 'template.html.twig';
$options['subject'] = 'Welcome to the library!';

$response = $ilsws->register_patron($patron, $token, $addr_num, $options);
```

### Update Patron Record
```
// Define patron array
$patron = [
    'firstName' => 'John',
    'middleName' => 'Rad',
    'lastName' => 'Houser',
    'birthDate' => '1972-03-10',
    'home_library' => 'CEN',
    'county' => '0_MULT',
    'notice_type' => 'PHONE',
    'library_news' => 'YES',
    'friends_notices' => 'YES',
    'online_update' => 'YES',
    'street' => '925 NW Hoyt St Apt 606',
    'city_state' => 'Portland, OR',
    'patron_id' => '21168045918653',
    'postal_code' => '97208',
    'email' => 'john.houser@multco.us',
    'telephone' => '215-544-6941',
    'sms_phone' => [
        'number' => '215-544-6941',
        'countryCode => 'US',
        'bills'       => true,
        'general'     => true,
        'holds'       => true,
        'manual'      => true,
        'overdues'    => true,
        ],
    ];

$addr_num = 1;
$patron_key = '782339';

// Update the patron record
$response = $ilsws->update_patron($patron, $token, $patron_key, $addr_num);
```

### Search for bibliographic records
```
/**
 * Convert UTF-8 characters with accents to ASCII and strip unwanted characters and 
 * boolean operators from search terms
 */
$search = $ilsws->prepare_search($search);

// Prepare search parameters and choose fields to return
$params = [ 
    'ct'            => '50',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'author,title,bib{650_a,856_u},callList{callNumber,itemList{barcode,currentLocation}}'
    ];

// Run search
$response = $ilsws->search_bib($token, $index, $search, $params);
```
Notes on the includeFields parameter: 
* To include MARC data by tag, add a bib item. For example, to get the 650 tag, subfield a, add: ``bib{650_a}``
* To get a call number or any item from the item record, you must include a callList item. For example, add ``callList{callNumber}``
* To get any field from the item record, you must include an itemList entry within the callList item. For example, to get a barcode, add ``callList{itemList{barcode}}``

## More Information
See the libilsws.yaml.sample file for field definitions and documentation
of the YAML configuration options.

For a complete set of code examples see the example scripts in the ``test`` directory.

**Warning:** the test scripts may make real changes to the configured
Symphony system. **Do not use on a production system without carefully
reviewing what they do!**
