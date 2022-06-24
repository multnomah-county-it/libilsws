<?php

namespace Libilsws;

/**
 *
 * Multnomah County Library ILSWS API Support
 *
 * Copyright (c) 2022 Multnomah County (Oregon)
 * 
 * John Houser
 * john.houser@multco.us
 *
 */

use Symfony\Component\Yaml\Yaml;
use Curl\Curl;

class Libilsws
{
    /**
     * Turn this on to see various debug messages
     */
    private $debug = 1;

    /**
     * The ILSWS host we should connect to.
     */
    private $hostname;

    /**
     * The ILSWS port we should connect on.
     */
    private $port;

    /**
     * The username we should connect to the database with.
     */
    private $username;

    /**
     * The password we should connect to the database with.
     */
    private $password;

    /**
     * The ILSWS webapp
     */
    private $webapp;

    /**
     * The ILSWS app_id
     */
    private $app_id;

    /**
     * The ILSWS client_id
     */
    private $client_id;

    /**
     * The ILSWS connection timeout
     */
    private $timeout;

    /**
     * The ILSWS max search count
     */
    private $max_search_count;

    /**
     * Constructor for this class
     */
    public function __construct()
    {
        $config = Yaml::parseFile('libilsws.yaml');

        $this->hostname         = $config['ilsws']['hostname'];
        $this->port             = $config['ilsws']['port'];
        $this->username         = $config['ilsws']['username'];
        $this->password         = $config['ilsws']['password'];
        $this->webapp           = $config['ilsws']['webapp'];
        $this->app_id           = $config['ilsws']['app_id'];
        $this->client_id        = $config['ilsws']['client_id'];
        $this->timeout          = $config['ilsws']['timeout'];
        $this->max_search_count = $config['ilsws']['max_search_count'];
    }

    /**
     * Connect to ILSWS
     *
     * @return x-sirs-sessionToken
     */
    private function connect()
    {
        try {
            $url = "https://$this->hostname:$this->port/$this->webapp";
            $action = "rest/security/loginUser";
            $params = "client_id=$this->client_id&login=$this->username&password=$this->password";

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                "SD-Originating-App-ID: $this->app_id",
                "x-sirs-clientID: $this->client_id"
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "$url/$action?$params");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $json = curl_exec($ch);

            $response = json_decode($json, true);
            $token = $response['sessionToken'];

            curl_close($ch);

        } catch (\Exception $e) {
            // Obfuscate the password if it's part of the dsn
            $obfuscated_url =  preg_replace('/(password)=(.*?([;]|$))/', '${1}=***', "$url/$action?$params");

            throw new Exception('mclilsws:' . $this->authId . ': - Could not connect to ILSWS: \'' .  $obfuscated_url . '\': ' . $e->getMessage());
        }

