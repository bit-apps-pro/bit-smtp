<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\HealthTransition;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Drives a health transition through the real notifier + email channel and asserts the alert lands in
 * mailpit, gated by the notify_events preference. The alert email is a guard-deferred native wp_mail,
 * so a phpmailer_init listener points that native send at mailpit.
 *
 * @internal
 *
 * @coversNothing
 */
final class HealthAlertDeliveryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(ConnectionHealthStore::OPTION_NAME);
    }

    protected function tearDown(): void
    {
        delete_option(ConnectionHealthStore::OPTION_NAME);
        parent::tearDown();
    }

    public function testAnUnhealthyTransitionDeliversAnEmailAlert(): void
    {
        $this->configureAlerts([HealthNotification::EVENT_UNHEALTHY]);

        $this->deliveringToMailpit(function (): void {
            $this->notifier()->notifyTransitions([$this->unhealthyTransition()]);
        });

        $message = $this->latestMailpitMessage();
        $this->assertNotNull($message, 'the unhealthy transition should deliver an email alert');
        $this->assertStringContainsString('Connection unhealthy', (string) ($message['Subject'] ?? ''));

        // The alert is deduped for next time via the health record's marker.
        $record = (new ConnectionHealthStore())->get('conn_smtp');
        $this->assertNotNull($record);
        $this->assertSame(HealthNotification::EVENT_UNHEALTHY, $record->getLastAlertedState());
    }

    public function testNoAlertIsSentWhenTheEventIsNotSubscribed(): void
    {
        // Only 'connection_recovered' is subscribed, so an unhealthy transition must stay silent.
        $this->configureAlerts([HealthNotification::EVENT_RECOVERED]);

        $this->deliveringToMailpit(function (): void {
            $this->notifier()->notifyTransitions([$this->unhealthyTransition()]);
        });

        $this->assertEmpty($this->mailpitMessages(), 'an unsubscribed event must not alert');
    }

    private function notifier(): HealthNotifier
    {
        return Plugin::instance()->app()->make(HealthNotifier::class);
    }

    private function unhealthyTransition(): HealthTransition
    {
        return new HealthTransition(HealthStatus::DEGRADED, HealthStatus::UNHEALTHY, $this->connection());
    }

    private function connection(): Connection
    {
        return Connection::fromArray([
            'id'       => 'conn_smtp',
            'provider' => 'other_smtp',
            'kind'     => 'smtp',
            'name'     => 'Primary SMTP',
            'enabled'  => true,
            'settings' => ['host' => self::SMTP_HOST, 'port' => self::SMTP_PORT],
        ]);
    }

    /**
     * @param string[] $events
     */
    private function configureAlerts(array $events): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => 'conn_smtp',
            'fallback_connection_ids' => [],
            'connections'             => [$this->connection()->toArray()],
            'features'                => [
                'alerts' => [
                    'enabled' => true,
                    'email'   => ['enabled' => true, 'recipients' => ['ops@example.org']],
                ],
            ],
        ]);
        Plugin::instance()->mailConfigService()->reload();
        PluginSettings::make()->set('notify_events', $events)->save();
    }

    /**
     * Run the alert-producing callback with native wp_mail wired to mailpit, then unwire it.
     */
    private function deliveringToMailpit(callable $callback): void
    {
        $this->useRealPhpMailer();
        $listener = static function ($mailer): void {
            $mailer->isSMTP();
            $mailer->Host        = self::SMTP_HOST;
            $mailer->Port        = self::SMTP_PORT;
            $mailer->SMTPAuth    = false;
            $mailer->SMTPAutoTLS = false;
        };
        add_action('phpmailer_init', $listener);

        try {
            $callback();
        } finally {
            remove_action('phpmailer_init', $listener);
        }
    }
}
