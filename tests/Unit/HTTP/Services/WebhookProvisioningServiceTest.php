<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Services;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\HTTP\Services\WebhookProvisioningService;
use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\ProviderInterface;
use BitApps\SMTP\Mail\Contracts\WebhookProvisionerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\WebhookProvisionerFactory;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class WebhookProvisioningServiceTest extends BaseUnitTestCase
{
    private const WEBHOOK_URL = 'https://example.test/bit-smtp/conn_1/sekret';

    /**
     * @var MailConfigService|Mockery\MockInterface
     */
    private $config;

    /**
     * @var ProviderRegistry|Mockery\MockInterface
     */
    private $registry;

    /**
     * @var WebhookProvisionerFactory|Mockery\MockInterface
     */
    private $factory;

    /**
     * @var ApiClient|Mockery\MockInterface
     */
    private $apiClient;

    private AuthorizationResolver $authResolver;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://example.test' . $path);

        $this->config    = Mockery::mock(MailConfigService::class);
        $this->registry  = Mockery::mock(ProviderRegistry::class);
        $this->factory   = Mockery::mock(WebhookProvisionerFactory::class);
        $this->apiClient = Mockery::mock(ApiClient::class);
        $this->apiClient->shouldReceive('withTimeout')->andReturnSelf();

        $this->authResolver = new AuthorizationResolver(
            Mockery::mock(OAuth2TokenProvider::class),
            new SigV4Signer()
        );
    }

    public function testProvisionOnSaveSkipsWhenWebhookDisabled(): void
    {
        $this->factory->shouldNotReceive('forProvider');

        $result = $this->service()->provisionOnSave($this->connection(['webhook_enabled' => false]));

        $this->assertSame(['status' => 'skipped'], $result);
    }

    public function testProvisionOnSaveSkipsWhenProviderHasNoProvisioner(): void
    {
        $this->factory->shouldNotReceive('forProvider');

        // Mockery's array argument matching is a plain `==` compare and does not recurse into
        // matcher objects nested inside a literal array, so the updated_at timestamp is asserted
        // via Mockery::on() instead of Mockery::type() nested in the array (as with cycles a/b).
        $this->config->shouldReceive('persistConnectionProvisioning')
            ->once()
            ->with('conn_1', [], Mockery::on(static function (array $settings): bool {
                return ($settings['webhook_provisioning_status'] ?? null) === 'unsupported'
                    && ($settings['webhook_provisioning_reason'] ?? null) === ''
                    && \is_int($settings['webhook_provisioning_updated_at'] ?? null);
            }))
            ->andReturn(true);

        // 'zeptomail' is webhook-capable (has an inbound adapter) but exposes no registration API,
        // so it has no provisioner and must be left for manual-paste setup.
        $result = $this->service()->provisionOnSave($this->connection([], 'zeptomail'));

        $this->assertSame(['status' => 'skipped'], $result);
    }

    public function testProvisionOnSaveSkipsWhenAlreadyProvisionedForTheSameUrl(): void
    {
        $this->factory->shouldNotReceive('forProvider');

        $result = $this->service()->provisionOnSave(
            $this->connection(['webhook_provisioned_url' => self::WEBHOOK_URL])
        );

        $this->assertSame(['status' => 'skipped'], $result);
    }

    public function testProvisionOnSaveReturnsWarningWithoutThrowingWhenProvisioningFails(): void
    {
        // The best-effort path logs the failure via error_log; declare it so the strict-output suite
        // doesn't flag the write as risky, and assert the per-connection URL was redacted before logging.
        $this->expectOutputRegex('/webhook auto-provision failed: boom at \[redacted webhook URL\]/');

        $this->registry->shouldReceive('get')->with('sendgrid')->andReturn($this->bearerProvider());

        $provisioner = Mockery::mock(WebhookProvisionerInterface::class);
        $provisioner->shouldReceive('ensure')->andThrow(new RuntimeException('boom at ' . self::WEBHOOK_URL));
        $this->factory->shouldReceive('forProvider')->andReturn($provisioner);

        $this->config->shouldReceive('persistConnectionProvisioning')
            ->once()
            ->with('conn_1', [], Mockery::on(static function (array $settings): bool {
                return ($settings['webhook_provisioning_status'] ?? null) === 'failed'
                    && ($settings['webhook_provisioning_reason'] ?? null) === 'boom at [redacted webhook URL]'
                    && \is_int($settings['webhook_provisioning_updated_at'] ?? null);
            }))
            ->andReturn(true);

        $result = $this->service()->provisionOnSave($this->connection());

        $this->assertSame('warning', $result['status']);
        $this->assertNotEmpty($result['message']);
    }

    public function testProvisionOnSaveReturnsOkAndPersistsPublicKeyOnSuccess(): void
    {
        $this->registry->shouldReceive('get')->with('sendgrid')->andReturn($this->bearerProvider());

        $provisioner = Mockery::mock(WebhookProvisionerInterface::class);
        $provisioner->shouldReceive('ensure')->andReturn(['created' => true, 'id' => 'wh_1', 'public_key' => 'PK']);
        $this->factory->shouldReceive('forProvider')->andReturn($provisioner);

        $this->config->shouldReceive('persistConnectionProvisioning')
            ->once()
            ->with('conn_1', [], [
                'webhook_provisioned_url'   => self::WEBHOOK_URL,
                'webhook_signature_enabled' => true,
                'webhook_public_key'        => 'PK',
            ])
            ->andReturn(true);
        $this->config->shouldReceive('persistConnectionProvisioning')
            ->once()
            ->with('conn_1', [], Mockery::on(static function (array $settings): bool {
                return ($settings['webhook_provisioning_status'] ?? null) === 'registered'
                    && ($settings['webhook_provisioning_reason'] ?? null) === ''
                    && \is_int($settings['webhook_provisioning_updated_at'] ?? null);
            }))
            ->andReturn(true);

        $result = $this->service()->provisionOnSave($this->connection());

        $this->assertSame(['status' => 'ok', 'created' => true], $result);
    }

    public function testEnsureForStoresHmacSecretAsEncryptedCredential(): void
    {
        $this->registry->shouldReceive('get')->with('sendgrid')->andReturn($this->bearerProvider());

        $provisioner = Mockery::mock(WebhookProvisionerInterface::class);
        $provisioner->shouldReceive('ensure')->andReturn(['created' => true, 'id' => 'wh_1', 'signing_secret' => 'whsec_abc']);
        $this->factory->shouldReceive('forProvider')->andReturn($provisioner);

        $this->config->shouldReceive('persistConnectionProvisioning')
            ->once()
            ->with('conn_1', ['webhook_signing_secret' => 'whsec_abc'], [
                'webhook_provisioned_url'   => self::WEBHOOK_URL,
                'webhook_signature_enabled' => true,
            ])
            ->andReturn(true);

        $result = $this->service()->ensureFor($this->connection());

        $this->assertSame(['created' => true, 'id' => 'wh_1'], $result);
    }

    private function service(): WebhookProvisioningService
    {
        return new WebhookProvisioningService(
            $this->config,
            $this->registry,
            $this->authResolver,
            $this->apiClient,
            $this->factory
        );
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function connection(array $settings = [], string $provider = 'sendgrid', string $kind = 'api'): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => $provider,
            'kind'        => $kind,
            'name'        => 'Conn',
            'settings'    => array_merge(['webhook_enabled' => true, 'webhook_secret' => 'sekret'], $settings),
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'SG.key']],
        ]);
    }

    private function bearerProvider(): ProviderInterface
    {
        $provider = Mockery::mock(ProviderInterface::class);
        $provider->shouldReceive('authConfig')->andReturn(['type' => 'bearer', 'params' => ['credentialKey' => 'api_key']]);

        return $provider;
    }
}
