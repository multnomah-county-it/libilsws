# libilsws

PHP package to support use of the SirsiDynix Symphony Web Services API (ILSWS)

John Houser
john.houser@multco.us

# Design Goals
- Validate all inputs
- Produce clean, clear error messages
- Prevent or replace SirsiDynix error messages, which are sometimes obscure
- Provide easy, high-level functions for creating, modifying, searching for, and authenticating patrons
- Support easy reconfiguration to mirror changes to the Symphony configuration
- Allow easy adaptation by other libraries

# Public Functions

## Low-level 
These functions can be used with any valid ILSWS access point. They will
throw exceptions on error.

- connect()
- send_get ($url, $token, $params) 
- send_query ($url, $token, $query_json, $query_type)

## Convenience Functions
These functions correspond with ILSWS access points, but
they valididate all inputs and will throw exceptions
if presented with inappropriate inputs.

- patron_activity_update ($token, $patron_id)
- patron_alt_id_search ($token, $alt_id, $count)
- patron_authenticate ($token, $patron_id, $password)
- patron_id_search ($token, $patron_id, $count) 
- patron_describe ($token) 
- patron_search ($token, $index, $search, $params)
- patron_update ($token, $json, $patron_key) 

## High-level
These functions offer functionality not directly supported by
ILSWS by performing multiple queries or by combining, manipulating
or evaluating data from the Symphony system.

- authenticate_search ($token, $index, $search, $password)
- authenticate_id ($token, $patron_id, $password)
- get_patron_attributes ($token, $patron_key)
- patron_register ($patron, $token)

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
$options = [
    'rw' => 1, 
    'ct' => 10, 
    'j' => 'AND', 
    'includeFields' => 'key,barcode']
    ];

$response = $ilsws->patron_search($token, $index, $search, $options);
```

### Get Patron Attributes
```
$response = $ilsws->get_patron($token, $patron_key);
```

### Update Patron Record
```
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
    'postal_code' => '97208',
    'email' => 'john.houser@multco.us',
    'telephone' => '215-544-6941',
    'sms_phone_list' => [
        'number' => '215-544-6941',
        'countryCode => 'US',
        'bills'       => true,
        'general'     => true,
        'holds'       => true,
        'manual'      => true,
        'overdues'    => true,
        ],
    ];

$json = $ilsws->create_patron_json($patron, $patron_key);
$response = $ilsws->patron_update($token, $json, $patron_key);
```
See the libilsws.yaml.sample file for field definitions and documentation
of the YAML configuration options.

For a complete set of code examples see:
`test/test.php` and `test/register.php`

**Warning:** the test files make real changes to the configured
Symphony system. Do use on a production system without carefully
reviewing what they do!
