<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureNotifierIntegrationTest extends IntegrationTestCase
{
    public function testOnlyFirstFailureNotifiesUntilASuccessResetsTheEpisode(): void
    {
        $config = new MailConfigService();
        $config->saveSettings([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => [
                'alerts' => [
                    'enabled' => true,
                    'email'   => ['enabled' => true, 'recipients' => ['ops@example.org']],
                    'webhook' => ['enabled' => false, 'url' => '', 'signing_secret' => ''],
                ],
            ],
        ]);

        $channel  = new CountingFailureNotificationChannel();
        $notifier = new FailureNotifier($config, new FailureNotificationGate(), [$channel]);
        $error    = new WP_Error('wp_mail_failed', 'Connection unavailable');

        $notifier->notifyFailure($error);
        $notifier->notifyFailure($error);
        $this->assertSame(1, $channel->sendCount);

        $notifier->notifySuccess();
        $notifier->notifyFailure($error);
        $this->assertSame(2, $channel->sendCount);
    }
}

final class CountingFailureNotificationChannel implements FailureNotificationChannelInterface
{
    public int $sendCount = 0;

    public function key(): string
    {
        return 'email';
    }

    public function send(FailureNotification $notification, array $settings): bool
    {
        ++$this->sendCount;

        return true;
    }
}
