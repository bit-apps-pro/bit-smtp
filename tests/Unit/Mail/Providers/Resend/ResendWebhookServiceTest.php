<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Resend;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Providers\Resend\ResendWebhookService;
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
final class ResendWebhookServiceTest extends BaseUnitTestCase
{
    private const ENDPOINT = 'https://api.resend.com/webhooks';

    private const WEBHOOK_URL = 'https://example.test/bit-smtp/conn_1/secret';

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
        $this->auth   = $this->bearerAuth();
    }

    public function testCreatesWebhookWithDeliverabilityEventsAndReturnsSigningSecret(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->with(Mockery::on(static function (array $headers): bool {
            return ($headers['Authorization'] ?? '') === 'Bearer re_key123'
                && ($headers['Content-Type'] ?? '')  === 'application/json';
        }))->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::ENDPOINT)->andReturn(new ApiResponse(200, ['data' => []]));
        $this->client->shouldReceive('post')->once()->with(
            self::ENDPOINT,
            Mockery::on(static function (array $body): bool {
                return $body['endpoint'] === self::WEBHOOK_URL
                    && $body['events']   === ['email.delivered', 'email.bounced', 'email.delivery_delayed', 'email.failed'];
            })
        )->andReturn(new ApiResponse(201, ['id' => 're_wh_1', 'signing_secret' => 'whsec_live']));

        $result = (new ResendWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => 're_wh_1', 'signing_secret' => 'whsec_live'], $result);
    }

    public function testReusesWebhookWithSameEndpointAndOmitsSigningSecret(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'data' => [
                ['id' => 're_wh_existing', 'endpoint' => self::WEBHOOK_URL],
            ],
        ]));
        $this->client->shouldNotReceive('post');

        $result = (new ResendWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => false, 'id' => 're_wh_existing'], $result);
        $this->assertArrayNotHasKey('signing_secret', $result);
    }

    public function testCreatedWebhookWithoutSigningSecretStillSucceedsWithoutTheKey(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, ['data' => []]));
        $this->client->shouldReceive('post')->once()->andReturn(new ApiResponse(201, ['id' => 're_wh_2']));

        $result = (new ResendWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => 're_wh_2'], $result);
        $this->assertArrayNotHasKey('signing_secret', $result);
    }

    public function testThrowsWhenApiRejectsTheRequest(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(422, ['message' => 'Invalid endpoint']));
        $this->client->shouldNotReceive('post');

        $this->expectException(RuntimeException::class);

        (new ResendWebhookService($this->client, $this->auth))->ensure($this->connection());
    }

    /**
     * Stand-in for BearerTokenStrategy: writes the connection's api_key as a Bearer header onto the
     * request, proving the provisioner forwards whatever the auth strategy produced onto the client.
     */
    private function bearerAuth(): AuthStrategyInterface
    {
        $auth = Mockery::mock(AuthStrategyInterface::class);
        $auth->shouldReceive('apply')->once()->andReturnUsing(static function (ApiRequest $request, Connection $connection): void {
            $request->setHeader('Authorization', 'Bearer ' . ($connection->getCredentials()['api_key']['value'] ?? ''));
        });

        return $auth;
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'resend',
            'kind'        => 'api',
            'name'        => 'Resend',
            'settings'    => ['webhook_secret' => 'secret'],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 're_key123']],
        ]);
    }
}
