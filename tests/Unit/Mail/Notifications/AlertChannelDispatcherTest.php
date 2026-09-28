<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
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
        $dispatcher = $this->dispatcher($this->alerts(true, [$this->instance('ch_email01', 'email', false)]), [$channel]);

        $this->assertFalse($dispatcher->hasEnabledChannel());
        $this->assertFalse($dispatcher->dispatch($this->message()));
    }

    public function testReturnsFalseWhenTheAlertsMasterSwitchIsOff(): void
    {
        $channel = $this->channel('email');
        $channel->shouldReceive('send')->never();
        $dispatcher = $this->dispatcher($this->alerts(false, [$this->instance('ch_email01', 'email')]), [$channel]);

        $this->assertFalse($dispatcher->hasEnabledChannel());
        $this->assertFalse($dispatcher->dispatch($this->message()));
    }

    public function testReturnsFalseForAnEmptyOrGarbageAlertsBlob(): void
    {
        $channel = $this->channel('email');
        $channel->shouldReceive('send')->never();

        foreach ([[], ['enabled' => true, 'channels' => 'nope'], ['enabled' => true, 'email' => ['enabled' => true]]] as $alerts) {
            $dispatcher = $this->dispatcher($alerts, [$channel]);
            $this->assertFalse($dispatcher->hasEnabledChannel());
            $this->assertFalse($dispatcher->dispatch($this->message()));
        }
    }

    public function testDeliversThroughEachEnabledChannelWithItsOwnSettings(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')
            ->once()
            ->with(Mockery::type(NotificationMessage::class), ['recipients' => ['ops@example.com']])
            ->andReturn(ChannelDeliveryResult::delivered());
        $dispatcher = $this->dispatcher($this->alerts(true, [
            $this->instance('ch_email01', 'email', true, ['recipients' => ['ops@example.com']]),
        ]), [$email]);

        $this->assertTrue($dispatcher->hasEnabledChannel());
        $this->assertTrue($dispatcher->dispatch($this->message()));
    }

    public function testDeliversToEveryEnabledInstanceOfTheSameTypeAndSkipsDisabledOnes(): void
    {
        $webhook = $this->channel('webhook');
        $webhook->shouldReceive('send')
            ->once()
            ->with(Mockery::type(NotificationMessage::class), ['url' => 'https://a.example.com/hook'])
            ->andReturn(ChannelDeliveryResult::failed('Rejected.'));
        $webhook->shouldReceive('send')
            ->once()
            ->with(Mockery::type(NotificationMessage::class), ['url' => 'https://b.example.com/hook'])
            ->andReturn(ChannelDeliveryResult::delivered());
        $dispatcher = $this->dispatcher($this->alerts(true, [
            $this->instance('ch_hookaaa', 'webhook', true, ['url' => 'https://a.example.com/hook']),
            $this->instance('ch_hookbbb', 'webhook', true, ['url' => 'https://b.example.com/hook']),
            $this->instance('ch_hookccc', 'webhook', false, ['url' => 'https://c.example.com/hook']),
        ]), [$webhook]);

        $this->assertTrue($dispatcher->dispatch($this->message()));
    }

    public function testAThrowingChannelIsIsolatedAndTheNextStillDelivers(): void
    {
        $slack    = $this->channel('slack');
        $telegram = $this->channel('telegram');
        $slack->shouldReceive('send')->once()->andThrow(new RuntimeException('provider body: secret'));
        $telegram->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::delivered());
        $dispatcher = $this->dispatcher($this->alerts(true, [
            $this->instance('ch_slack01', 'slack'),
            $this->instance('ch_tele001', 'telegram'),
        ]), [$slack, $telegram]);

        $this->assertTrue($dispatcher->dispatch($this->message()));
    }

    public function testReturnsFalseWhenEveryEnabledChannelFailsToDeliver(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(ChannelDeliveryResult::failed('Rejected.'));
        $dispatcher = $this->dispatcher($this->alerts(true, [$this->instance('ch_email01', 'email')]), [$email]);

        $this->assertFalse($dispatcher->dispatch($this->message()));
    }

    /**
     * An alerts blob in the channel-list shape.
     */
    private function alerts(bool $enabled, array $channels): array
    {
        return ['enabled' => $enabled, 'channels' => $channels];
    }

    /**
     * One channel instance in the stored shape.
     */
    private function instance(string $id, string $type, bool $enabled = true, array $settings = []): array
    {
        return ['id' => $id, 'type' => $type, 'name' => '', 'enabled' => $enabled, 'settings' => $settings];
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
