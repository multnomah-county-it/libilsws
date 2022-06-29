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

# Example
```
require_once 'vendor/autoload.php';

// Initialize and load configuration from libilsws.yaml
$ilsws = new Libilsws\Libilsws();

// All connection parameters supplied from configuration loaded from YAML file
$token = $ilsws->connect();

$response = $ilsws->get_patron($token, $patron_key);
```

For a complete set of examples see:
`test/test.php`
