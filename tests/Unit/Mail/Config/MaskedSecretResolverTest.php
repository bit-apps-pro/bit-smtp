<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Config;

use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Config\MailSettingsSerializer;
use BitApps\SMTP\Mail\Config\MaskedSecretResolver;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MaskedSecretResolverTest extends BaseUnitTestCase
{
    private const SENTINEL = MailSettingsSerializer::MASK_SENTINEL;

    private const WEBHOOK_URL = 'https://hooks.example.com/secret';

    private const WHSEC = 'whsec_abcdefghijklmnopqrstuvwxyz012345';

    private const SLACK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/X';

    private const BOT_TOKEN = '123456789:AAExampleBotToken_abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('wp_generate_uuid4')->justReturn('test-uuid-1234');
    }

    public function testAbsentCredentialsKeyPreservesStoredCredentials(): void
    {
        $current = $this->currentSettings();

        $incoming = [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_abc',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'       => 'conn_abc',
                    'provider' => 'other_smtp',
                    'kind'     => 'smtp',
                ],
            ],
            'features' => [],
        ];

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertSame(
            ['password' => ['source' => 'database', 'value' => 'stored-secret']],
            $result['connections'][0]['credentials'],
            'Stored credentials must be copied when the update payload omits the credentials key.'
        );
    }

    public function testAbsentCredentialsKeyOnNewConnectionIsLeftAsIs(): void
    {
        $current = $this->currentSettings();

        $incoming = [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_new',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'       => 'conn_new',
                    'provider' => 'other_smtp',
                    'kind'     => 'smtp',
                ],
            ],
            'features' => [],
        ];

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertArrayNotHasKey(
            'credentials',
            $result['connections'][0],
            'A brand-new connection with no credentials key must not have one injected.'
        );
    }

    public function testSentinelValueIsReplacedWithStoredSecret(): void
    {
        $current  = $this->currentSettings();
        $incoming = $this->incomingV2([
            'password' => ['source' => 'database', 'value' => self::SENTINEL],
        ]);

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertSame(
            'stored-secret',
            $result['connections'][0]['credentials']['password']['value']
        );
    }

    public function testGenuineNewValueOverwritesStoredSecret(): void
    {
        $current  = $this->currentSettings();
        $incoming = $this->incomingV2([
            'password' => ['source' => 'database', 'value' => 'brand-new-password'],
        ]);

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertSame(
            'brand-new-password',
            $result['connections'][0]['credentials']['password']['value']
        );
    }

    public function testOmittedOAuthCredentialsArePreservedAlongsideEditableSecret(): void
    {
        $current = $this->currentSettings([
            'client_secret' => ['source' => 'database', 'value' => 'stored-client-secret'],
            'access_token'  => ['source' => 'database', 'value' => 'stored-access-token'],
            'refresh_token' => ['source' => 'database', 'value' => 'stored-refresh-token'],
        ]);
        $incoming = $this->incomingV2([
            'client_secret' => ['source' => 'database', 'value' => self::SENTINEL],
        ]);

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertSame(
            [
                'client_secret' => ['source' => 'database', 'value' => 'stored-client-secret'],
                'access_token'  => ['source' => 'database', 'value' => 'stored-access-token'],
                'refresh_token' => ['source' => 'database', 'value' => 'stored-refresh-token'],
            ],
            $result['connections'][0]['credentials']
        );
    }

    public function testNestedSentinelAtAnyDepthIsPreserved(): void
    {
        $current = $this->currentSettings([
            'token' => ['meta' => ['value' => 'deep-stored-secret']],
        ]);
        $incoming = $this->incomingV2([
            'token' => ['meta' => ['value' => self::SENTINEL]],
        ]);

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertSame(
            'deep-stored-secret',
            $result['connections'][0]['credentials']['token']['meta']['value']
        );
    }

    public function testBrandNewConnectionSentinelResolvesToEmptyString(): void
    {
        $current  = $this->currentSettings();
        $incoming = [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_new',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'          => 'conn_new',
                    'provider'    => 'other_smtp',
                    'kind'        => 'smtp',
                    'credentials' => [
                        'password' => ['source' => 'database', 'value' => self::SENTINEL],
                    ],
                ],
            ],
            'features' => [],
        ];

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertSame(
            '',
            $result['connections'][0]['credentials']['password']['value']
        );
    }

    public function testNonCredentialFieldsAreUntouched(): void
    {
        $current  = $this->currentSettings();
        $incoming = $this->incomingV2([
            'password' => ['source' => 'database', 'value' => self::SENTINEL],
        ]);
        $incoming['connections'][0]['fromEmail'] = 'foo@example.com';
        $incoming['connections'][0]['settings']  = ['host' => 'smtp.example.com'];

        $result = MaskedSecretResolver::apply($incoming, $current);

        $this->assertSame('foo@example.com', $result['connections'][0]['fromEmail']);
        $this->assertSame('smtp.example.com', $result['connections'][0]['settings']['host']);
    }

    /**
     * Sentinel restored by connection id, not array position; prevents restoration mix-ups when reordering.
     */
    public function testSentinelRestoredByIdNotPosition(): void
    {
        $current = MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_A',
            'fallback_connection_ids' => [],
            'connections'             => [
                $this->connectionStub('conn_A', [
                    'password' => ['source' => 'database', 'value' => 'A'],
                ]),
                $this->connectionStub('conn_B', [
                    'password' => ['source' => 'database', 'value' => 'B'],
                ]),
            ],
            'features' => [],
        ]);

        $incomingV2 = [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_A',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'          => 'conn_B',
                    'provider'    => 'other_smtp',
                    'kind'        => 'smtp',
                    'credentials' => [
                        'password' => ['source' => 'database', 'value' => self::SENTINEL],
                    ],
                ],
            ],
            'features' => [],
        ];

        $result = MaskedSecretResolver::apply($incomingV2, $current);

        $this->assertSame(
            'B',
            $result['connections'][0]['credentials']['password']['value'],
            'Sentinel must resolve to the secret for conn_B (by id), not conn_A (by position).'
        );
    }

    public function testAWebhookSigningSecretSentinelRestoresTheStoredSecretWithoutConnections(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_hook001', 'webhook', ['url' => self::WEBHOOK_URL, 'signing_secret' => self::WHSEC]),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_hook001', 'webhook', ['url' => self::WEBHOOK_URL, 'signing_secret' => self::SENTINEL]),
        ]), $current);

        $this->assertSame(
            ['url' => self::WEBHOOK_URL, 'signing_secret' => self::WHSEC],
            $result['features']['alerts']['channels'][0]['settings']
        );
    }

    public function testASentinelInAUrlFieldIsNotTreatedAsMasked(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_slackaa', 'slack', ['webhook_url' => self::SLACK_URL]),
            $this->instance('ch_hook001', 'webhook', ['url' => self::WEBHOOK_URL, 'signing_secret' => self::WHSEC]),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_slackaa', 'slack', ['webhook_url' => self::SENTINEL]),
            $this->instance('ch_hook001', 'webhook', ['url' => self::SENTINEL, 'signing_secret' => self::SENTINEL]),
        ]), $current);

        $channels = $result['features']['alerts']['channels'];
        $this->assertSame(self::SENTINEL, $channels[0]['settings']['webhook_url']);
        $this->assertSame(self::SENTINEL, $channels[1]['settings']['url']);
        $this->assertSame(self::WHSEC, $channels[1]['settings']['signing_secret']);
    }

    public function testEachSameTypeInstanceRestoresItsOwnSecretById(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_teleaaa', 'telegram', ['bot_token' => self::BOT_TOKEN . 'A', 'chat_id' => '-1']),
            $this->instance('ch_telebbb', 'telegram', ['bot_token' => self::BOT_TOKEN . 'B', 'chat_id' => '-2']),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_telebbb', 'telegram', ['bot_token' => self::SENTINEL, 'chat_id' => '-2']),
            $this->instance('ch_teleaaa', 'telegram', ['bot_token' => self::SENTINEL, 'chat_id' => '-1']),
        ]), $current);

        $channels = $result['features']['alerts']['channels'];
        $this->assertSame(self::BOT_TOKEN . 'B', $channels[0]['settings']['bot_token']);
        $this->assertSame(self::BOT_TOKEN . 'A', $channels[1]['settings']['bot_token']);
    }

    public function testATypeChangeNeverInheritsTheStoredSecret(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_swap001', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1']),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_swap001', 'webhook', ['url' => self::WEBHOOK_URL, 'signing_secret' => self::SENTINEL]),
        ]), $current);

        $this->assertSame('', $result['features']['alerts']['channels'][0]['settings']['signing_secret']);
    }

    public function testASentinelOnANewInstanceResolvesToBlank(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_teleaaa', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1']),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_brandnew', 'telegram', ['bot_token' => self::SENTINEL, 'chat_id' => '-1']),
        ]), $current);

        $this->assertSame('', $result['features']['alerts']['channels'][0]['settings']['bot_token']);
    }

    public function testExplicitBlankSecretClearsTheStoredValue(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_tele001', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890']),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_tele001', 'telegram', ['bot_token' => '', 'chat_id' => '-1001234567890']),
        ]), $current);

        $this->assertSame('', $result['features']['alerts']['channels'][0]['settings']['bot_token']);
    }

    public function testOmittedSettingsOfAnExistingInstanceArePreserved(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_tele001', 'telegram', ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890']),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_tele001', 'telegram', []),
        ]), $current);

        $this->assertSame(
            ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890'],
            $result['features']['alerts']['channels'][0]['settings']
        );
    }

    public function testTheIncomingListIsAuthoritativeSoOmittedInstancesAreDeleted(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_slackaa', 'slack', ['webhook_url' => self::SLACK_URL]),
            $this->instance('ch_hook001', 'webhook', ['url' => self::WEBHOOK_URL, 'signing_secret' => self::WHSEC]),
        ]);

        $result = MaskedSecretResolver::apply($this->incomingAlerts([
            $this->instance('ch_hook001', 'webhook', ['url' => self::SENTINEL, 'signing_secret' => self::SENTINEL]),
        ]), $current);

        $this->assertSame(['ch_hook001'], array_column($result['features']['alerts']['channels'], 'id'));
    }

    public function testResolveAlertChannelRestoresAMaskedSecretForAnUnsavedTestSend(): void
    {
        $current = $this->alertSettings([
            $this->instance('ch_hook001', 'webhook', ['url' => self::WEBHOOK_URL, 'signing_secret' => self::WHSEC]),
        ]);

        $settings = MaskedSecretResolver::resolveAlertChannel(
            'ch_hook001',
            'webhook',
            ['url' => 'https://edited.example.com/hook', 'signing_secret' => self::SENTINEL],
            $current
        );

        $this->assertSame(['url' => 'https://edited.example.com/hook', 'signing_secret' => self::WHSEC], $settings);
    }

    /**
     * A channel instance in the stored/API list shape.
     */
    private function instance(string $id, string $type, array $settings): array
    {
        return ['id' => $id, 'type' => $type, 'name' => '', 'enabled' => true, 'settings' => $settings];
    }

    /**
     * Stored settings holding only the given alert channel instances.
     */
    private function alertSettings(array $channels): MailSettings
    {
        return MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => ['alerts' => ['enabled' => true, 'channels' => $channels]],
        ]);
    }

    /**
     * An incoming save payload carrying only the given alert channel instances.
     */
    private function incomingAlerts(array $channels): array
    {
        return ['features' => ['alerts' => ['enabled' => true, 'channels' => $channels]]];
    }

    private function connectionStub(string $id, array $credentials): array
    {
        return [
            'id'           => $id,
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => 'Connection ' . $id,
            'enabled'      => true,
            'fromEmail'    => '',
            'fromName'     => '',
            'replyToEmail' => '',
            'settings'     => [],
            'credentials'  => $credentials,
        ];
    }

    /**
     * Two-connection settings to exercise id-lookup-miss path for non-matching connection ids.
     */
    private function currentSettings(array $credentialValue = []): MailSettings
    {
        $creds = !empty($credentialValue) ? $credentialValue : [
            'password' => ['source' => 'database', 'value' => 'stored-secret'],
        ];

        return MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_abc',
            'fallback_connection_ids' => [],
            'connections'             => [
                $this->connectionStub('conn_abc', $creds),
                $this->connectionStub('conn_other', [
                    'password' => ['source' => 'database', 'value' => 'other-secret'],
                ]),
            ],
            'features' => [],
        ]);
    }

    private function incomingV2(array $credentials): array
    {
        return [
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_abc',
            'fallback_connection_ids' => [],
            'connections'             => [
                [
                    'id'          => 'conn_abc',
                    'provider'    => 'other_smtp',
                    'kind'        => 'smtp',
                    'credentials' => $credentials,
                ],
            ],
            'features' => [],
        ];
    }
}
