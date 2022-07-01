# libilsws

PHP package to support use of the SirsiDynix Symphony Web Services API (ILSWS)

John Houser
john.houser@multco.us

# Public Functions

## Low level 
These functions can be used with any valid ILSWS access point. They will
throw exceptions on error.

- connect()
- send_get ($url, $token, $params) 
- send_query ($url, $token, $query_json, $query_type)

## Convenience functions
These functions correspond with ILSWS access points, but
they valididate all inputs and will throw exceptions
if presented with inappropriate inputs.

- patron_activity_update ($token, $patron_id)
- patron_alt_id_search ($token, $alt_id, $count)
- patron_authenticate ($token, $patron_id, $password)
- patron_id_search ($token, $patron_id, $count) 
- patron_create ($token, $json) 
- patron_describe ($token) 
- patron_search ($token, $index, $search, $params)
- patron_update ($token, $json, $patron_key) 

## High level
These functions offer functionality not directly supported by
ILSWS by performing multiple queries or by combining, manipulating
or evaluating data from the Symphony system.

- authenticate_search ($token, $index, $search, $password)
- authenticate_id ($token, $patron_id, $password)
- get_patron_attributes ($token, $patron_key)
- create_patron_json ($patron, $patron_key)

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

$response = $ilsws->patron_search($token, 'EMAIL', 'john.houser@multco.us', ['rw' => 1, 'ct' => 10, 'j' => 'AND', 'includeFields' => 'key,barcode']);
```

### Get patron attributes example
```
$response = $ilsws->get_patron($token, $patron_key);
```

### Create new patron example
```
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

/**
 * Second parameter is the $patron_key. Set to 0 to create new record. 
 * Set to an existing key to modify the patron record.
 */

$json = $ilsws->create_patron_json($patron, 0);
```
For a complete set of examples see:
`test/test.php`
