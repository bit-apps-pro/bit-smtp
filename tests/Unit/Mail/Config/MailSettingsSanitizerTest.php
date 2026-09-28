<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\MailSettingsSanitizer;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
class MailSettingsSanitizerTest extends BaseUnitTestCase
{
    private const SLACK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX';

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

        $this->assertSame('AKIAEXAMPLE', $settings['access_key']);
        $this->assertSame('eu-central-1', $settings['region']);
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

    public function testDropsDeadDomainConditionOnANonAddressFieldAndTheNowEmptyRule(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['routing' => [
            [
                'connectionId' => 'conn_b',
                'conditions'   => [
                    ['field' => 'source_plugin', 'operator' => 'domain', 'value' => 'woocommerce'],
                ],
            ],
        ]];

        $this->assertSame([], MailSettingsSanitizer::sanitize($input)['features']['routing']);
    }

    public function testKeepsValidConditionsWhenDroppingADeadDomainCondition(): void
    {
        $input             = $this->baseV2();
        $input['features'] = ['routing' => [
            [
                'connectionId' => 'conn_b',
                'conditions'   => [
                    ['field' => 'recipient', 'operator' => 'equals', 'value' => 'ceo@acme.test'],
                    ['field' => 'subject', 'operator' => 'domain', 'value' => 'nonsense'],
                ],
            ],
        ]];
        $routing = MailSettingsSanitizer::sanitize($input)['features']['routing'];

        $this->assertSame([
            ['field' => 'recipient', 'operator' => 'equals', 'value' => 'ceo@acme.test'],
        ], $routing[0]['conditions']);
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
     * @param mixed $rawValue
     */
    #[DataProvider('falsyWebhookEnabledProvider')]
    public function testWebhookEnabledCoercedToStrictFalse($rawValue): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_enabled' => $rawValue]);

        $this->assertFalse($settings['webhook_enabled']);
        $this->assertIsBool($settings['webhook_enabled']);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function falsyWebhookEnabledProvider(): array
    {
        return [
            'bool false'   => [false],
            'string zero'  => ['0'],
            'empty string' => [''],
        ];
    }

    /**
     * @param mixed $rawValue
     */
    #[DataProvider('truthyWebhookEnabledProvider')]
    public function testWebhookEnabledCoercedToStrictTrue($rawValue): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_enabled' => $rawValue]);

        $this->assertTrue($settings['webhook_enabled']);
        $this->assertIsBool($settings['webhook_enabled']);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function truthyWebhookEnabledProvider(): array
    {
        return [
            'bool true'  => [true],
            'string one' => ['1'],
        ];
    }

    /**
     * @param mixed $rawValue
     */
    #[DataProvider('falsyWebhookVerifiedProvider')]
    public function testWebhookVerifiedCoercedToStrictFalse($rawValue): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_verified' => $rawValue]);

        $this->assertFalse($settings['webhook_verified']);
        $this->assertIsBool($settings['webhook_verified']);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function falsyWebhookVerifiedProvider(): array
    {
        return [
            'bool false'   => [false],
            'string zero'  => ['0'],
            'empty string' => [''],
        ];
    }

    /**
     * @param mixed $rawValue
     */
    #[DataProvider('truthyWebhookVerifiedProvider')]
    public function testWebhookVerifiedCoercedToStrictTrue($rawValue): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_verified' => $rawValue]);

        $this->assertTrue($settings['webhook_verified']);
        $this->assertIsBool($settings['webhook_verified']);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function truthyWebhookVerifiedProvider(): array
    {
        return [
            'bool true'   => [true],
            'string one'  => ['1'],
            'string true' => ['true'],
        ];
    }

    public function testWebhookSecretPassesThroughAsTrimmedString(): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_secret' => '  sek_abc123xyz  ']);

        $this->assertSame('sek_abc123xyz', $settings['webhook_secret']);
        $this->assertIsString($settings['webhook_secret']);
    }

    public function testWebhookSecretAbsentOmittedFromSanitized(): void
    {
        $settings = $this->sanitizeConnectionSettings([]);

        $this->assertArrayNotHasKey('webhook_secret', $settings);
    }

    public function testSanitizeConnectionSettingsCastsWebhookProvisioningUpdatedAtToInt(): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_provisioning_updated_at' => '1700000000']);

