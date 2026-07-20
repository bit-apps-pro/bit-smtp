<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class MailSettingsSanitizerTest extends BaseUnitTestCase
{
    public function testDropsUnknownTopLevelKeys(): void
    {
        $input           = $this->baseV2();
        $input['foo']    = 'bar';
        $result          = MailSettingsSanitizer::sanitize($input);

        $this->assertArrayNotHasKey('foo', $result);
    }

    public function testCoercesSchemaVersion(): void
    {
        $input                   = $this->baseV2();
        $input['schema_version'] = '2';
        $result                  = MailSettingsSanitizer::sanitize($input);

        $this->assertSame(2, $result['schema_version']);
        $this->assertIsInt($result['schema_version']);
    }

    public function testCoercesEnabled(): void
    {
        $input            = $this->baseV2();
        $input['enabled'] = '1';
        $result           = MailSettingsSanitizer::sanitize($input);

        $this->assertTrue($result['enabled']);
        $this->assertIsBool($result['enabled']);
    }

    public function testCoercesConnectionPort(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['port' => '465'],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame(465, $result['connections'][0]['settings']['port']);
        $this->assertIsInt($result['connections'][0]['settings']['port']);
    }

    public function testTrimsStringFields(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '  test@example.com  ',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('test@example.com', $result['connections'][0]['fromEmail']);
    }

    public function testDropsUnknownFeatureKeys(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['logging' => [], 'unknown_key' => []];
        $result            = MailSettingsSanitizer::sanitize($input);

        $this->assertArrayNotHasKey('unknown_key', $result['features']);
        $this->assertArrayHasKey('logging', $result['features']);
    }

    public function testFallbackConnectionIdsEnsuredArray(): void
    {
        $input = $this->baseV2();
        unset($input['fallback_connection_ids']);
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame([], $result['fallback_connection_ids']);
    }

    public function testCredentialWhitelistStripsUnknownKeysAndPreservesValue(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => [
                    'password' => [
                        'source'  => 'database',
                        'value'   => 'secret123',
                        'bogus'   => 'evil_payload',
                        'another' => ['nested' => 'data'],
                    ],
                ],
            ],
        ];
        $result      = MailSettingsSanitizer::sanitize($input);
        $credentials = $result['connections'][0]['credentials'];

        $this->assertSame('database', $credentials['password']['source']);
        $this->assertSame('secret123', $credentials['password']['value']);
        $this->assertArrayNotHasKey('bogus', $credentials['password']);
        $this->assertArrayNotHasKey('another', $credentials['password']);
    }

    public function testCredentialAbsentDefaultsToEmptyArray(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame([], $result['connections'][0]['credentials']);
    }

    public function testEncryptionEmptyStringNormalizedToNone(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['encryption' => ''],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('none', $result['connections'][0]['settings']['encryption']);
    }

    public function testEncryptionAbsentNormalizedToNone(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('none', $result['connections'][0]['settings']['encryption']);
    }

    public function testEncryptionPresentValuePreserved(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['encryption' => 'tls'],
                'credentials'  => [],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame('tls', $result['connections'][0]['settings']['encryption']);
    }

    public function testPreservesOAuthClientIdAndTokenExpiry(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'gmail',
                'kind'         => 'api',
                'name'         => 'Gmail',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['client_id' => '  cid.apps.googleusercontent.com ', 'token_expires_at' => '1700000000'],
                'credentials'  => [],
            ],
        ];
        $settings = MailSettingsSanitizer::sanitize($input)['connections'][0]['settings'];

        $this->assertSame('cid.apps.googleusercontent.com', $settings['client_id']);
        $this->assertSame(1700000000, $settings['token_expires_at']);
        $this->assertIsInt($settings['token_expires_at']);
    }

    public function testPreservesArbitraryNonSecretSettingKeysAsTrimmedStrings(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'amazon_ses',
                'kind'         => 'api',
                'name'         => 'SES',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['access_key' => '  AKIAEXAMPLE  ', 'region' => 'eu-central-1'],
                'credentials'  => [],
            ],
        ];
        $settings = MailSettingsSanitizer::sanitize($input)['connections'][0]['settings'];

        // Provider-specific non-secret settings survive the save, trimmed.
        $this->assertSame('AKIAEXAMPLE', $settings['access_key']);
        $this->assertSame('eu-central-1', $settings['region']);
        // Known SMTP keys are still coerced/defaulted alongside the pass-through settings.
        $this->assertSame(0, $settings['port']);
        $this->assertIsInt($settings['port']);
        $this->assertSame('none', $settings['encryption']);
        $this->assertFalse($settings['auth']);
    }

    public function testDropsNonScalarSettingValues(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'amazon_ses',
                'kind'         => 'api',
                'name'         => 'SES',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [
                    'access_key' => 'AKIAEXAMPLE',
                    'junk_array' => ['nested' => 'x'],
                    'junk_obj'   => (object) ['a' => 1],
                ],
                'credentials'  => [],
            ],
        ];
        $settings = MailSettingsSanitizer::sanitize($input)['connections'][0]['settings'];

        $this->assertSame('AKIAEXAMPLE', $settings['access_key']);
        $this->assertArrayNotHasKey('junk_array', $settings);
        $this->assertArrayNotHasKey('junk_obj', $settings);
    }

    public function testOmitsOAuthKeysForSmtpConnections(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['host' => 'smtp.example.com'],
                'credentials'  => [],
            ],
        ];
        $settings = MailSettingsSanitizer::sanitize($input)['connections'][0]['settings'];

        $this->assertArrayNotHasKey('client_id', $settings);
        $this->assertArrayNotHasKey('token_expires_at', $settings);
    }

    public function testKeepsValidRoutingRuleWithWhitelistedFieldAndOperator(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['routing' => [
            [
                'connectionId' => 'conn_b',
                'conditions'   => [
                    ['field' => 'recipient', 'operator' => 'domain', 'value' => 'routed.test'],
                ],
            ],
        ]];
        $routing = MailSettingsSanitizer::sanitize($input)['features']['routing'];

        $this->assertSame([
            [
                'connectionId' => 'conn_b',
                'conditions'   => [
                    ['field' => 'recipient', 'operator' => 'domain', 'value' => 'routed.test'],
                ],
            ],
        ], $routing);
    }

    public function testNormalizesSnakeCaseConnectionIdAliasToCamelCase(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['routing' => [
            [
                'connection_id' => 'conn_b',
                'conditions'    => [['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']],
            ],
        ]];
        $routing = MailSettingsSanitizer::sanitize($input)['features']['routing'];

        $this->assertSame('conn_b', $routing[0]['connectionId']);
        $this->assertArrayNotHasKey('connection_id', $routing[0]);
    }

    public function testTrimsRoutingConditionValueAndDropsMalformedConditions(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['routing' => [
            [
                'connectionId' => 'conn_b',
                'conditions'   => [
                    ['field' => 'recipient', 'operator' => 'domain', 'value' => '  routed.test  '],
                    ['field' => 'body', 'operator' => 'domain', 'value' => 'x'],
                    ['field' => 'from', 'operator' => 'startswith', 'value' => 'x'],
                    'not-an-array',
                ],
            ],
        ]];
        $routing = MailSettingsSanitizer::sanitize($input)['features']['routing'];

        $this->assertSame([
            ['field' => 'recipient', 'operator' => 'domain', 'value' => 'routed.test'],
        ], $routing[0]['conditions']);
    }

    public function testDropsRoutingRuleWithoutConnectionId(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['routing' => [
            ['conditions' => [['field' => 'recipient', 'operator' => 'domain', 'value' => 'routed.test']]],
        ]];
        $routing = MailSettingsSanitizer::sanitize($input)['features']['routing'];

        $this->assertSame([], $routing);
    }

    public function testDropsRoutingRuleWhenNoConditionSurvivesWhitelist(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['routing' => [
            [
                'connectionId' => 'conn_b',
                'conditions'   => [['field' => 'unknown', 'operator' => 'domain', 'value' => 'routed.test']],
            ],
        ]];
        $routing = MailSettingsSanitizer::sanitize($input)['features']['routing'];

        $this->assertSame([], $routing);
    }

    public function testStripsSettingsKeyThatCollidesWithProviderSecretField(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'sendgrid',
                'kind'         => 'api',
                'name'         => 'SendGrid',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['api_key' => 'SG.raw-secret-in-settings', 'region' => 'eu'],
                'credentials'  => [],
            ],
        ];
        $settings = MailSettingsSanitizer::sanitize($input, $this->sendGridSecretKeyResolver())['connections'][0]['settings'];

        $this->assertArrayNotHasKey('api_key', $settings);
        $this->assertSame('eu', $settings['region']);
    }

    public function testDoesNotStripSettingsKeyForProviderWhoseSecretFieldsDiffer(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'amazon_ses',
                'kind'         => 'api',
                'name'         => 'SES',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => ['access_key' => 'AKIAEXAMPLE'],
                'credentials'  => [],
            ],
        ];
        $settings = MailSettingsSanitizer::sanitize($input, $this->sendGridSecretKeyResolver())['connections'][0]['settings'];

        $this->assertSame('AKIAEXAMPLE', $settings['access_key']);
    }

    public function testCredentialScalarEntryCoercedInsteadOfThrowing(): void
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'other_smtp',
                'kind'         => 'smtp',
                'name'         => 'Test',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => [],
                'credentials'  => ['password' => 'raw'],
            ],
        ];
        $result = MailSettingsSanitizer::sanitize($input);

        $this->assertSame(
            ['source' => 'database', 'value' => 'raw'],
            $result['connections'][0]['credentials']['password']
        );
    }

    /**
     * Stands in for the real Plugin::providerRegistry() lookup so the unit tier stays WP-free.
     */
    private function sendGridSecretKeyResolver(): callable
    {
        return static function (string $provider): array {
            return $provider === 'sendgrid' ? ['api_key'] : [];
        };
    }

    private function baseV2(): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => [
                'logging'        => [],
                'alerts'         => [],
                'routing'        => [],
                'tracking'       => [],
                'email_controls' => [],
            ],
        ];
    }
}
