<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Postmark;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkWebhookService;
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
final class PostmarkWebhookServiceTest extends BaseUnitTestCase
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
        $this->auth   = $this->serverTokenAuth();
    }

    public function testCreatesOutboundWebhookWithDeliverabilityTriggers(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->with(Mockery::on(static function (array $headers): bool {
            return ($headers['X-Postmark-Server-Token'] ?? '') === 'pm-token-123'
                && ($headers['Content-Type'] ?? '')            === 'application/json';
        }))->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(
            'https://api.postmarkapp.com/webhooks',
            ['MessageStream' => 'outbound']
        )->andReturn(new ApiResponse(200, ['Webhooks' => []]));
        $this->client->shouldReceive('post')->once()->with(
            'https://api.postmarkapp.com/webhooks',
            Mockery::on(static function (array $body): bool {
                return $body['Url']           === 'https://example.test/bit-smtp/conn_1/secret'
                    && $body['MessageStream'] === 'outbound'
                    && $body['Triggers']      === [
                        'Delivery'      => ['Enabled' => true],
                        'Bounce'        => ['Enabled' => true, 'IncludeContent' => false],
                        'SpamComplaint' => ['Enabled' => true, 'IncludeContent' => false],
                    ];
            })
        )->andReturn(new ApiResponse(200, ['ID' => 99]));

        $result = (new PostmarkWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => '99'], $result);
    }

    public function testReusesWebhookWithSameEndpointInsteadOfCreatingDuplicate(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'Webhooks' => [
                ['ID' => 5, 'Url' => 'https://example.test/bit-smtp/conn_1/secret'],
            ],
        ]));
        $this->client->shouldNotReceive('post');

        $result = (new PostmarkWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => false, 'id' => '5'], $result);
    }

    public function testThrowsWithoutLeakingSecretWhenCreationFails(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, ['Webhooks' => []]));
        $this->client->shouldReceive('post')->once()->andReturn(new ApiResponse(422, ['Message' => 'Invalid webhook URL']));

        try {
            (new PostmarkWebhookService($this->client, $this->auth))->ensure($this->connection());
            $this->fail('Expected a RuntimeException for the failed webhook creation.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertStringNotContainsString('pm-token-123', $exception->getMessage());
        }
    }

    public function testDeregisterDeletesTheWebhookMatchingOurUrl(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(
            'https://api.postmarkapp.com/webhooks',
            ['MessageStream' => 'outbound']
        )->andReturn(new ApiResponse(200, [
            'Webhooks' => [
                ['ID' => 2, 'Url' => 'https://other.test/hook'],
                ['ID' => 5, 'Url' => 'https://example.test/bit-smtp/conn_1/secret'],
            ],
        ]));
        $this->client->shouldReceive('delete')->once()->with('https://api.postmarkapp.com/webhooks/5')->andReturn(new ApiResponse(200, []));

        (new PostmarkWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenNoWebhookMatchesOurUrl(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'Webhooks' => [
                ['ID' => 2, 'Url' => 'https://other.test/hook'],
            ],
        ]));
        $this->client->shouldNotReceive('delete');

        (new PostmarkWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenListingFails(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(500, []));
        $this->client->shouldNotReceive('delete');

        (new PostmarkWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    /**
     * Stand-in for the Postmark api_key strategy: writes the connection's api_key as the server-token
     * header, proving the provisioner forwards whatever the auth strategy produced onto the client.
     */
    private function serverTokenAuth(): AuthStrategyInterface
    {
        $auth = Mockery::mock(AuthStrategyInterface::class);
        $auth->shouldReceive('apply')->once()->andReturnUsing(static function (ApiRequest $request, Connection $connection): void {
            $request->setHeader('X-Postmark-Server-Token', $connection->getCredentials()['api_key']['value'] ?? '');
        });

        return $auth;
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'postmark',
            'kind'        => 'api',
            'name'        => 'Postmark',
            'settings'    => ['webhook_secret' => 'secret'],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'pm-token-123']],
        ]);
    }
}
