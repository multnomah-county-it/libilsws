<?php

declare(strict_types=1);

namespace Libilsws;

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
class DataHandler
{
    /**
     * Validates various types of incoming field data.
     * Sample fields hash with validation rules:
     *
     * $fields = [
     * 'blank'      => 'b',                           // must be blank
     * 'boolean'    => 'o',                           // 1|0
     * 'date1'      => 'd:YYYY-MM-DD',
     * 'date2'      => 'd:YYYY/MM/DD',
     * 'date3'      => 'd:MM-DD-YYYY',
     * 'date4'      => 'd:MM/DD/YYYY',
     * 'email'      => 'e',
     * 'timestamp1' => 'd:YYYY/MM/DD HH:MM',
     * 'timestamp2' => 'd:YYYY-MM-DD HH:MM',
     * 'integer'    => 'i:1,99999999',                // integer between 1 and 99999999
     * 'JSON'       => 'j',                           // JSON
     * 'number'     => 'n:1,999',                     // decimal number between 1 and 999
     * 'regex'      => 'r:/^[A-Z]{2,4}$/',            // Regular expression pattern
     * 'string'     => 's:256',                       // string of length <= 256
     * 'url'        => 'u',                           // URL
     * 'list'       => 'v:01|11',                     // list('01', '11')
     * ];
     *
     * @param mixed  $value           String to validation.
     * @param string $validationRule Validation rule to apply.
     * @return int Returns 1 if the validation is successful, 0 if it is not.
     * @throws \Exception If no validation rule for the given type exists.
     */
    public function validate($value, string $validationRule): int
    {
        $retval = 0;

        // A null value always returns 0
        if ($value === null) {
            return 0;
        }

        if (strlen($validationRule) > 1) {
            list($type, $param) = preg_split('/:/', $validationRule, 2);
        } else {
            $type = $validationRule;
            $param = '';
        }

        switch ($type) {
            case 'b':
                // FIXED: Check if the value is actually empty, null, or a blank string
                if (is_null($value) || trim((string) $value) === '') {
                    $retval = 1;
                }
                break;
            case 'd':
                // Send to date validation routine
                if ($value && $this->validateDate($value, $param)) {
                    $retval = 1;
                }
                break;
            case 'e':
                // Validate format as email
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $retval = 1;
                }
                break;
            case 'i':
                // Must be an integer of length specified
                if (preg_match('/^-?\d+$/', (string) $value)) {
                    list($minRange, $maxRange) = preg_split('/,/', $param);
                    if (filter_var((int) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => (int) $minRange, 'max_range' => (int) $maxRange]])) {
                        $retval = 1;
                    }
                }
                break;
            case 'j':
                // Must be valid JSON
                if (!empty($value)) {
                    json_decode((string) $value);
                    if (json_last_error() == JSON_ERROR_NONE) {
                        $retval = 1;
                    }
                }
                break;
            case 'n':
                /**
                 * Number with minimum, maximum and decimal notation. You may
                 * want to send numbers representing currency through sprintf after
                 * validation but before printing.
                 *
                 * Zero is acceptable but undefined will return invalid.
                 */
                list($minRange, $maxRange) = preg_split('/\./', $param);
                if (filter_var((float) $value, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => (float) $minRange, 'max_range' => (float) $maxRange]])) {
                    $retval = 1;
                }
                break;
            case 'o':
                // 1|0
                if ($value == 1 || $value == 0) {
                    $retval = 1;
                }
                break;
            case 'r':
                /**
                 * Regular expression match
                 */
                preg_match($param, (string) $value, $matches);
                if (count($matches) >= 1) {
                    $retval = 1;
                }
                break;
            case 's':
                // Removed defined() and check for string length properly
                if ($value === null || strlen((string) $value) <= (int) $param) {
                    $retval = 1;
                }
                break;
            case 'u':
                // Check for valid URL
                if (filter_var($value, FILTER_VALIDATE_URL)) {
                    $retval = 1;
                }
                break;
            case 'v':
                // Value must match one of those listed in | delimited form in the parameter.
                if ($value) {
                    $params = preg_split('/\|/', $param);
                    foreach ($params as $test) {
                        if ($test === $value) {
                            $retval = 1;
                            break 1;
                        }
                    }
                }
                break;
            default:
                throw new \Exception("No validation rule for type {$type}");
        }

        return $retval;
    }

    /**
     * Validates various date or timestamp formats. Returns nothing or the
     * valid date in YYYY-MM-DD format, which is generally what is needed
     * for ILSWS.
     *
     * @param string $date   Date in any supported format.
     * @param string $format Format of date (YYYY-MM-DD HH:MM, YYYY/MM/DD HH:MM, YYYY-MM-DD,
     * YYYY/MM/DD, MM-DD-YYYY, MM/DD/YYYY, YYYYMMDD).
     * @return string Returns the date in YYYY-MM-DD format if the validation is successful,
     * or an empty string if validation fails.
     */
    public function validateDate(string $date, string $format): string
    {
        // Normalize slashes to hyphens in both the date and the format for uniform matching
        $normalizedDate = str_replace('/', '-', $date);
        $normalizedFormat = str_replace('/', '-', $format);
        
        // Map the normalized custom formats to their strict PHP DateTime equivalents
        $formatMap = [
            'YYYY-MM-DD HH:MM' => 'Y-m-d H:i',
            'YYYY-MM-DD'       => 'Y-m-d',
            'MM-DD-YYYY'       => 'm-d-Y',
            'YYYYMMDD'         => 'Ymd',
        ];

        // If the requested format isn't recognized at all, fail immediately
        if (!isset($formatMap[$normalizedFormat])) {
            return '';
        }

        $phpFmt = $formatMap[$normalizedFormat];

        $dt = \DateTime::createFromFormat($phpFmt, $normalizedDate);
        
        // Ensure the date parses correctly AND strictly matches the expected format
        if ($dt !== false && $dt->format($phpFmt) === $normalizedDate) {
            return $dt->format('Y-m-d');
        }

        return '';
    }
}
