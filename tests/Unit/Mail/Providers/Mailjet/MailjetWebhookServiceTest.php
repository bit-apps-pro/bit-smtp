<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\Mailjet;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Providers\Mailjet\MailjetWebhookService;
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
final class MailjetWebhookServiceTest extends BaseUnitTestCase
{
    private const ENDPOINT = 'https://api.mailjet.com/v3/REST/eventcallbackurl';

    private const WEBHOOK_URL = 'https://example.test/bit-smtp/conn_1/secret';

    private const EVENT_IDS = ['sent' => 111, 'bounce' => 222, 'blocked' => 333, 'spam' => 444];

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
        $this->auth   = $this->basicAuth();
    }

    public function testRegistersACallbackUrlForEveryDeliverabilityEventType(): void
    {
        $this->expectSignedForJson();
        $this->client->shouldReceive('get')->once()->with(self::ENDPOINT)
            ->andReturn(new ApiResponse(200, ['Count' => 0, 'Data' => []]));

        $posted = [];
        $this->client->shouldReceive('post')->times(4)->with(
            self::ENDPOINT,
            Mockery::on(static function (array $body) use (&$posted): bool {
                $posted[] = $body['EventType'];

                return $body['Url']     === self::WEBHOOK_URL
                    && $body['Version'] === 2
                    && $body['Status']  === 'alive';
            })
        )->andReturnUsing(static fn (string $endpoint, array $body): ApiResponse => self::created($body['EventType']));

        $result = (new MailjetWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['sent', 'bounce', 'blocked', 'spam'], $posted);
        $this->assertSame(['created' => true, 'id' => '111'], $result);
    }

    public function testReusesEveryCallbackAlreadyPointingAtOurUrlWithoutPosting(): void
    {
        $this->expectSignedForJson();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'Count' => 4,
            'Data'  => [
                $this->existing('sent'),
                $this->existing('bounce'),
                $this->existing('blocked'),
                $this->existing('spam'),
            ],
        ]));
        $this->client->shouldNotReceive('post');

        $result = (new MailjetWebhookService($this->client, $this->auth))->ensure($this->connection());

        // Any callback already pointing at our URL is an equally valid reuse marker; the loop reports the
        // last one it sees (spam).
        $this->assertSame(['created' => false, 'id' => '444'], $result);
    }

    public function testRegistersOnlyTheEventTypesNotYetPointingAtOurUrl(): void
    {
        $this->expectSignedForJson();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'Count' => 1,
            'Data'  => [$this->existing('sent')],
        ]));

        $posted = [];
        $this->client->shouldReceive('post')->times(3)->with(
            self::ENDPOINT,
            Mockery::on(static function (array $body) use (&$posted): bool {
                $posted[] = $body['EventType'];

                return $body['Url'] === self::WEBHOOK_URL;
            })
        )->andReturnUsing(static fn (string $endpoint, array $body): ApiResponse => self::created($body['EventType']));

        $result = (new MailjetWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['bounce', 'blocked', 'spam'], $posted);
        $this->assertSame(['created' => true, 'id' => '222'], $result);
    }

    public function testThrowsWhenRegisteringACallbackFails(): void
    {
        $this->expectSignedForJson();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, ['Count' => 0, 'Data' => []]));
        $this->client->shouldReceive('post')->once()->andReturn(new ApiResponse(400, ['ErrorMessage' => 'nope']));

        $this->expectException(RuntimeException::class);

        (new MailjetWebhookService($this->client, $this->auth))->ensure($this->connection());
    }

    private static function created(string $eventType): ApiResponse
    {
        return new ApiResponse(201, [
            'Count' => 1,
            'Data'  => [['ID' => self::EVENT_IDS[$eventType], 'EventType' => $eventType, 'Url' => self::WEBHOOK_URL]],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function existing(string $eventType): array
    {
        return ['ID' => self::EVENT_IDS[$eventType], 'EventType' => $eventType, 'Url' => self::WEBHOOK_URL, 'Status' => 'alive', 'Version' => 2];
    }

    private function expectSignedForJson(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->with(Mockery::on(static function (array $headers): bool {
            return ($headers['Authorization'] ?? '') === 'Basic ' . base64_encode('public:private')
                && ($headers['Content-Type'] ?? '')  === 'application/json';
        }))->andReturnSelf();
    }

    /**
     * Stand-in for BasicAuthStrategy: writes the connection's key pair as a Basic header, proving the
     * provisioner forwards whatever the auth strategy produced onto the client.
     */
    private function basicAuth(): AuthStrategyInterface
    {
        $auth = Mockery::mock(AuthStrategyInterface::class);
        $auth->shouldReceive('apply')->once()->andReturnUsing(static function (ApiRequest $request, Connection $connection): void {
            $credentials = $connection->getCredentials();
            $token       = base64_encode(($credentials['api_key']['value'] ?? '') . ':' . ($credentials['secret_key']['value'] ?? ''));
            $request->setHeader('Authorization', 'Basic ' . $token);
        });

        return $auth;
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1',
            'provider'    => 'mailjet',
            'kind'        => 'api',
            'name'        => 'Mailjet',
            'settings'    => ['webhook_secret' => 'secret'],
            'credentials' => [
                'api_key'    => ['source' => 'database', 'value' => 'public'],
                'secret_key' => ['source' => 'database', 'value' => 'private'],
            ],
        ]);
    }
}
