<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Status;

use BitApps\SMTP\Mail\Contracts\MessageStatusCheckerInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Status\StatusCheckerRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
class StatusCheckerRegistryTest extends BaseUnitTestCase
{
    private StatusCheckerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new StatusCheckerRegistry(Mockery::mock(ApiClient::class));
    }

    public function testMessageIdFromBrevoReadsTheMessageIdFromTheDebugBody(): void
    {
        $this->assertSame('abc-123', $this->registry->messageIdFrom('brevo', ['body' => ['messageId' => 'abc-123']]));
    }

    public function testMessageIdFromBrevoReturnsNullWhenThereIsNoBody(): void
    {
        $this->assertNull($this->registry->messageIdFrom('brevo', ['exception' => 'Foo']));
    }

    public function testMessageIdFromReturnsNullForOtherProviders(): void
    {
        $this->assertNull($this->registry->messageIdFrom('sendgrid', ['body' => ['messageId' => 'abc-123']]));
    }

    public function testCheckerForBrevoReturnsAStatusChecker(): void
    {
        $this->assertInstanceOf(MessageStatusCheckerInterface::class, $this->registry->checkerFor('brevo'));
    }

    public function testCheckerForUnknownProviderReturnsNull(): void
    {
        $this->assertNull($this->registry->checkerFor('unknown-provider'));
    }
}
