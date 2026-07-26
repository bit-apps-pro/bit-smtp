<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
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

        $notifier = new FailureNotifier($this->config(), $gate, [$email, $webhook]);
        $notifier->notifyFailure(new WP_Error('wp_mail_failed', 'Failed', ['to' => ['to@example.com']]));
    }

    public function testRepeatedFailureIsSuppressedByTheGate(): void
    {
        $email = $this->channel('email');
        $email->shouldReceive('send')->never();
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->once()->withNoArgs()->andReturn(false);

        $notifier = new FailureNotifier($this->config(), $gate, [$email]);
        $notifier->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    public function testDisabledAlertsDoNotAcquireTheGate(): void
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->once()->andReturn($this->settings([]));
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('acquire')->never();

        $notifier = new FailureNotifier($config, $gate, [$this->channel('email')]);
        $notifier->notifyFailure(new WP_Error('wp_mail_failed', 'Failed'));
    }

    public function testSuccessResetsTheFailureEpisode(): void
    {
        $gate = Mockery::mock(FailureNotificationGate::class);
        $gate->shouldReceive('reset')->once();

        (new FailureNotifier($this->config(), $gate, []))->notifySuccess();
    }

    private function config(): MailConfigService
    {
        $config = Mockery::mock(MailConfigService::class);
        $config->shouldReceive('load')->andReturn($this->settings([
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
