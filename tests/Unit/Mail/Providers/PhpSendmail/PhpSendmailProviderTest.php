<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\PhpSendmail;

use BitApps\SMTP\Mail\Contracts\TransportInterface;
use BitApps\SMTP\Mail\Contracts\ValidatorInterface;
use BitApps\SMTP\Mail\Providers\PhpSendmail\PhpSendmailProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class PhpSendmailProviderTest extends BaseUnitTestCase
{
    private PhpSendmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new PhpSendmailProvider(Mockery::mock(TransportInterface::class));
    }

    public function testMetadataIdentifiesALocalPhpSendmailProvider(): void
    {
        $this->assertSame('php_sendmail', $this->provider->key());
        $this->assertSame('PHP Sendmail', $this->provider->label());
        $this->assertSame('local', $this->provider->kind());
        $this->assertSame([], $this->provider->fields());
        $this->assertSame([], $this->provider->defaults());
    }

    public function testTransportReturnsInjectedInstance(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $provider  = new PhpSendmailProvider($transport);

        $this->assertSame($transport, $provider->transport());
    }

    public function testValidatorAcceptsTheFieldlessProvider(): void
    {
        $this->assertInstanceOf(ValidatorInterface::class, $this->provider->validator());
        $this->assertSame([], $this->provider->validator()->validate([], []));
    }

    public function testProviderHasNoRemoteAuthTrackingOrDeliveryClaim(): void
    {
        $this->assertSame(['type' => 'none', 'params' => []], $this->provider->authConfig());
        $this->assertSame([], $this->provider->tracking());
        $this->assertNull($this->provider->deliveryStatusOnAccept());
    }
}
