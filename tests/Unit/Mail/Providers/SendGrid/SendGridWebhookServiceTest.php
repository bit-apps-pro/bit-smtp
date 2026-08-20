<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\AuthStrategyInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridWebhookService;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class SendGridWebhookServiceTest extends BaseUnitTestCase
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
        $this->auth   = $this->bearerAuth();
    }

    public function testCreatesWebhookWithDeliverabilityEvents(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->with(Mockery::on(static function (array $headers): bool {
            return ($headers['Authorization'] ?? '') === 'Bearer SG.key123'
                && ($headers['Content-Type'] ?? '')  === 'application/json';
        }))->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, []));
        $this->client->shouldReceive('post')->once()->with(
            'https://api.sendgrid.com/v3/user/webhooks/event/settings',
            Mockery::on(static function (array $body): bool {
                return $body['enabled']     === true
                    && $body['url']         === 'https://example.test/bit-smtp/conn_1/secret'
                    && $body['processed']   === true
                    && $body['dropped']     === true
                    && $body['deferred']    === true
                    && $body['bounce']      === true
                    && $body['delivered']   === true
                    && $body['spam_report'] === true;
            })
        )->andReturn(new ApiResponse(201, ['id' => 'sg_wh_1', 'url' => 'https://example.test/bit-smtp/conn_1/secret']));
        $this->client->shouldReceive('patch')->once()->with(
            'https://api.sendgrid.com/v3/user/webhooks/event/settings/signed/sg_wh_1',
            ['enabled' => true]
        )->andReturn(new ApiResponse(200, ['public_key' => 'PUBLIC KEY']));

        $result = (new SendGridWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => true, 'id' => 'sg_wh_1', 'public_key' => 'PUBLIC KEY'], $result);
    }

    public function testReusesWebhookWithSameEndpointInsteadOfCreatingDuplicate(): void
    {
        $this->client->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->client->shouldReceive('get')->once()->andReturn(new ApiResponse(200, [
            'webhooks' => [
                ['id' => 'sg_wh_existing', 'url' => 'https://example.test/bit-smtp/conn_1/secret'],
            ],
        ]));
        $this->client->shouldNotReceive('post');
        $this->client->shouldReceive('patch')->once()->andReturn(new ApiResponse(200, ['public_key' => 'PUBLIC KEY']));

        $result = (new SendGridWebhookService($this->client, $this->auth))->ensure($this->connection());

        $this->assertSame(['created' => false, 'id' => 'sg_wh_existing', 'public_key' => 'PUBLIC KEY'], $result);
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
            'provider'    => 'sendgrid',
            'kind'        => 'api',
            'name'        => 'SendGrid',
            'settings'    => ['webhook_secret' => 'secret'],
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'SG.key123']],
        ]);
    }
}
