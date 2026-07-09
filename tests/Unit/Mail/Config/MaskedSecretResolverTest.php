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

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('wp_generate_uuid4')->justReturn('test-uuid-1234');
    }

    public function testAbsentCredentialsKeyPreservesStoredCredentials(): void
    {
        $current = $this->currentSettings();

        // $incomingV2 has conn_abc with NO credentials key at all (not just an empty array).
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
                    // credentials key is intentionally absent
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

        // A brand-new connection (id not in $current) with no credentials key stays as-is.
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
                    // credentials key is intentionally absent, and there is no stored connection
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
        // $current holds conn_abc + conn_other — neither matches conn_new, so the id-lookup-miss
        // path is genuinely exercised and the sentinel must blank to ''.
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
        // $current has two connections; incoming targets conn_abc only.
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
     * Sentinel must be restored by connection id, not array position.
     *
     * $current: [conn_A (password="A"), conn_B (password="B")]
     * $incoming: [conn_B at index 0 with sentinel password]
     *
     * A position-based matcher would restore "A" (index 0 → conn_A).
     * An id-based matcher correctly restores "B" (id conn_B → conn_B).
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

        // conn_B is at index 0 in the incoming array.
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
     * Two-connection current settings so id-lookup-miss path is exercised when the
     * incoming connection id does not match any stored id.
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
