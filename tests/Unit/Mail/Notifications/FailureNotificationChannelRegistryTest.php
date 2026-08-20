<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;
use Mockery;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotificationChannelRegistryTest extends BaseUnitTestCase
{
    public function testItReturnsRegisteredChannelsByKeyAndAsACollection(): void
    {
        $slack    = $this->channel('slack');
        $telegram = $this->channel('telegram');

        $registry = new FailureNotificationChannelRegistry([$slack, $telegram]);

        $this->assertSame($slack, $registry->get('slack'));
        $this->assertSame($telegram, $registry->get('telegram'));
        $this->assertNull($registry->get('unknown'));
        $this->assertSame(['slack' => $slack, 'telegram' => $telegram], $registry->all());
    }

    public function testItRejectsDuplicateChannelKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FailureNotificationChannelRegistry([$this->channel('slack'), $this->channel('slack')]);
    }

    private function channel(string $key): FailureNotificationChannelInterface
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->once()->andReturn($key);

        return $channel;
    }
}