        $this->assertSame(1700000000, $settings['webhook_provisioning_updated_at']);
        $this->assertIsInt($settings['webhook_provisioning_updated_at']);
    }

    public function testWebhookProvisioningUpdatedAtAbsentOmittedFromSanitized(): void
    {
        $settings = $this->sanitizeConnectionSettings([]);

        $this->assertArrayNotHasKey('webhook_provisioning_updated_at', $settings);
    }

    /**
     * @param mixed $rawValue
     */
    #[DataProvider('validWebhookProvisioningStatusProvider')]
    public function testWebhookProvisioningStatusKeepsWhitelistedEnumValue($rawValue): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_provisioning_status' => $rawValue]);

        $this->assertSame($rawValue, $settings['webhook_provisioning_status']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function validWebhookProvisioningStatusProvider(): array
    {
        return [
            'registered'  => ['registered'],
            'failed'      => ['failed'],
            'unsupported' => ['unsupported'],
            'unavailable' => ['unavailable'],
        ];
    }

    /**
     * Guards "provider confirmed" trust signal: unknown webhook_provisioning_status values are dropped.
     */
    public function testWebhookProvisioningStatusDropsUnknownValue(): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_provisioning_status' => 'exploited']);

        $this->assertArrayNotHasKey('webhook_provisioning_status', $settings);
    }

    public function testWebhookProvisioningStatusAbsentOmittedFromSanitized(): void
    {
        $settings = $this->sanitizeConnectionSettings([]);

        $this->assertArrayNotHasKey('webhook_provisioning_status', $settings);
    }

    public function testWebhookProvisioningReasonPassesThroughAsTrimmedString(): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_provisioning_reason' => '  provider returned 401  ']);

        $this->assertSame('provider returned 401', $settings['webhook_provisioning_reason']);
    }

    public function testWebhookLastEventAtPassesThroughAsTrimmedString(): void
    {
        $settings = $this->sanitizeConnectionSettings(['webhook_last_event_at' => '  2024-01-15 10:30:00  ']);

        $this->assertSame('2024-01-15 10:30:00', $settings['webhook_last_event_at']);
        $this->assertIsString($settings['webhook_last_event_at']);
    }

    public function testWebhookLastEventAtAbsentOmittedFromSanitized(): void
    {
        $settings = $this->sanitizeConnectionSettings([]);

        $this->assertArrayNotHasKey('webhook_last_event_at', $settings);
    }

    public function testFailureAlertsAreWhitelistedAndNormalized(): void
    {
        $alerts = $this->sanitizeAlerts([
            'enabled'  => '1',
            'channels' => [
                [
                    'id'       => 'ch_email01',
                    'type'     => 'email',
                    'name'     => ' <b>Ops</b> inbox ',
                    'enabled'  => true,
                    'settings' => [
                        'recipients' => [' ops@example.com ', 'invalid', 'ops@example.com'],
                        'ignored'    => 'value',
                    ],
                    'ignored'  => 'value',
                ],
                [
                    'id'       => 'ch_hook001',
                    'type'     => 'webhook',
                    'enabled'  => 'true',
                    'settings' => [
                        'url'            => ' https://hooks.example.com/mail-failed ',
                        'signing_secret' => ' whsec_abcdefghijklmnopqrstuvwxyz012345 ',
                    ],
                ],
            ],
            'ignored' => 'value',
        ]);

        $this->assertSame([
            'enabled'          => true,
            'reminder_minutes' => 60,
            'channels'         => [
                [
                    'id'       => 'ch_email01',
                    'type'     => 'email',
                    'name'     => 'Ops inbox',
                    'enabled'  => true,
                    'settings' => ['recipients' => ['ops@example.com']],
                ],
                [
                    'id'       => 'ch_hook001',
                    'type'     => 'webhook',
                    'name'     => 'Webhook',
                    'enabled'  => true,
                    'settings' => [
                        'url'            => 'https://hooks.example.com/mail-failed',
                        'signing_secret' => 'whsec_abcdefghijklmnopqrstuvwxyz012345',
                    ],
                ],
            ],
        ], $alerts);
    }

    /**
     * @return array<string,array{mixed,int}>
     */
    public static function reminderMinutesProvider(): array
    {
        return [
            'absent uses default'      => [null, 60],
            'integer kept'             => [30, 30],
            'numeric string kept'      => ['15', 15],
            'zero turns reminders off' => [0, 0],
            'negative clamps to zero'  => [-5, 0],
            'above max clamps'         => [5000, 1440],
            'non-integer uses default' => ['soon', 60],
            'fraction uses default'    => [12.5, 60],
        ];
    }

    #[DataProvider('reminderMinutesProvider')]
    public function testFailureAlertReminderMinutesIsClampedWithADefault(mixed $input, int $expected): void
    {
        $alerts = $input === null ? ['enabled' => true] : ['enabled' => true, 'reminder_minutes' => $input];

        $this->assertSame($expected, $this->sanitizeAlerts($alerts)['reminder_minutes']);
    }

    public function testFailureAlertsEmptyOrGarbageBlobNormalizesToNoChannels(): void
    {
        $expected = ['enabled' => false, 'reminder_minutes' => 60, 'channels' => []];

        $this->assertSame($expected, $this->sanitizeAlerts([]));
        $this->assertSame($expected, $this->sanitizeAlerts(['channels' => 'nope']));
    }

    public function testReadOnlyRevisionAndDeliveryAreNeverPersisted(): void
    {
        $alerts = $this->sanitizeAlerts([
            'enabled'  => true,
            'revision' => 'abc123',
            'delivery' => ['cron_disabled' => true, 'overdue' => true],
        ]);

        $this->assertSame(['enabled', 'reminder_minutes', 'channels'], array_keys($alerts));
    }

    public function testAnUnnamedChannelFallsBackToItsTranslatedTypeLabel(): void
    {
        $alerts = $this->sanitizeAlerts(['channels' => [['id' => 'ch_tele001', 'type' => 'telegram', 'name' => '  ', 'settings' => []]]]);

        $this->assertSame('Telegram', $alerts['channels'][0]['name']);
    }

    public function testFailureAlertsKeepSeveralInstancesOfTheSameType(): void
    {
        $alerts = $this->sanitizeAlerts(['enabled' => true, 'channels' => [
            ['id' => 'ch_slackaa', 'type' => 'slack', 'name' => 'Ops', 'enabled' => true, 'settings' => ['webhook_url' => self::SLACK_URL]],
            ['id' => 'ch_slackbb', 'type' => 'slack', 'name' => 'Dev', 'enabled' => false, 'settings' => ['webhook_url' => self::SLACK_URL . 'B']],
        ]]);

        $this->assertCount(2, $alerts['channels']);
        $this->assertSame(['ch_slackaa', 'ch_slackbb'], array_column($alerts['channels'], 'id'));
        $this->assertSame([true, false], array_column($alerts['channels'], 'enabled'));
    }

    public function testFailureAlertsDropUnknownTypesAndCapTheList(): void
    {
        $channels = [['type' => 'sms', 'settings' => []], 'not-an-array'];
        for ($i = 0; $i < 25; ++$i) {
            $channels[] = ['type' => 'email', 'settings' => []];
        }

        $alerts = $this->sanitizeAlerts(['channels' => $channels]);

        $this->assertCount(20, $alerts['channels']);
        $this->assertSame(['email'], array_values(array_unique(array_column($alerts['channels'], 'type'))));
    }

    public function testFailureAlertsRegenerateInvalidAndDuplicateIds(): void
    {
        $alerts = $this->sanitizeAlerts(['channels' => [
            ['id' => 'ch_keepme1', 'type' => 'email'],
            ['id'   => 'ch_keepme1', 'type' => 'email'],
            ['id'   => 'BAD id', 'type' => 'email'],
            ['type' => 'email'],
        ]]);

        $ids = array_column($alerts['channels'], 'id');
        $this->assertSame('ch_keepme1', $ids[0]);
        $this->assertCount(4, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^ch_[a-z0-9]{6,32}$/', $id);
        }
    }

    public function testFailureAlertsClampNameAndFallBackToTypeLabel(): void
    {
        $alerts = $this->sanitizeAlerts(['channels' => [
            ['id' => 'ch_named01', 'type' => 'telegram', 'name' => str_repeat('a', 80)],
            ['id' => 'ch_named02', 'type' => 'discord', 'name' => '   '],
        ]]);

        $this->assertSame(str_repeat('a', 60), $alerts['channels'][0]['name']);
        $this->assertSame('Discord', $alerts['channels'][1]['name']);
    }

    public function testFailureAlertsRejectNonHttpWebhookUrls(): void
    {
        $this->assertSame('', $this->sanitizeChannelSettings('webhook', ['url' => 'file:///etc/passwd'])['url']);
    }

    public function testFailureAlertsRejectOverlongWebhookUrls(): void
    {
        $url = 'https://hooks.example.com/' . str_repeat('a', 2048);

        $this->assertSame('', $this->sanitizeChannelSettings('webhook', ['url' => $url])['url']);
    }

    public function testFailureAlertsRejectMalformedWebhookSigningSecret(): void
    {
        $settings = $this->sanitizeChannelSettings('webhook', [
            'url'            => 'https://hooks.example.com/failure',
            'signing_secret' => 'not-a-whsec',
        ]);

        $this->assertSame('', $settings['signing_secret']);
    }

    public function testFailureAlertsCapRecipientsAtTwenty(): void
    {
        $recipients = array_map(static fn (int $i): string => "ops{$i}@example.com", range(1, 25));

        $this->assertCount(20, $this->sanitizeChannelSettings('email', ['recipients' => $recipients])['recipients']);
    }

    public function testFailureAlertsNormalizeSlackAndTelegramSettingsWithoutUnknownKeys(): void
    {
        $this->assertSame(
            ['webhook_url' => self::SLACK_URL],
            $this->sanitizeChannelSettings('slack', ['webhook_url' => ' ' . self::SLACK_URL . ' ', 'ignored' => 'drop me'])
        );
        $this->assertSame(
            [
                'bot_token' => '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz',
                'chat_id'   => '-1001234567890',
            ],
            $this->sanitizeChannelSettings('telegram', [
                'bot_token' => ' 123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz ',
                'chat_id'   => ' -1001234567890 ',
                'ignored'   => 'drop me',
            ])
        );
    }

    public function testFailureAlertsNormalizeDiscordWebhookUrlWithoutUnknownKeys(): void
    {
        $this->assertSame(
            ['webhook_url' => 'https://discord.com/api/webhooks/123456789012345678/aBcDeFgHiJkLmNoPqRsTuVwXyZ'],
            $this->sanitizeChannelSettings('discord', [
                'webhook_url' => ' https://discord.com/api/webhooks/123456789012345678/aBcDeFgHiJkLmNoPqRsTuVwXyZ ',
                'ignored'     => 'drop me',
            ])
        );
    }

    public function testFailureAlertsBlankMalformedDiscordWebhookUrl(): void
    {
        $settings = $this->sanitizeChannelSettings('discord', ['webhook_url' => 'https://discord.com/webhooks/123/token']);

        $this->assertSame('', $settings['webhook_url']);
    }

    public function testFailureAlertsBlankMalformedSlackAndTelegramSecrets(): void
    {
        $slack    = $this->sanitizeChannelSettings('slack', ['webhook_url' => 'https://hooks.slack.com/api/services/T/B/X']);
        $telegram = $this->sanitizeChannelSettings('telegram', ['bot_token' => 'bad token', 'chat_id' => 'channel-name']);

        $this->assertSame('', $slack['webhook_url']);
        $this->assertSame('', $telegram['bot_token']);
        $this->assertSame('', $telegram['chat_id']);
    }

    /**
     * Sanitize an alerts blob through the public entry point and return it.
     *
     * @param mixed $alerts
     */
    private function sanitizeAlerts($alerts): array
    {
        $input                       = $this->baseV2();
        $input['features']['alerts'] = $alerts;

        return MailSettingsSanitizer::sanitize($input)['features']['alerts'];
    }

    /**
     * Sanitize one alert channel instance of the given type and return its settings.
     */
    private function sanitizeChannelSettings(string $type, array $settings): array
    {
        return $this->sanitizeAlerts([
            'channels' => [['id' => 'ch_single1', 'type' => $type, 'settings' => $settings]],
        ])['channels'][0]['settings'];
    }

    /**
     * Sanitize a single API connection's settings through the public entry point and return them.
     */
    private function sanitizeConnectionSettings(array $settings): array
    {
        $input                = $this->baseV2();
        $input['connections'] = [
            [
                'id'           => 'conn_1',
                'provider'     => 'postmark',
                'kind'         => 'api',
                'name'         => 'Postmark',
                'enabled'      => true,
                'fromEmail'    => '',
                'fromName'     => '',
                'replyToEmail' => '',
                'settings'     => $settings,
                'credentials'  => [],
            ],
        ];

        return MailSettingsSanitizer::sanitize($input)['connections'][0]['settings'];
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
