<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Mailgun;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Providers\Mailgun\MailgunWebhookService;
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
final class MailgunWebhookServiceTest extends BaseUnitTestCase
{
    private const API_KEY = 'mg-key-123';

    private const WEBHOOK_URL = 'https://example.test/bit-smtp/conn_1/secret';

    private const US_BASE = 'https://api.mailgun.net/v3/domains/mg.example.com/webhooks';

    private const EU_BASE = 'https://api.eu.mailgun.net/v3/domains/mg.example.com/webhooks';

    private const EVENTS = ['delivered', 'permanent_fail', 'temporary_fail'];

    private ApiClient $client;

    /**
     * @var AuthStrategyInterface|Mockery\MockInterface
     */
    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('home_url')->alias(static fn (string $path): string => 'https://example.test' . $path);
        $this->client = Mockery::mock(ApiClient::class);
        $this->auth   = $this->basicAuth();
    }

    public function testRegistersEveryTrackedEventWhenNonePresent(): void
    {
        $expectedAuth = 'Basic ' . base64_encode('api:' . self::API_KEY);
        $this->client->shouldReceive('setHeaders')->once()->with(Mockery::on(static function (array $headers) use ($expectedAuth): bool {
            return ($headers['Authorization'] ?? '') === $expectedAuth
                && ($headers['Content-Type'] ?? '')  === 'application/x-www-form-urlencoded';
        }))->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_BASE)->andReturn(new ApiResponse(200, ['webhooks' => []]));
        foreach (self::EVENTS as $event) {
            $this->client->shouldReceive('post')->once()->with(
                self::US_BASE,
                http_build_query(['id' => $event, 'url' => self::WEBHOOK_URL])
            )->andReturn(new ApiResponse(200, []));
        }

        $result = (new MailgunWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => 'mg.example.com'], $result);
    }

    public function testReusesWebhooksAlreadyPointingAtOurUrlWithoutPosting(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_BASE)->andReturn(new ApiResponse(200, [
            'webhooks' => [
                'delivered'      => ['urls' => [self::WEBHOOK_URL]],
                'permanent_fail' => ['urls' => [self::WEBHOOK_URL]],
                'temporary_fail' => ['urls' => ['https://old.example/hook', self::WEBHOOK_URL]],
            ],
        ]));
        $this->client->shouldNotReceive('post');

        $result = (new MailgunWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => false, 'id' => 'mg.example.com'], $result);
    }

    public function testRegistersOnlyTheMissingEventsWhenSomeAlreadyPresent(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_BASE)->andReturn(new ApiResponse(200, [
            'webhooks' => ['delivered' => ['urls' => [self::WEBHOOK_URL]]],
        ]));
        $this->client->shouldReceive('post')->once()->with(
            self::US_BASE,
            http_build_query(['id' => 'permanent_fail', 'url' => self::WEBHOOK_URL])
        )->andReturn(new ApiResponse(200, []));
        $this->client->shouldReceive('post')->once()->with(
            self::US_BASE,
            http_build_query(['id' => 'temporary_fail', 'url' => self::WEBHOOK_URL])
        )->andReturn(new ApiResponse(200, []));

        $result = (new MailgunWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => 'mg.example.com'], $result);
    }

    public function testThrowsForAnInvalidDomainBeforeTouchingTheClient(): void
    {
        foreach (['', 'evil.com/inject', 'localhost'] as $domain) {
            $client = Mockery::mock(ApiClient::class);
            $client->shouldNotReceive('setHeaders');
            $client->shouldNotReceive('get');
            $client->shouldNotReceive('post');

            $connection = $this->connection(['settings' => ['domain' => $domain, 'webhook_secret' => 'secret']]);

            try {
                (new MailgunWebhookService($client, $this->auth))->ensure($connection);
                $this->fail("Expected a RuntimeException for domain: {$domain}");
            } catch (RuntimeException $exception) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testThrowsWhenMailgunRejectsARegistration(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, ['webhooks' => []]));
        $this->client->shouldReceive('post')->once()->andReturn(new ApiResponse(400, ['message' => 'bad request']));

        $this->expectException(RuntimeException::class);

        (new MailgunWebhookService($this->client, $this->auth))->ensure($this->connection());
    }

    public function testTargetsTheEuHostWhenRegionIsEu(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::EU_BASE)->andReturn(new ApiResponse(200, ['webhooks' => []]));
        foreach (self::EVENTS as $event) {
            $this->client->shouldReceive('post')->once()->with(
                self::EU_BASE,
                http_build_query(['id' => $event, 'url' => self::WEBHOOK_URL])
            )->andReturn(new ApiResponse(200, []));
        }

        $connection = $this->connection(['settings' => ['domain' => 'mg.example.com', 'region' => 'eu', 'webhook_secret' => 'secret']]);
        $result     = (new MailgunWebhookService($this->client, $this->auth))->ensure($connection);

        $this->assertSame(['created' => true, 'id' => 'mg.example.com'], $result);
    }

    public function testDeregisterDeletesEveryEventWhoseSoleUrlIsOurs(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_BASE)->andReturn(new ApiResponse(200, [
            'webhooks' => [
                'delivered'      => ['urls' => [self::WEBHOOK_URL]],
                'permanent_fail' => ['urls' => [self::WEBHOOK_URL]],
                'temporary_fail' => ['urls' => [self::WEBHOOK_URL]],
            ],
        ]));
        foreach (self::EVENTS as $event) {
            $this->client->shouldReceive('delete')->once()->with(self::US_BASE . '/' . $event)->andReturn(new ApiResponse(200, []));
        }

        (new MailgunWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterSkipsAnEventThatSharesOurUrlWithACoTenant(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_BASE)->andReturn(new ApiResponse(200, [
            'webhooks' => [
                'delivered'      => ['urls' => [self::WEBHOOK_URL]],
                'permanent_fail' => ['urls' => [self::WEBHOOK_URL]],
                'temporary_fail' => ['urls' => ['https://old.example/hook', self::WEBHOOK_URL]],
            ],
        ]));
        $this->client->shouldReceive('delete')->once()->with(self::US_BASE . '/delivered')->andReturn(new ApiResponse(200, []));
        $this->client->shouldReceive('delete')->once()->with(self::US_BASE . '/permanent_fail')->andReturn(new ApiResponse(200, []));
        $this->client->shouldNotReceive('delete')->with(self::US_BASE . '/temporary_fail');

        (new MailgunWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenNoEventPointsAtOurUrl(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_BASE)->andReturn(new ApiResponse(200, [
            'webhooks' => [
                'delivered' => ['urls' => ['https://old.example/hook']],
            ],
        ]));
        $this->client->shouldNotReceive('delete');

        (new MailgunWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    public function testDeregisterIsANoOpWhenListingFails(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->with(self::US_BASE)->andReturn(new ApiResponse(500, []));
        $this->client->shouldNotReceive('delete');

        (new MailgunWebhookService($this->client, $this->auth))->deregister($this->connection());
    }

    /**
     * Stand-in for the Basic strategy: writes "api:{api_key}" as a Basic header onto the request,
     * proving the provisioner forwards whatever the auth strategy produced onto the client.
     */
    private function basicAuth(): AuthStrategyInterface
    {
        $auth = Mockery::mock(AuthStrategyInterface::class);
        $auth->shouldReceive('apply')->andReturnUsing(static function (ApiRequest $request, Connection $connection): void {
            $key = $connection->getCredentials()['api_key']['value'] ?? '';
            $request->setHeader('Authorization', 'Basic ' . base64_encode('api:' . $key));
        });

        return $auth;
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id'          => 'conn_1',
            'provider'    => 'mailgun',
            'kind'        => 'api',
            'name'        => 'Mailgun',
            'settings'    => ['domain' => 'mg.example.com', 'webhook_secret' => 'secret'],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => self::API_KEY]],
        ], $overrides));
    }
}
