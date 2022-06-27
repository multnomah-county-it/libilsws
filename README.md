# libilsws

PHP package to support use of the SirsiDynix Symphony Web Services API (ILSWS)

John Houser
john.houser@multco.us

# Public Functions
- connect()
- send_get ($URL, $token, $params) 
- send_post ($URL, $token, $query_json, $query_type)
- get_patron ($username, $password, $token)
- patron_authenticate ($token, $id, $pin)
- patron_describe ($token) 
- patron_search ($token, $index, $value, $params)
- patron_alt_id_search ($token, $value, $count)
- patron_barcode_search ($token, $value, $count) 
- patron_create ($token, $json) 
- patron_update ($token, $json, $key) 
- activity_update ($token, $json)

# Example
~~~
require_once 'vendor/autoload.php';

$ilsws = new Libilsws\Libilsws();

$response = $ilsws->authenticate_patron($username, $password);
~~~
