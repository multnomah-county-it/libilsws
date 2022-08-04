<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {

    print "Syntax: php $argv[0] INDEX SEARCH\n";
    exit;

} else {

    $index = $argv[1];
    $search = $argv[2];
}

// Initialize
print "Initializing\n\n";
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
print "Connecting\n\n";
$token = $ilsws->connect();

/**
 * Search for a patron. If the $params array is empty or any item is omitted,
 * default values will be supplied as shown, with the exception of the 
 * includeFields, which is configured in the libilsws.yaml file.
 */
$params = [ 
    'ct'            => '50',
    'rw'            => '1',
    'j'             => 'AND',
    'includeFields' => 'key,barcode,alternateID,firstName,middleName,lastName',
    ];
$response = $ilsws->patron_search($token, $index, $search, $params);

if ( isset($response['totalResults']) && $response['totalResults'] >= 1 ) {

    foreach ($response['result'] as $patron) {

        if ( ! empty($patron) ) {

            printf("%-12s", $patron['key']);
            printf("%-12s", $patron['fields']['barcode']);
            printf("%-12s", $patron['fields']['alternateID']);
            printf("%-12s", $patron['fields']['firstName']);
            printf("%-12s", $patron['fields']['middleName']);
            printf("%-12s", $patron['fields']['lastName']);
            print "\n";

            $confirm = readline("Do want to delete this patron (y/N)? ");

            if ( preg_match('/^[Yy]$/', $confirm) ) {

                if ( $ilsws->patron_delete($token, $patron['key']) ) {
                    print "Patron successfully deleted\n\n";
                } else {
                    print "Error deleting patron\n\n";
                }
            }
        }
    }

} else {
    print "No results\n";
}

// EOF
