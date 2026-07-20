<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Auth;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Auth\AwsSigV4Strategy;
use BitApps\SMTP\Mail\Auth\BearerTokenStrategy;
use BitApps\SMTP\Mail\Auth\OAuth2Strategy;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesProvider;
use BitApps\SMTP\Mail\Providers\Gmail\GmailProvider;
use BitApps\SMTP\Mail\Providers\OtherSmtp\OtherSmtpProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class AuthorizationResolverTest extends BaseUnitTestCase
{
    private AuthorizationResolver $resolver;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver   = new AuthorizationResolver(Mockery::mock(OAuth2TokenProvider::class), new SigV4Signer());
        $this->connection = Connection::fromArray(['id' => 'conn-1', 'provider' => 'x', 'kind' => 'api']);
    }

    public function testResolveReturnsBearerTokenStrategyForSendGrid(): void
    {
        $provider = new SendGridProvider(Mockery::mock(TransportInterface::class));

        $this->assertInstanceOf(BearerTokenStrategy::class, $this->resolver->resolve($provider, $this->connection));
    }

    public function testResolveReturnsOAuth2StrategyForGmail(): void
    {
        $transport = Mockery::mock(TransportInterface::class . ',' . OAuth2ProviderInterface::class);
        $provider  = new GmailProvider($transport);

        $this->assertInstanceOf(OAuth2Strategy::class, $this->resolver->resolve($provider, $this->connection));
    }

    public function testResolveReturnsAwsSigV4StrategyForSes(): void
    {
        $provider = new SesProvider(Mockery::mock(TransportInterface::class));

        $this->assertInstanceOf(AwsSigV4Strategy::class, $this->resolver->resolve($provider, $this->connection));
    }

    public function testResolveThrowsForOtherSmtp(): void
    {
        $provider = new OtherSmtpProvider(Mockery::mock(TransportInterface::class));

        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve($provider, $this->connection);
    }

    public function testResolveThrowsWhenOAuth2ProviderTransportIsNotOAuth2Capable(): void
    {
        $provider = new GmailProvider(Mockery::mock(TransportInterface::class));

        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve($provider, $this->connection);
    }
}
