<?php

declare(strict_types=1);

namespace Libilsws\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Libilsws\Libilsws;
class LibilswsTest extends TestCase
{
    private string $dummyYamlPath;

    protected function setUp(): void
    {
        // 1. Create a safe, temporary location for our dummy config
        $this->dummyYamlPath = sys_get_temp_dir() . '/dummy_ilsws_config_' . bin2hex(random_bytes(8)) . '.yaml';
        // 2. Write the minimum required configuration to pass validation
        $yamlContent = <<<YAML
ilsws:
  hostname: api.example.com
  port: 443
  webapp: symws
  username: test_user
  password: test_password
  client_id: test_client
  app_id: test_app
  timeout: 10
  connection_timeout: 5
  max_search_count: 50
  user_privilege_override: ALLOW
symphony:
  validate_catalog_fields: false
  validate_patron_indexes: false
  template_path: /tmp
  template_cache: false
smtp:
  smtp_host: smtp.example.com
  smtp_port: 587
  smtp_from: library@example.com
  smtp_fromname: Multnomah Library
debug:
  config: false
  connect: false
  query: false
  update: false
  register: false
YAML;

        file_put_contents($this->dummyYamlPath, $yamlContent);
    }

    protected function tearDown(): void
    {
        // 3. Clean up the dummy config after every test
        if (file_exists($this->dummyYamlPath)) {
            unlink($this->dummyYamlPath);
        }
    }

    public function testConstructorLoadsYamlProperly(): void
    {
        $ilsws = new Libilsws($this->dummyYamlPath);
        
        // Assert the base URL was built correctly from the config
        $this->assertEquals('https://api.example.com:443/symws', $ilsws->baseUrl);
        $this->assertEquals('test_client', $ilsws->config['ilsws']['client_id']);
    }

    public function testConstructorThrowsExceptionOnMissingFile(): void
    {
        $this->expectException(\Exception::class);
        
        // Redirect error_log to the null device to keep test output clean
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';
        $originalLog = ini_set('error_log', $nullDevice);
        
        try {
            new Libilsws('/path/to/nowhere.yaml');
        } finally {
            // Restore the original error_log setting
            if ($originalLog !== false) {
                ini_set('error_log', $originalLog);
            }
        }
    }
    
    public function testGetPatronAttributesMapsFieldsProperly(): void
    {
        // 1. Create a partial mock, overriding ONLY the sendGet method
        $ilswsMock = $this->getMockBuilder(Libilsws::class)
            ->setConstructorArgs([$this->dummyYamlPath])
            ->onlyMethods(['sendGet'])
            ->getMock();

        // 2. Define the exact fake JSON array the API *would* return
        $fakeApiResponse = [
            'key' => '1234567',
            'fields' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'barcode' => ['key' => '12345678901234'],
                'library' => ['key' => 'MAIN'],
                'standing' => ['key' => 'OK'],
                'address1' => [
                    [
                        'fields' => [
                            'code' => ['key' => 'EMAIL'],
                            'data' => 'johndoe@example.com'
                        ]
                    ],
                    [
                        'fields' => [
                            'code' => ['key' => 'CITY/STATE'],
                            'data' => 'Portland, OR'
                        ]
                    ],
                    [
                        'fields' => [
                            'code' => ['key' => 'ZIP'],
                            'data' => '97209'
                        ]
                    ]
                ]
            ]
        ];

        // 3. Program the mock to intercept sendGet and return the fake data
        // Use a valid 36-character UUID format for the fake token
        $fakeToken = '12345678-1234-1234-1234-123456789012';

        $ilswsMock->expects($this->once())
            ->method('sendGet')
            ->with(
                $this->stringContains('/user/patron/key/1234567'), // Verify the right URL was built
                $this->equalTo($fakeToken)
            )
            ->willReturn($fakeApiResponse);

        // 4. Execute the actual method using the valid fake token
        $attributes = $ilswsMock->getPatronAttributes($fakeToken, '1234567');

