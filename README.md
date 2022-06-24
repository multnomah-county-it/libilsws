# libilsws

PHP package to support use of the SirsiDynix Symphony Web Services API (ILSWS)

John Houser
john.houser@multco.us

~~~
require_once 'vendor/autoload.php';

$ilsws = new Libilsws\Libilsws();

$response = $ilsws->authenticate_patron($username, $password);
~~~
