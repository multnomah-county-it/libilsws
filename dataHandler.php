<?php

namespace dataHandler;

/**
 * Copyright (c) Multnomah County (Oregon)
 *
 * Module for handling data mapping and validation for reports
 *
 * John Houser
 * john.houser@multco.us
 *
 * 2022-06-29
 */


class validate
{

    // Set to 1 for dubugging messages
    private $debug = 1;

    /**
     * Validates various types of incoming field data
     * Sample fields hash with validation rules:
     *
     * %fields = (
     *     Date1                      => 'd:YYYY-MM-DD',
     *     Date2                      => 'd:YYYY/MM/DD',
     *	   Date3                      => 'd:MM-DD-YYYY',
     *	   Date4                      => 'd:MM/DD/YYYY',
     *     Email                      => 'e',
     *     Timestamp1                 => 'd:YYYY/MM/DD HH:MM',
     *     Timestamp2                 => 'd:YYYY-MM-DD HH:MM',
     *     Customer_Reference         => 'i:1,999999999',      // int(8)
     *     Invoice_Memo               => 's:256',              // string(256)
     *     Posting                    => 'v:01|11',            // list('01', '11')
     *     Customer_PO_Number         => 'b',                  // must be blank
     *     Extended_Amount            => 'n:1,999',            // decimal number(000.0)
     *     );
     *
     * Returns 0 or 1
     */

    public function validate ($value, $validation_rule) {

	    $retval = 0;
        list($type, $param) = preg_split('/:/', $validation_rule, 2);

	    switch ($type) {
		    case "b":
			    // Value must be undefined
			    if ( ! defined($value) ) {
				    $retval = 1;
			    }
		        break;
		    case "d":
			    // Send to date validation routine
			    if ( $value && $this->validate_date($value, $param) ) {
				    $retval = 1;
			    }
		        break;
            case "e":
                // Validate format as email
                if ( filter_var($value, FILTER_VALIDATE_EMAIL) ) {
                    $retval = 1;
                }
                break;
		    case "i":
			    // Must be an integer of length specified
                list($min_range, $max_range) = preg_split('/,/', $param);
                if ( filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min_range, 'max_range' => $max_range]]) ) {
                    $retval = 1;
                }
                break;
		    case "n":
			    /**
                 * Number with minimum, maximum and decimal notation. You may
                 * want to send numbers representing currency through sprintf after 
                 * validation but before printing.
                 * 
			     * Zero is acceptable but undefined will return invalid.
                 */
			    list($min_range, $max_range) = preg_split('/\./', $param);
				if ( filter_var($value, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => $min_range, 'max_range' => $max_range]]) ) {
					$retval = 1;
				}
                break;
		    case "s":
			    // Check string for length
			    if ( ! defined($value) || strlen($value) <= $param ) {
				    $retval = 1;
			    }
		    case "v":
			    // Value must match one of those listed in | delimited form in the parameter.
			    if ( $value ) {
				    $params = preg_split('/\|/', $param);
				    foreach ( $params as &$test ) {	
					    if ( $test === $value ) {
						    $retval = 1;
						    endforeach;
					    }
				    }
			    }
                break;
		    default:
			    throw new Exception("No validation rule for type $type");
	    }

	    return $retval;
    }

    /**
     * Validates various date formats. Returns nothing or the valid date in 
     * YYYY-MM-DD format, which is generally what is needed for ILSWS.
     */

    private function validate_date ($date, $format) 
    {
	    $retval = '';

	    switch (true) {
		    case preg_match('/^(YYYY)([\-\/]){1}(MM)([\-\/]){1}(DD\sHH:MM)$/', $format):
			    if ( preg_match('/^\d{4}[\-\/]{1}\d{2}[\-\/]{1}\d{2}\s\d{2}:\d{2}$/', $date) ) {
				    list($year, $month, $day, $time) = preg_split('/[\-\/\s]/', $date);
				    if ( checkdate($month, $day,$year) ) {
					    $retval = $year . '-' . sprintf("%02d", $month) . '-' . sprintf("%02d", $day);
				    }
			    }
                break;
		    case preg_match('/^(YYYY)([\-\/]{1})(MM)([\-\/]{1})(DD)$/', $format):
			    if ( preg_match('/^\d{4}[\-\/]{1}\d{2}[\-\/]{1}\d{2}(\s\d{2}:\d{2}){0,1}$/', $date) ) {
				    list($year, $month, $day) = preg_split('/[\-\/]/', $date);
				    if ( checkdate($month, $day, $year) ) {
					    $retval = $year . '-' . sprintf("%02d", $month) . '-' . sprintf("%02d", $day);
				    }
			    }
		        break;
		    case preg_match('/^(MM)([\-\/]{1})(DD)([\-\/]{1})(YYYY)$/', $format):
			    if ( preg_match('/^(\d{2})([\-\/]{1})(\d{2})([\-\/]{1})(\d{4})$/', $date) ) {
				    list($year, $month, $day) = preg_split('/[\-\/]/', $date;
				    if ( checkdate($month, $day, $year) ) {
					    $retval = $year . '-' . sprintf("%02d", $month) . '-' . sprintf("%02d", $day);
				    }
			    }
		        break;
		    case preg_match('YYYYMMDD', $format):
			    if ( $date =~ /^\d{8}$/ ) {
				    my $year = substr $format, 0, 4;
				    my $month = substr $format, 5, 2;
				    my $day = substr $format, 7, 2;
				    if ( check_date($year, $month, $day) ) {
					    $retval = $year . '-' . sprintf("%02d", $month) . '-' . sprintf("%02d", $day);
				    }
			    }
		        break;
		    default:
			    throw new Exception("Unsupported date format: $format");
	    }

	    return $retval;
    }
}

