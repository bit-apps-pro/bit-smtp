<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthStatus;
use BitApps\SMTP\Mail\Health\HealthTransition;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Tests health transitions queue WP-Cron events that land alerts in mailpit, gated by notify_events.
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
        wp_unschedule_hook(Config::HEALTH_ALERT_HOOK);
    }

    protected function tearDown(): void
    {
        delete_option(ConnectionHealthStore::OPTION_NAME);
        wp_unschedule_hook(Config::HEALTH_ALERT_HOOK);
        parent::tearDown();
    }

    public function testAnUnhealthyTransitionIsQueuedWithIdentifiersOnlyNotDeliveredInline(): void
    {
        $this->configureAlerts([HealthNotification::EVENT_UNHEALTHY]);
        $this->recordUnhealthy();

        $this->deliveringToMailpit(function (): void {
            $this->notifier()->notifyTransitions([$this->unhealthyTransition()]);
        });

        $this->assertEmpty($this->mailpitMessages(), 'the request path must not deliver the alert inline');
        $queued = $this->queuedAlerts();
        $this->assertCount(1, $queued);
        $this->assertSame([HealthNotification::EVENT_UNHEALTHY, 'conn_smtp'], \array_slice($queued[0], 0, 2));
        $this->assertIsInt($queued[0][2]);
        $this->assertStringNotContainsString('ops@example.org', (string) wp_json_encode(_get_cron_array()));
    }

    public function testAnUnhealthyTransitionDeliversAnEmailAlert(): void
    {
        $this->configureAlerts([HealthNotification::EVENT_UNHEALTHY]);
        $this->recordUnhealthy();

        $this->deliveringToMailpit(function (): void {
            $this->notifier()->notifyTransitions([$this->unhealthyTransition()]);
            $this->runQueuedAlerts();
        });

        $message = $this->latestMailpitMessage();
        $this->assertNotNull($message, 'the unhealthy transition should deliver an email alert');
        $this->assertStringContainsString('Connection unhealthy', (string) ($message['Subject'] ?? ''));

        $record = (new ConnectionHealthStore())->get('conn_smtp');
        $this->assertNotNull($record);
        $this->assertSame(HealthNotification::EVENT_UNHEALTHY, $record->getLastAlertedState());
    }

    public function testNoAlertIsSentWhenTheEventIsNotSubscribed(): void
    {
        $this->configureAlerts([HealthNotification::EVENT_RECOVERED]);

        $this->recordUnhealthy();

        $this->deliveringToMailpit(function (): void {
            $this->notifier()->notifyTransitions([$this->unhealthyTransition()]);
            $this->runQueuedAlerts();
        });

        $this->assertEmpty($this->mailpitMessages(), 'an unsubscribed event must not alert');
        $this->assertSame([], $this->queuedAlerts(), 'an unsubscribed event must not even be queued');
    }

    public function testDeactivationClearsQueuedHealthAlerts(): void
    {
        $this->configureAlerts([HealthNotification::EVENT_UNHEALTHY]);
        $this->recordUnhealthy();
        $this->notifier()->notifyTransitions([$this->unhealthyTransition()]);
        $this->assertCount(1, $this->queuedAlerts());

        do_action(Config::withPrefix('deactivate'), false);

        $this->assertSame([], $this->queuedAlerts());
    }

    /**
     * Put the connection's health record into the unhealthy state the queued alert re-checks.
     */
    private function recordUnhealthy(): void
    {
        (new ConnectionHealthStore())->put('conn_smtp', ConnectionHealth::fromArray(['status' => HealthStatus::UNHEALTHY]));
    }

    /**
     * The args of every queued health-alert event, oldest first.
     *
     * @return list<array<int,mixed>>
     */
    private function queuedAlerts(): array
    {
        $queued = [];
        foreach (_get_cron_array() as $hooks) {
            foreach ($hooks[Config::HEALTH_ALERT_HOOK] ?? [] as $event) {
                $queued[] = $event['args'];
            }
        }

        return $queued;
    }

    /**
     * Run every queued health-alert event through the booted plugin's hook, then unschedule them.
     */
    private function runQueuedAlerts(): void
    {
        foreach ($this->queuedAlerts() as $args) {
            do_action_ref_array(Config::HEALTH_ALERT_HOOK, $args);
        }
        wp_unschedule_hook(Config::HEALTH_ALERT_HOOK);
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
                    'enabled'  => true,
                    'channels' => [
                        ['id' => 'ch_email01', 'type' => 'email', 'enabled' => true, 'settings' => ['recipients' => ['ops@example.org']]],
                    ],
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