        // 5. Assert the method correctly extracted and mapped the complex array structure
        $this->assertEquals('John', $attributes['firstName']);
        $this->assertEquals('Doe', $attributes['lastName']);
        $this->assertEquals('MAIN', $attributes['library']);
        $this->assertEquals('johndoe@example.com', $attributes['email']);
        $this->assertEquals('Portland', $attributes['city']);
        $this->assertEquals('OR', $attributes['state']);
        $this->assertEquals('97209', $attributes['zip']);
        $this->assertEquals('John Doe', $attributes['displayName']);
    }

    public function testErrorMessageParsesJsonAndStatusCodes(): void
    {
        // Trigger autoloader to load src/Libilsws.php where APIException is defined
        $ilsws = new Libilsws($this->dummyYamlPath);
        $exception = new \Libilsws\APIException();

        // 1. Test parsing of valid JSON error format from SirsiDynix API
        $jsonError = json_encode([
            'messageList' => [
                ['message' => 'The patron barcode is already in use.']
            ]
        ]);
        $result = $exception->errorMessage($jsonError, 400);
        $this->assertEquals('HTTP 400: Bad Request - The patron barcode is already in use.', $result);

        // 2. Test plain text fallback when JSON is invalid
        $plainError = 'Raw database connection timeout';
        $result2 = $exception->errorMessage($plainError, 500);
        $this->assertEquals('HTTP 500: SirsiDynix Web Services unavailable - Raw database connection timeout', $result2);

        // 3. Test empty input fallback with unauthorized code
        $result3 = $exception->errorMessage('', 401);
        $this->assertEquals('HTTP 401: Unauthorized - ', $result3);
    }

    public function testGetExpirationCalculatesDatesCorrectly(): void
    {
        $ilsws = new Libilsws($this->dummyYamlPath);

        // 1. Test null returns null
        $this->assertNull($ilsws->getExpiration(null));

        // 2. Test 0 returns null
        $this->assertNull($ilsws->getExpiration(0));

        // 3. Test positive number of days calculates future date correctly
        $today = date('Y-m-d');
        $expectedFutureDate = date('Y-m-d', strtotime($today . " + 30 day"));
        $this->assertEquals($expectedFutureDate, $ilsws->getExpiration(30));
    }

    public function testUpdatePatronPreservesAddressFieldsFromGetPatronAttributes(): void
    {
        $sampleYamlContent = file_get_contents(__DIR__ . '/../../libilsws.yaml.sample');
        $sampleYamlContent = str_replace(
            ['HOSTNAME', 'PORT', 'WEBAPP_NAME'],
            ['api.example.com', '443', 'symws'],
            $sampleYamlContent
        );
        $tempYamlPath = sys_get_temp_dir() . '/test_sample_' . bin2hex(random_bytes(8)) . '.yaml';
        file_put_contents($tempYamlPath, $sampleYamlContent);

        try {
            $ilswsMock = $this->getMockBuilder(Libilsws::class)
                ->setConstructorArgs([$tempYamlPath])
                ->onlyMethods(['sendGet', 'sendQuery'])
                ->getMock();

            $ilswsMock->method('sendGet')->willReturn([
                'fields' => [
                    ['name' => 'library', 'type' => 'resource', 'uri' => '/policy/library'],
                    ['name' => 'profile', 'type' => 'resource', 'uri' => '/policy/patronProfile'],
                    ['name' => 'language', 'type' => 'resource', 'uri' => '/policy/patronLanguage'],
                    ['name' => 'firstName', 'type' => 'string', 'min' => 1, 'max' => 40],
                    ['name' => 'middleName', 'type' => 'string', 'min' => 0, 'max' => 40],
                    ['name' => 'lastName', 'type' => 'string', 'min' => 1, 'max' => 40],
                    ['name' => 'birthDate', 'type' => 'date'],
                    ['name' => 'category01', 'type' => 'resource', 'uri' => '/policy/patronCategory01'],
                    ['name' => 'category02', 'type' => 'resource', 'uri' => '/policy/patronCategory02'],
                    ['name' => 'category03', 'type' => 'resource', 'uri' => '/policy/patronCategory03'],
                    ['name' => 'category05', 'type' => 'resource', 'uri' => '/policy/patronCategory05'],
                    ['name' => 'category06', 'type' => 'resource', 'uri' => '/policy/patronCategory06'],
                    ['name' => 'category11', 'type' => 'string', 'min' => 0, 'max' => 10],
                    ['name' => 'category12', 'type' => 'string', 'min' => 0, 'max' => 10],
                    ['name' => 'category13', 'type' => 'string', 'min' => 0, 'max' => 10],
                ]
            ]);

            $fakeToken = '12345678-1234-1234-1234-123456789012';
            $patronKey = '1234567';

            // Input shaped like getPatronAttributes() output
            $patronInput = [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'city' => 'Portland',
                'state' => 'OR',
                'zip' => '97209',
                'email' => 'johndoe@example.com',
                'telephone' => '215-534-6820',
                'category02' => 'TEXT',
                'profile' => 'ONLINE',
            ];

            // Handle sendQuery calls for updatePatron
            $ilswsMock->expects($this->once())
                ->method('sendQuery')
                ->willReturnCallback(function ($url, $token, $data = null, $method = 'GET') use ($patronKey) {
                    if (str_contains($url, '/policy/patron/description')) {
                        return ['fields' => []];
                    }

                    if (str_contains($url, "/user/patron/key/{$patronKey}") && $method === 'PUT') {
                        $parsedData = json_decode($data, true);
                        $address1 = $parsedData['fields']['address1'] ?? [];

                        $zipField = current(array_filter($address1, fn($item) => ($item['fields']['code']['key'] ?? null) === 'ZIP'));
                        $cityStateField = current(array_filter($address1, fn($item) => ($item['fields']['code']['key'] ?? null) === 'CITY/STATE'));

                        $this->assertNotEmpty($zipField, 'ZIP field should be present in address1');
                        $this->assertEquals('97209', $zipField['fields']['data'] ?? null);
                        $this->assertNotEmpty($cityStateField, 'CITY/STATE field should be present in address1');
                        $this->assertEquals('Portland, OR', $cityStateField['fields']['data'] ?? null);

                        return ['resource' => '/user/patron', 'key' => $patronKey];
                    }

                    return [];
                });

            $response = $ilswsMock->updatePatron($patronInput, $fakeToken, $patronKey, 1);
            $this->assertEquals($patronKey, $response['key']);
        } finally {
            if (file_exists($tempYamlPath)) {
                unlink($tempYamlPath);
            }
        }
    }
}


