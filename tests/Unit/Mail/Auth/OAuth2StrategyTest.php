<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\OAuth2Strategy;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Support\ApiRequest;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class OAuth2StrategyTest extends BaseUnitTestCase
{
    public function testApplySetsBearerAuthorizationHeaderFromResolvedAccessToken(): void
    {
        $provider   = Mockery::mock(OAuth2ProviderInterface::class);
        $connection = $this->connection();
        $tokens     = Mockery::mock(OAuth2TokenProvider::class);
        $tokens->shouldReceive('accessToken')
            ->once()
            ->with($connection, $provider)
            ->andReturn('gm-access-token');

        $strategy = new OAuth2Strategy($tokens, $provider);
        $request  = $this->request();

        $strategy->apply($request, $connection);

        $this->assertSame('Bearer gm-access-token', $request->headers['Authorization']);
    }

    public function testTypeReturnsOauth2(): void
    {
        $strategy = new OAuth2Strategy(
            Mockery::mock(OAuth2TokenProvider::class),
            Mockery::mock(OAuth2ProviderInterface::class)
        );

        $this->assertSame('oauth2', $strategy->type());
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'       => 'conn-1',
            'provider' => 'gmail',
            'kind'     => 'api',
        ]);
    }

    private function request(): ApiRequest
    {
        return new ApiRequest('POST', 'https://example.test/send', '{}', 'application/json');
    }
}