        return $token;
    }

    /**
     * Use an email, telephone or other value to retrieve a user barcode (ID)
     * and then see if we can authenticate with that barcode and the user password.
     *
     * Should return a patron key or 0. On error,it should throw an exception. 
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $token  The session token returned by ILSWS.
     * @param string $index  The Symphony index to search in for the user.
     * @param string $search  The username the user entered.
     * @param string $password  The password the user entered.
     * @return string $barcode The user's barcode (ID).
     */
    protected function authenticate_search($token, $index, $search, $password)
    {
        assert(is_string($token));
        assert(is_string($index));
        assert(is_string($search));
        assert(is_string($password));

        try {

            $url = "https://$this->hostname:$this->port/$this->webapp";
            $action = "/user/patron/search";
            $post_data = array("q=$index:$search", 'rw=1', "ct=$this->max_search_count", 'j=AND', 'includeFields=barcode');
            $params = implode('&', $post_data);

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                "SD-Originating-App-ID: $this->app_id",
                "x-sirs-clientID: $this->client_id",
                "x-sirs-sessionToken: $token",
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "$url/$action?$params");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $json = curl_exec($ch);

            if ( $this->debug ) {
                print "$json\n";
            }
            
            $response = json_decode($json, true);

            curl_close($ch);

        } catch (\Exception $e) {
            throw new \Exception('ILSWS search query failed: ' . $e->getMessage());
        }

        /**
         * Symphony Web Services' with return nulls for records that have been deleted 
         * but still count them in the results. So, you can't trust the totalResults count 
         * match the number of actual records returned, and you have to loop through all 
         * possible result objects to see if there is data.
         */
        $patron_key = 0;
        $count = 0;
        if ( $response['totalResults'] > 0 && $response['totalResults'] <= $this->max_search_count ) {
            for ($i = 0; $i <= $response['totalResults'] - 1; $i++) {
                if ( isset($response['result'][$i]['fields']['barcode']) ) {
                    $barcode = $response['result'][$i]['fields']['barcode'];
                    assert(is_string($barcode));
                    $patron_key = $this->authenticate_barcode($token, $barcode, $password);
                    if ( $patron_key ) {
                        $count++;
                    }
                }
                if ( $count > 1 ) {
                    $patron_key = 0;
                    break;
                }
            }
        }

        return $patron_key;
    }

    /**
     * Authenticate via barcode and password.
     *
     * On a successful login, this function should return the user's patron key. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a \SimpleSAML\Error\Error('WRONGUSERPASS') should be thrown.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $username  The username the user wrote.
     * @param string $password  The password the user wrote.
     * @return string $patron_key The user's patron key.
     */
    protected function authenticate_barcode($token, $barcode, $password)
    {
        assert(is_string($token));
        assert(is_string($barcode));
        assert(is_string($password));

        $patron_key = 0;
 
        try {

            $url = "https://$this->hostname:$this->port/$this->webapp";
            $action = "/user/patron/authenticate";
            $post_data = json_encode( array('barcode' => $barcode, 'password' => $password) );

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                "SD-Originating-App-ID: $this->app_id",
                "x-sirs-clientID: $this->client_id",
                "x-sirs-sessionToken: $token",
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "$url/$action");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

            $json = curl_exec($ch);

            if ( $this->debug ) {
                print "$json\n";
            }

            $response = json_decode($json, true);
            
            curl_close($ch);

        } catch (\Exception $e) {
            throw new \Exception('ILSWS barcode authentication query failed: ' . $e->getMessage());
        }

        if ( isset($response['patronKey']) ) {
            $patron_key = $response['patronKey'];
        }

        return $patron_key;
    }

    /**
     * Attempt to retrieve patron attributes.
     *
     * On a successful login, this function should return the users attributes. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a \SimpleSAML\Error\Error('WRONGUSERPASS') should be thrown.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $username  The username the user wrote.
     * @param string $password  The password the user wrote.
     * @return array @attributes Associative array with the users attributes.
     */
    public function authenticate_patron ($username, $password)
    {
        assert(is_string($username));
        assert(is_string($password));

        $token = $this->connect();
        assert(is_string($token));
 
        // We support authentication by barcode and pin, telephone and pin, or email address and pin
        $patron_key = 0;

        if ( filter_var($username, FILTER_VALIDATE_EMAIL) ) {

            # The username looks like an email
            $patron_key = $this->authenticate_search($token, 'EMAIL', $username, $password);

        } elseif ( preg_match("/^\d{6,}$/", $username) ) {

            # Assume the username is a barcode
            $patron_key = $this->authenticate_barcode($token, $username, $password);

            if ( ! $patron_key ) {

                # Maybe the username is a telephone number without hyphens?
                $patron_key = $this->authenticate_search($token, 'PHONE', $username, $password);
            }

        } elseif ( preg_match("/^\d{3}\-\d{3}\-\d{4}$/", $username) ) {

            # This looks like a telephone number
            $patron_key = $this->authenticate_search($token, 'PHONE', $username, $password);
        }

        $attributes = [];
        if ( $patron_key ) {

            assert(is_string($patron_key));

            // Patron is authenticated. Now try to retrieve patron attributes.
            if ( $this->debug ) {
                print "ILSWS authenticated patron: $patron_key";
            }

            $include_fields = [
                'lastName',
                'firstName',
                'middleName',
                'barcode',
                'library',
                'profile',
                'language',
                'lastActivityDate',
                'address1',
                'category01',
                'category02',
                'category03',
                'standing'
            ];

            $include_str = implode(',', $include_fields);

            try {

                $url = "https://$this->hostname:$this->port/$this->webapp";
                $action = "/user/patron/key";

                $headers = [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    "SD-Originating-App-ID: $this->app_id",
                    "x-sirs-clientID: $this->client_id",
                    "x-sirs-sessionToken: $token",
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "$url/$action/$patron_key?includeFields=$include_str");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $json = curl_exec($ch);

                if ( $this->debug ) {
                    print "$json\n";
                }

                $response = json_decode($json, true);

                curl_close($ch);

            } catch (\Exception $e) {
                throw new \Exception('Could not retrieve attributes from ILSWS: ' . $e->getMessage());
            }

            // Extract patron attributes from the ILSWS response and assign to $attributes.
            if ( isset($response['key']) ) {
                foreach ( $include_fields as &$field ) {

                    if ( $field == 'address1' ) {
                        if ( isset($response['fields']['address1']) ) {
                            foreach ($response['fields']['address1'] as &$i) {
                                if ( $i['fields']['code']['key'] == 'EMAIL' ) {
                                    $attributes['email'][] = $i['fields']['data'];
                                } elseif ( $i['fields']['code']['key'] == 'CITY/STATE' ) {
                                    $parts = preg_split("/,\s*/", $i['fields']['data']);
                                    $attributes['city'][] = $parts[0];
                                    $attributes['state'][] = $parts[1];
                                } elseif ( $i['fields']['code']['key'] == 'ZIP' ) {
                                    $attributes['zip'][] = $i['fields']['data'];
                                } elseif ( $i['fields']['code']['key'] == 'PHONE' ) {
                                    $attributes['telephone'][] = $i['fields']['data'];
                                }
                            }
                        }
                    } elseif ( isset($response['fields'][$field]['key']) ) {
                        $attributes[$field][] = $response['fields'][$field]['key'];
                    } elseif ( isset($response['fields'][$field]) ) {
                        $attributes[$field][] = $response['fields'][$field];
                    } else {
                        $attributes[$field][] = '';
                    }
                }
            }
            // Generate a displayName
            if ( isset($response['fields']['lastName']) && isset($response['fields']['firstName']) ) {
                $attributes['displayName'][] = $response['fields']['firstName'] . ' ' . $response['fields']['lastName'];
            }
            // Generate a commonName
            if ( isset($response['fields']['lastName']) && isset($response['fields']['firstName']) ) {
                if ( isset($response['fields']['middleName']) ) {
                    $attributes['commonName'][] = $response['fields']['lastName'] 
                      . ', ' 
                      . $response['fields']['firstName'] 
                      . ' ' 
                      . $response['fields']['middleName'];
                } else {
                    $attributes['commonName'][] = $response['fields']['lastName'] 
                      . ', ' 
                      . $response['fields']['firstName'];
                }
            }

            if ( $this->debug ) {
                print 'ILSWS attributes returned: ' . implode(',', array_keys($attributes)) . "\n";
            }

        } else if ( $this->debug ) {
            print "Invalid username or password\n";
        }

        return $attributes;
    }
}
