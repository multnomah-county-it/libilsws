<?php

declare(strict_types=1);

namespace Libilsws\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Libilsws\DataHandler;

class DataHandlerTest extends TestCase
{
    private DataHandler $dh;

    protected function setUp(): void
    {
        // Instantiate a fresh DataHandler before each test
        $this->dh = new DataHandler();
    }

    /**
     * @dataProvider validationRuleProvider
     */
    public function testValidate($value, string $rule, int $expectedResult): void
    {
        $result = $this->dh->validate($value, $rule);
        $this->assertSame($expectedResult, $result, "Failed on rule '{$rule}' with value '" . print_r($value, true) . "'");
    }

    /**
     * @dataProvider dateValidationProvider
     */
    public function testValidateDate(string $inputDate, string $format, string $expectedOutput): void
    {
        $result = $this->dh->validateDate($inputDate, $format);
        $this->assertSame($expectedOutput, $result, "Failed on date '{$inputDate}' with format '{$format}'");
    }

    public function testValidateThrowsExceptionOnUnknownRule(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("No validation rule for type z");
        
        $this->dh->validate('test', 'z');
    }

    /**
     * Data Provider for testValidate()
     * Format: [Input Value, Validation Rule, Expected Result (1=pass, 0=fail)]
     */
    public static function validationRuleProvider(): array
    {
        return [
            // Null check (should always return 0)
            'Null value' => [null, 'e', 0],

            // 'b': Blank validation (Assuming the `defined()` bug fix was applied)
            'Blank string passes' => ['', 'b', 1],
            'Populated string fails blank' => ['Not blank', 'b', 0],

            // 'e': Email validation
            'Valid email' => ['patron@multcolib.org', 'e', 1],
            'Invalid email missing domain' => ['patron@', 'e', 0],
            'Invalid email missing @' => ['patron_multcolib.org', 'e', 0],

            // 'i': Integer range validation
            'Valid integer inside range' => ['50', 'i:1,100', 1],
            'Valid integer at boundary' => ['1', 'i:1,100', 1],
            'Invalid integer below range' => ['0', 'i:1,100', 0],
            'Invalid integer above range' => ['101', 'i:1,100', 0],
            'Not an integer' => ['50.5', 'i:1,100', 0],

            // 'j': JSON validation
            'Valid JSON array' => ['[1, 2, 3]', 'j', 1],
            'Valid JSON object' => ['{"key": "value"}', 'j', 1],
            'Invalid JSON trailing comma' => ['{"key": "value",}', 'j', 0],
            'Invalid JSON unquoted string' => ['{key: "value"}', 'j', 0],

            // 'n': Number (float) range validation
            // Note: Your original code splits 'n' params on a period `\.` (e.g., n:1.100), 
            // even though your docblock says `n:1,999`. This test assumes the code's `\.` logic.
            'Valid float inside range' => ['3.14', 'n:1.100', 1],
            'Invalid float above range' => ['101.5', 'n:1.100', 0],
            'Not a number' => ['abc', 'n:1.100', 0],

            // 'o': Boolean (1|0) validation
            'Valid boolean 1' => ['1', 'o', 1],
            'Valid boolean 0' => ['0', 'o', 1],
            'Invalid boolean 2' => ['2', 'o', 0],
            'Invalid boolean true string' => ['true', 'o', 0],

            // 'r': Regular expression validation
            'Valid regex match' => ['OR', 'r:/^[A-Z]{2,4}$/', 1],
            'Valid regex match upper limit' => ['OREG', 'r:/^[A-Z]{2,4}$/', 1],
            'Invalid regex lowercase' => ['or', 'r:/^[A-Z]{2,4}$/', 0],
            'Invalid regex too long' => ['OREGON', 'r:/^[A-Z]{2,4}$/', 0],

            // 's': String length validation
            'Valid string length' => ['Hello', 's:10', 1],
            'Valid exact length limit' => ['1234567890', 's:10', 1],
            'Invalid string too long' => ['This is too long', 's:10', 0],

            // 'u': URL validation
            'Valid URL HTTPS' => ['https://multcolib.org', 'u', 1],
            'Valid URL HTTP' => ['http://multcolib.org', 'u', 1],
            'Invalid URL missing protocol' => ['multcolib.org', 'u', 0],
            'Invalid URL gibberish' => ['htt//bad-url', 'u', 0],

            // 'v': Value list validation
            'Valid list value first' => ['item', 'v:item|call', 1],
            'Valid list value second' => ['call', 'v:item|call', 1],
            'Invalid list value' => ['patron', 'v:item|call', 0],
            'Invalid empty list value' => ['', 'v:item|call', 0],
        ];
    }

    /**
     * Data Provider for testValidateDate()
     * Format: [Input Date String, Format String, Expected YYYY-MM-DD Output]
     */
    public static function dateValidationProvider(): array
    {
        return [
            // Exact YYYY-MM-DD matches
            'Valid YYYY-MM-DD' => ['2026-06-11', 'YYYY-MM-DD', '2026-06-11'],
            'Valid YYYY/MM/DD' => ['2026/06/11', 'YYYY/MM/DD', '2026-06-11'],
            
            // US Format MM-DD-YYYY matches
            'Valid MM-DD-YYYY' => ['06-11-2026', 'MM-DD-YYYY', '2026-06-11'],
            'Valid MM/DD/YYYY' => ['06/11/2026', 'MM/DD/YYYY', '2026-06-11'],

            // Compressed YYYYMMDD matches
            'Valid YYYYMMDD' => ['20260611', 'YYYYMMDD', '2026-06-11'],

            // Timestamp handling
            'Valid Timestamp with hyphens' => ['2026-06-11 14:30', 'YYYY-MM-DD HH:MM', '2026-06-11'],
            'Valid Timestamp with slashes' => ['2026/06/11 14:30', 'YYYY/MM/DD HH:MM', '2026-06-11'],

            // Edge cases and failures
            'Valid Leap Year' => ['2024-02-29', 'YYYY-MM-DD', '2024-02-29'],
            'Invalid Leap Year (not a leap year)' => ['2023-02-29', 'YYYY-MM-DD', ''],
            'Invalid Month' => ['2026-13-01', 'YYYY-MM-DD', ''],
            'Invalid Day' => ['2026-06-32', 'YYYY-MM-DD', ''],
            'Complete gibberish' => ['Not a date string', 'YYYY-MM-DD', ''],
            'Empty string' => ['', 'YYYY-MM-DD', ''],
            
            // Format mismatches
            'Date is valid but format requested is wrong' => ['20260611', 'YYYY-MM-DD', ''],
        ];
    }
}
