<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\SparkPost;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostWebhookService;
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
final class SparkPostWebhookServiceTest extends BaseUnitTestCase
{
    private const US_ENDPOINT = 'https://api.sparkpost.com/api/v1/webhooks';

    private const EU_ENDPOINT = 'https://api.eu.sparkpost.com/api/v1/webhooks';

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
        $this->auth   = $this->apiKeyAuth();
    }

    public function testCreatesWebhookWithDeliverabilityEvents(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->with(Mockery::on(static function (array $headers): bool {
            return ($headers['Authorization'] ?? '') === 'key123'
                && ($headers['Content-Type'] ?? '')  === 'application/json';
        }))->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_ENDPOINT)->andReturn(new ApiResponse(200, ['results' => []]));
        $this->client->shouldReceive('post')->once()->with(
            self::US_ENDPOINT,
            Mockery::on(static function (array $body): bool {
                return $body['name']   === 'SparkPost Bit SMTP'
                    && $body['target'] === self::WEBHOOK_URL
                    && $body['active'] === true
                    && $body['events'] === ['delivery', 'bounce', 'delay', 'policy_rejection', 'out_of_band'];
            })
        )->andReturn(new ApiResponse(200, ['results' => ['id' => 'sp_wh_1']]));

        $result = (new SparkPostWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => 'sp_wh_1'], $result);
    }

    public function testReusesWebhookWithSameTargetInsteadOfCreatingDuplicate(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'results' => [
                ['id' => 'sp_wh_existing', 'target' => self::WEBHOOK_URL],
            ],
        ]));
        $this->client->shouldNotReceive('post');

        $result = (new SparkPostWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => false, 'id' => 'sp_wh_existing'], $result);
    }

    public function testEuRegionConnectionTargetsEuHost(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::EU_ENDPOINT)->andReturn(new ApiResponse(200, ['results' => []]));
        $this->client->shouldReceive('post')->once()->with(self::EU_ENDPOINT, Mockery::type('array'))
            ->andReturn(new ApiResponse(200, ['results' => ['id' => 'sp_eu_1']]));

        $result = (new SparkPostWebhookService($this->client, $this->auth))->ensure($this->connection(['region' => 'eu']));

        $this->assertSame(['created' => true, 'id' => 'sp_eu_1'], $result);
    }

    public function testThrowsWhenApiRejectsTheRequest(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(401, ['errors' => [['message' => 'Unauthorized']]]));
        $this->client->shouldNotReceive('post');

        $this->expectException(RuntimeException::class);

        (new SparkPostWebhookService($this->client, $this->auth))->ensure($this->connection());
    }

    public function testDeregisterDeletesTheWebhookMatchingOurUrl(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_ENDPOINT, [])->andReturn(new ApiResponse(200, [
            'results' => [
                ['id' => 'sp_wh_other', 'target' => 'https://other.test/hook'],
                ['id' => 'sp_wh_ours', 'target' => self::WEBHOOK_URL],
            ],
        ]));
        $this->client->shouldReceive('delete')->once()->with(self::US_ENDPOINT . '/sp_wh_ours')->andReturn(new ApiResponse(200, []));

        (new SparkPostWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenNoWebhookMatchesOurUrl(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'results' => [
                ['id' => 'sp_wh_other', 'target' => 'https://other.test/hook'],
            ],
        ]));
        $this->client->shouldNotReceive('delete');

        (new SparkPostWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenListingFails(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(500, []));
        $this->client->shouldNotReceive('delete');

        (new SparkPostWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    /**
     * Stand-in for the api_key strategy: writes the connection's api_key verbatim as the Authorization
     * header, proving the provisioner forwards whatever the auth strategy produced onto the client.
     */
    private function apiKeyAuth(): AuthStrategyInterface
    {
        $auth = Mockery::mock(AuthStrategyInterface::class);
        $auth->shouldReceive('apply')->once()->andReturnUsing(static function (ApiRequest $request, Connection $connection): void {
            $request->setHeader('Authorization', (string) ($connection->getCredentials()['api_key']['value'] ?? ''));
        });

        return $auth;
    }

    private function connection(array $settings = []): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'sparkpost',
            'kind'        => 'api',
            'name'        => 'SparkPost',
            'settings'    => ['webhook_secret' => 'secret'] + $settings,
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'key123']],
        ]);
    }
}
