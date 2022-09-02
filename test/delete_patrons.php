<?php

require_once 'vendor/autoload.php';

if ( count($argv) < 3 ) {
    print "Syntax: php $argv[0] INDEX SEARCH\n";
    exit 0;
}

$index = $argv[1];
$search = $argv[2];

// Initialize
$ilsws = new Libilsws\Libilsws("./libilsws.yaml");

// Connect and get token
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
$response = $ilsws->search_patron($token, $index, $search, $params);

if ( isset($response['totalResults']) && $response['totalResults'] >= 1 ) {

    print $response['totalResults'] . " patrons found\n\n";

    foreach ($response['result'] as $patron) {

        if ( ! empty($patron) ) {

            print str_pad("Key", 8, ' ');
            print str_pad("Barcode", 16, ' ');
            print str_pad("Alt ID", 10, ' ');
            print str_pad("First Name", 16, ' ');
            print str_pad("Middle Name", 16, ' ');
            print str_pad("Last Name", 16, ' ');
            print "\n";

            print str_pad($patron['key'], 8, ' ');
            print str_pad($patron['fields']['barcode'], 16, ' ');
            print str_pad($patron['fields']['alternateID'], 10, ' ');
            print str_pad($patron['fields']['firstName'], 16, ' ');
            print str_pad($patron['fields']['middleName'], 16, ' ');
            print str_pad($patron['fields']['lastName'], 16, ' ');
            print "\n";

            $confirm = readline("Do want to delete this patron (y/N)? ");

            if ( preg_match('/^[Yy]$/', $confirm) ) {

                if ( $ilsws->delete_patron($token, $patron['key']) ) {
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
