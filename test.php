<?php

require_once 'vendor/autoload.php';

if ( ! $argv[1] || ! $argv[2] ) {
    print "Syntax: php $argv[0] USERNAME PASSWORD\n";
    exit;
}

$username = $argv[1];
$password = $argv[2];

$ilsws = new Libilsws\Libilsws();

$response = $ilsws->authenticate_patron($username, $password);

print var_dump($response) . "\n";
