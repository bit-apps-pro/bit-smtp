<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\BearerTokenStrategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Exceptions\AuthConfigException;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class BearerTokenStrategyTest extends BaseUnitTestCase
{
    public function testApplySetsBearerAuthorizationHeaderFromDefaultCredentialKey(): void
    {
        $strategy = new BearerTokenStrategy();
        $request  = $this->request();

        $strategy->apply($request, $this->connection('SG.k'));

        $this->assertSame('Bearer SG.k', $request->headers['Authorization']);
    }

    public function testApplyUsesConfiguredCredentialKey(): void
    {
        $strategy   = new BearerTokenStrategy(['credentialKey' => 'access_token']);
        $connection = Connection::fromArray([
            'id'          => 'conn-1',
            'provider'    => 'gmail',
            'kind'        => 'api',
            'credentials' => ['access_token' => ['source' => 'db', 'value' => 'gm-token']],
        ]);
        $request = $this->request();

        $strategy->apply($request, $connection);

        $this->assertSame('Bearer gm-token', $request->headers['Authorization']);
    }

    public function testTypeReturnsBearer(): void
    {
        $this->assertSame('bearer', (new BearerTokenStrategy())->type());
    }

    public function testApplyThrowsWhenCredentialMissing(): void
    {
        $strategy = new BearerTokenStrategy();

        $this->expectException(AuthConfigException::class);

        $strategy->apply($this->request(), Connection::fromArray([
            'id'       => 'conn-1',
            'provider' => 'sendgrid',
            'kind'     => 'api',
        ]));
    }

    private function connection(string $apiKey): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn-1',
            'provider'    => 'sendgrid',
            'kind'        => 'api',
            'credentials' => ['api_key' => ['source' => 'db', 'value' => $apiKey]],
        ]);
    }

    private function request(): ApiRequest
    {
        return new ApiRequest('POST', 'https://example.test/send', '{}', 'application/json');
    }
}
