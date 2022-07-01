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

# Get patron attributes example
```
require_once 'vendor/autoload.php';

// Initialize and load configuration from YAML configuration file
$ilsws = new Libilsws\Libilsws('./libilsws.yaml');

// All connection parameters supplied from configuration loaded from YAML file
$token = $ilsws->connect();

$response = $ilsws->get_patron($token, $patron_key);
```

# Create new patron example
```
require_once 'vendor/autoload.php';

// Initialize and load configuration from YAML configuration file
$ilsws = new Libilsws\Libilsws('./libilsws.yaml');

// All connection parameters supplied from configuration loaded from YAML file
$token = $ilsws->connect();

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
