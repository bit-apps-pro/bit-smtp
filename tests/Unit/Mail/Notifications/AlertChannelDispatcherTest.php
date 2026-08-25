<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class AlertChannelDispatcherTest extends BaseUnitTestCase
{
    public function testSkipsEveryChannelAndReturnsFalseWhenNoneEnabled(): void
    {
        $channel = $this->channel('email');
        $channel->shouldReceive('send')->never();
        $dispatcher = $this->dispatcher(['enabled' => true, 'email' => ['enabled' => false]], [$channel]);

        $this->assertFalse($dispatcher->hasEnabledChannel());
        $this->assertFalse($dispatcher->dispatch($this->message()));
    }

    public function testReturnsFalseWhenTheAlertsMasterSwitchIsOff(): void
    {
        $channel = $this->channel('email');
        $channel->shouldReceive('send')->never();
        $dispatcher = $this->dispatcher(['enabled' => false, 'email' => ['enabled' => true]], [$channel]);

        $this->assertFalse($dispatcher->hasEnabledChannel());
        $this->assertFalse($dispatcher->dispatch($this->message()));
    }

    public function testDeliversThroughEachEnabledChannelWithItsOwnSettings(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')
            ->once()
            ->with(Mockery::type(NotificationMessage::class), ['enabled' => true, 'recipients' => ['ops@example.com']])
            ->andReturn(true);
        $dispatcher = $this->dispatcher([
            'enabled' => true,
            'email'   => ['enabled' => true, 'recipients' => ['ops@example.com']],
        ], [$email]);

        $this->assertTrue($dispatcher->hasEnabledChannel());
        $this->assertTrue($dispatcher->dispatch($this->message()));
    }

    public function testAThrowingChannelIsIsolatedAndTheNextStillDelivers(): void
    {
        $slack    = $this->channel('slack');
        $telegram = $this->channel('telegram');
        $slack->shouldReceive('send')->once()->andThrow(new RuntimeException('provider body: secret'));
        $telegram->shouldReceive('send')->once()->andReturn(true);
        $dispatcher = $this->dispatcher([
            'enabled'  => true,
            'slack'    => ['enabled' => true],
            'telegram' => ['enabled' => true],
        ], [$slack, $telegram]);

        $this->assertTrue($dispatcher->dispatch($this->message()));
    }

    public function testReturnsFalseWhenEveryEnabledChannelFailsToDeliver(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(false);
        $dispatcher = $this->dispatcher(['enabled' => true, 'email' => ['enabled' => true]], [$email]);

        $this->assertFalse($dispatcher->dispatch($this->message()));
    }

    /**
     * @param array<string,mixed>                   $alerts
     * @param FailureNotificationChannelInterface[] $channels
     */
    private function dispatcher(array $alerts, array $channels): AlertChannelDispatcher
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn(MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => ['alerts' => $alerts],
        ]));

        return new AlertChannelDispatcher($config, new FailureNotificationChannelRegistry($channels));
    }

    private function message(): NotificationMessage
    {
        return Mockery::mock(NotificationMessage::class);
    }

    private function channel(string $key): FailureNotificationChannelInterface
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->andReturn($key);

        return $channel;
    }
}
