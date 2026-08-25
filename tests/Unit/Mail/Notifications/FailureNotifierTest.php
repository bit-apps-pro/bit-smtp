<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotifierTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
    }

    public function testFirstFailureSendsEveryEnabledChannel(): void
    {
        $email   = $this->channel('email');
        $webhook = $this->channel('webhook');
        $email->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FailureNotification::class), ['enabled' => true, 'recipients' => ['ops@example.com']])
            ->andReturn(true);
        $webhook->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FailureNotification::class), ['enabled' => true, 'url' => 'https://hooks.example.com/failure'])
            ->andReturn(true);
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->once()->withNoArgs()->andReturn(true);

        $this->notifier($this->config(), $gate, [$email, $webhook])
            ->notifyFailure(new WP_Error('wp_mail_failed', 'Failed', ['to' => ['to@example.com']]));
    }

    public function testRepeatedFailureIsSuppressedByTheGate(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->never();
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->once()->withNoArgs()->andReturn(false);

        $this->notifier($this->config(), $gate, [$email])->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    public function testDisabledAlertsDoNotAcquireTheGate(): void
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->once()->andReturn($this->settings([]));
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->never();

        $this->notifier($config, $gate, [$this->channel('email')])->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    public function testSuccessResetsTheFailureEpisode(): void
    {
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('reset')->once();

        $this->notifier($this->config(), $gate, [])->notifySuccess();
    }

    public function testOneFailingChannelDoesNotStopTheNextEnabledChannel(): void
    {
        $slack    = $this->channel('slack');
        $telegram = $this->channel('telegram');
        $slack->shouldReceive('send')->once()->andThrow(new RuntimeException('provider body: secret'));
        $telegram->shouldReceive('send')->once()->andReturn(true);
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->once()->andReturn(true);

        $config = $this->config([
            'enabled'  => true,
            'slack'    => ['enabled' => true, 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret'],
            'telegram' => ['enabled' => true, 'bot_token' => '123456:secret', 'chat_id' => '-1001234567890'],
        ]);

        $this->notifier($config, $gate, [$slack, $telegram])->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    public function testAllChannelsFailingReleasesTheGate(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(false);
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->once()->andReturn(true);
        $gate->shouldReceive('reset')->once();

        $config = $this->config([
            'enabled' => true,
            'email'   => ['enabled' => true, 'recipients' => ['ops@example.com']],
        ]);

        $this->notifier($config, $gate, [$email])->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    public function testAThrowingChannelReleasesTheGateWhenNoOtherChannelDelivers(): void
    {
        $slack = $this->channel('slack');
        $slack->shouldReceive('send')->once()->andThrow(new RuntimeException('provider body: secret'));
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->once()->andReturn(true);
        $gate->shouldReceive('reset')->once();

        $config = $this->config([
            'enabled' => true,
            'slack'   => ['enabled' => true, 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret'],
        ]);

        $this->notifier($config, $gate, [$slack])->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    public function testADeliveredChannelKeepsTheGateLocked(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->once()->andReturn(true);
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->once()->andReturn(true);
        $gate->shouldReceive('reset')->never();

        $config = $this->config([
            'enabled' => true,
            'email'   => ['enabled' => true, 'recipients' => ['ops@example.com']],
        ]);

        $this->notifier($config, $gate, [$email])->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    /**
     * @param FailureNotificationChannelInterface[] $channels
     */
    private function notifier(MailConfigService $config, FailureNotificationGate $gate, array $channels): FailureNotifier
    {
        return new FailureNotifier(
            new AlertChannelDispatcher($config, new FailureNotificationChannelRegistry($channels)),
            $gate
        );
    }

    private function config(?array $alerts = null): MailConfigService
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn($this->settings($alerts ?? [
            'enabled' => true,
            'email'   => ['enabled' => true, 'recipients' => ['ops@example.com']],
            'webhook' => ['enabled' => true, 'url' => 'https://hooks.example.com/failure'],
        ]));

        return $config;
    }

    private function settings(array $alerts): MailSettings
    {
        return MailSettings::fromArray([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => ['alerts' => $alerts],
        ]);
    }

    private function channel(string $key): FailureNotificationChannelInterface
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->andReturn($key);

        return $channel;
    }
}
