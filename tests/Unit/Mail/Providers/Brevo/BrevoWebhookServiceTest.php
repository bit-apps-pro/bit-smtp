<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Brevo;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Providers\Brevo\BrevoWebhookService;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class BrevoWebhookServiceTest extends BaseUnitTestCase
{
    private ApiClient $client;

    /**
     * @var AuthStrategyInterface|Mockery\MockInterface
     */
    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('home_url')->alias(static fn (string $path): string => 'https://example.test' . $path);
        $this->client = Mockery::mock(ApiClient::class);
        $this->auth   = $this->apiKeyAuth();
    }

    public function testCreatesTransactionalWebhookWithDeliverabilityEvents(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->with(Mockery::on(static function (array $headers): bool {
            return ($headers['api-key'] ?? '')      === 'brevo-key-123'
                && ($headers['Content-Type'] ?? '') === 'application/json';
        }))->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(
            'https://api.brevo.com/v3/webhooks',
            ['type' => 'transactional']
        )->andReturn(new ApiResponse(200, ['webhooks' => []]));
        $this->client->shouldReceive('post')->once()->with(
            'https://api.brevo.com/v3/webhooks',
            Mockery::on(static function (array $body): bool {
                return $body['url']         === 'https://example.test/bit-smtp/conn_1/secret'
                    && $body['description'] === 'Brevo Bit SMTP'
                    && $body['type']        === 'transactional'
                    && $body['events']      === ['delivered', 'hardBounce', 'softBounce', 'blocked', 'invalid', 'deferred'];
            })
        )->andReturn(new ApiResponse(201, ['id' => 42]));

        $result = (new BrevoWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => '42'], $result);
    }

    public function testReusesWebhookWithSameEndpointInsteadOfCreatingDuplicate(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'webhooks' => [
                ['id' => 7, 'url' => 'https://example.test/bit-smtp/conn_1/secret'],
            ],
        ]));
        $this->client->shouldNotReceive('post');

        $result = (new BrevoWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => false, 'id' => '7'], $result);
    }

    public function testThrowsWithoutLeakingSecretWhenListingFails(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(401, ['message' => 'Key not found']));
        $this->client->shouldNotReceive('post');

        try {
            (new BrevoWebhookService($this->client, $this->auth))->ensure($this->connection());
            $this->fail('Expected a RuntimeException for the failed webhook listing.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertStringNotContainsString('brevo-key-123', $exception->getMessage());
        }
    }

    public function testDeregisterDeletesTheWebhookMatchingOurUrl(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(
            'https://api.brevo.com/v3/webhooks',
            ['type' => 'transactional']
        )->andReturn(new ApiResponse(200, [
            'webhooks' => [
                ['id' => 3, 'url' => 'https://other.test/hook'],
                ['id' => 7, 'url' => 'https://example.test/bit-smtp/conn_1/secret'],
            ],
        ]));
        $this->client->shouldReceive('delete')->once()->with('https://api.brevo.com/v3/webhooks/7')->andReturn(new ApiResponse(200, []));

        (new BrevoWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenNoWebhookMatchesOurUrl(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'webhooks' => [
                ['id' => 3, 'url' => 'https://other.test/hook'],
            ],
        ]));
        $this->client->shouldNotReceive('delete');

        (new BrevoWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenListingFails(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(500, []));
        $this->client->shouldNotReceive('delete');

        (new BrevoWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    /**
     * Stand-in for the Brevo api_key strategy: writes the connection's api_key as the api-key header,
     * proving the provisioner forwards whatever the auth strategy produced onto the client.
     */
    private function apiKeyAuth(): AuthStrategyInterface
    {
        $auth = Mockery::mock(AuthStrategyInterface::class);
        $auth->shouldReceive('apply')->once()->andReturnUsing(static function (ApiRequest $request, Connection $connection): void {
            $request->setHeader('api-key', $connection->getCredentials()['api_key']['value'] ?? '');
        });

        return $auth;
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'brevo',
            'kind'        => 'api',
            'name'        => 'Brevo',
            'settings'    => ['webhook_secret' => 'secret'],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'brevo-key-123']],
        ]);
    }
}
