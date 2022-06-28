# libilsws

PHP package to support use of the SirsiDynix Symphony Web Services API (ILSWS)

John Houser
john.houser@multco.us

# Public Functions

## Low level (can be used with any ILSWS access point)
- connect()
- send_get ($url, $token, $params) 
- send_query ($url, $token, $query_json, $query_type)

## Convenience functions
- patron_activity_update ($token, $patron_id)
- patron_alt_id_search ($token, $value, $count)
- patron_authenticate ($token, $id, $patron_id)
- patron_barcode_search ($token, $value, $count) 
- patron_create ($token, $json) 
- patron_describe ($token) 
- patron_search ($token, $index, $value, $params)
- patron_update ($token, $json, $patron_key) 

## High level
- authenticate_search($token, $index, $search, $password)
- authenticate_id($token, $patron_id, $password)
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
`test.php`
