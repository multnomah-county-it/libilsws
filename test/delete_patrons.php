<?php

require_once 'vendor/autoload.php';

use Libilsws\Libilsws;

if (count($argv) < 3) {
    echo "Syntax: php {$argv[0]} INDEX SEARCH\n";
    exit;
}

$index = $argv[1];
$search = $argv[2];

// Initialize
$ilsws = new Libilsws('./libilsws.yaml');

// Connect and get token
$token = $ilsws->connect();

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the
 * includeFields, which is configured in the libilsws.yaml file.
 */
$params = [
    'ct' => '50',
    'rw' => '1',
    'j' => 'AND',
    'includeFields' => 'key,barcode,alternateID,firstName,middleName,lastName',
];
$response = $ilsws->searchPatron($token, $index, $search, $params);

if (isset($response['totalResults']) && $response['totalResults'] >= 1) {
    echo "{$response['totalResults']} patrons found\n\n";

    $i = 0;
    foreach ($response['result'] as $patron) {
        $i++;
        if (!empty($patron)) {
            echo str_pad('Key', 8, ' ');
            echo str_pad('Barcode', 16, ' ');
            echo str_pad('Alt ID', 10, ' ');
            echo str_pad('First Name', 16, ' ');
            echo str_pad('Middle Name', 16, ' ');
            echo str_pad('Last Name', 16, ' ');
            echo "\n";

            echo str_pad($patron['key'], 8, ' ');
            echo str_pad($patron['fields']['barcode'], 16, ' ');
            echo str_pad($patron['fields']['alternateID'], 10, ' ');
            echo str_pad($patron['fields']['firstName'], 16, ' ');
            echo str_pad($patron['fields']['middleName'], 16, ' ');
            echo str_pad($patron['fields']['lastName'], 16, ' ');
            echo "\n";

            $confirm = readline('Do want to delete this patron (y/N)? ');

            if (preg_match('/^[Yy]$/', $confirm)) {
                if ($ilsws->deletePatron($token, (string) $patron['key'])) {
                    echo "Patron successfully deleted\n\n";
                } else {
                    echo "Error deleting patron\n\n";
                }
            }
        } else {
            echo "No patron data returned for result $i\n";
        }
    }
} else {
    echo "No results\n";
}
