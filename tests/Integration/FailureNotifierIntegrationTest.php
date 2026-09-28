<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\AlertScheduler;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use BitApps\SMTP\Mail\Notifications\FailureAlertHandler;
use BitApps\SMTP\Mail\Notifications\FailureIncident;
use BitApps\SMTP\Mail\Notifications\FailureIncidentStore;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use WP_Error;

/**
 * Tests send-failure incident lifecycle: notifier queues, handler runs against real options/WP-Cron.
 *
 * @internal
 *
 * @coversNothing
 */
final class FailureNotifierIntegrationTest extends IntegrationTestCase
{
    private int $now;

    private RecordingFailureNotificationChannel $channel;

    private FailureNotifier $notifier;

    private FailureAlertHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        wp_unschedule_hook(Config::FAILURE_ALERT_HOOK);

        $config = new MailConfigService();
        $config->saveSettings([
            'schema_version'          => 2,
            'enabled'                 => false,
            'default_connection_id'   => '',
            'fallback_connection_ids' => [],
            'connections'             => [],
            'features'                => [
                'alerts' => [
                    'enabled'          => true,
                    'reminder_minutes' => 60,
                    'channels'         => [
                        ['id' => 'ch_email01', 'type' => 'email', 'enabled' => true, 'settings' => ['recipients' => ['ops@example.org']]],
                        ['id' => 'ch_hook001', 'type' => 'webhook', 'enabled' => false, 'settings' => ['url' => '', 'signing_secret' => '']],
                    ],
                ],
            ],
        ]);

        $this->now     = time();
        $this->channel = new RecordingFailureNotificationChannel();
        $dispatcher    = new AlertChannelDispatcher($config, new FailureNotificationChannelRegistry([$this->channel]));
        $incidents     = new FailureIncidentStore(new FailureNotificationGate());
        $clock         = fn (): int => $this->now;

        $this->notifier = new FailureNotifier($dispatcher, $incidents, new AlertScheduler(), $clock);
        $this->handler  = new FailureAlertHandler($dispatcher, $incidents, new AlertScheduler(), $clock);
    }

    protected function tearDown(): void
    {
        wp_unschedule_hook(Config::FAILURE_ALERT_HOOK);
        parent::tearDown();
    }

    public function testTheCronHookIsRegisteredByTheBootedPlugin(): void
    {
        $this->assertNotFalse(has_action(Config::FAILURE_ALERT_HOOK));
    }

    public function testFailuresAreQueuedOnWpCronAndNeverDeliveredInline(): void
    {
        $this->notifier->notifyFailure($this->error());
        $this->notifier->notifyFailure($this->error());

        $this->assertSame([], $this->channel->events);
        $queued = $this->queuedAlerts();
        $this->assertCount(1, $queued);
        $this->assertSame(FailureNotification::EVENT_OPENED, $queued[0][0]);
        $this->assertIsString($queued[0][1]);
        $this->assertSame(1, $queued[0][2]);
        $this->assertCount(3, $queued[0]);
        $cron = (string) wp_json_encode(_get_cron_array());
        foreach (['to@example.org', 'Invoice', 'Connection refused'] as $personal) {
            $this->assertStringNotContainsString($personal, $cron);
        }
    }

    public function testAResolvedAlertStillRendersAfterANewIncidentOpenedAndItsRecordIsThenRemoved(): void
    {
        $this->notifier->notifyFailure($this->error());
        $this->runQueuedAlerts();
        $this->notifier->notifyFailure($this->error());
        $this->notifier->notifySuccess();
        $this->notifier->notifyFailure($this->error());
        $this->assertNotFalse(get_option(FailureIncidentStore::resolvedOptionName()));

        $this->runQueuedAlerts();

        $this->assertSame(
            [[FailureNotification::EVENT_OPENED, 1], [FailureNotification::EVENT_RESOLVED, 2], [FailureNotification::EVENT_OPENED, 1]],
            array_map(static fn (array $event): array => [$event['event'], $event['failed_count']], $this->channel->events)
        );
        $this->assertFalse(get_option(FailureIncidentStore::resolvedOptionName()));
    }

    public function testEventsWhoseStateWasDeletedAreDroppedSilently(): void
    {
        $this->notifier->notifyFailure($this->error());
        Config::deleteOption('failure_notification_active');

        $this->runQueuedAlerts();

        $this->assertSame([], $this->channel->events);
    }

    public function testOpenedReminderAndResolvedAreDeliveredOncePerIncident(): void
    {
        $this->notifier->notifyFailure($this->error());
        $this->notifier->notifyFailure($this->error());
        $this->runQueuedAlerts();

        $this->now += 1800;
        $this->notifier->notifyFailure($this->error());
        $this->runQueuedAlerts();

        $this->now += 1800;
        $this->notifier->notifyFailure($this->error('Still refused'));
        $this->runQueuedAlerts();

        $this->now += 60;
        $this->notifier->notifySuccess();
        $this->runQueuedAlerts();

        $this->assertSame(
            [FailureNotification::EVENT_OPENED, FailureNotification::EVENT_REMINDER, FailureNotification::EVENT_RESOLVED],
            array_column($this->channel->events, 'event')
        );
        $this->assertSame([2, 4, 4], array_column($this->channel->events, 'failed_count'));
        $this->assertSame(3660, $this->channel->events[2]['duration_seconds']);
        $this->assertFalse(Config::getOption('failure_notification_active'));
    }

    public function testAnUndeliveredOpenedAlertIsRetriedAndANewIncidentFollowsASuccess(): void
    {
        $this->channel->accepts = false;
        $this->notifier->notifyFailure($this->error());
        $this->runQueuedAlerts();

        $this->channel->accepts = true;
        $this->notifier->notifyFailure($this->error());
        $this->runQueuedAlerts();

        $this->notifier->notifySuccess();
        $this->runQueuedAlerts();
        $this->notifier->notifyFailure($this->error());
        $this->runQueuedAlerts();

        $this->assertSame(
            [FailureNotification::EVENT_OPENED, FailureNotification::EVENT_OPENED, FailureNotification::EVENT_RESOLVED, FailureNotification::EVENT_OPENED],
            array_column($this->channel->events, 'event')
        );
    }

    public function testAnUndeliveredResolvedAlertIsRetriedLaterAndDeactivationClearsAllIncidentState(): void
    {
        $this->notifier->notifyFailure($this->error());
        $this->runQueuedAlerts();
        $this->channel->accepts = false;
        $this->notifier->notifySuccess();
        $this->runQueuedAlerts();

        $queued = $this->queuedAlerts();
        $this->assertCount(1, $queued);
        $this->assertSame([FailureNotification::EVENT_RESOLVED, 1], [$queued[0][0], $queued[0][3]]);
        $this->assertNotFalse(get_option(FailureIncidentStore::resolvedOptionName()));
        $this->notifier->notifyFailure($this->error());
        $this->assertNotFalse(Config::getOption('failure_notification_active'));

        do_action(Config::withPrefix('deactivate'), false);

        $this->assertSame([], $this->queuedAlerts());
        $this->assertFalse(get_option(FailureIncidentStore::resolvedOptionName()));
        $this->assertFalse(Config::getOption('failure_notification_active'), 'deactivation must also drop the open incident');
    }

    public function testResolvedIncidentsFromSeparateRequestsShareOneCompareAndSwapRow(): void
    {
        $first  = new FailureIncidentStore(new FailureNotificationGate());
        $second = new FailureIncidentStore(new FailureNotificationGate());
        $first->keepResolved($this->resolvedIncident('incident-a'));
        $second->keepResolved($this->resolvedIncident('incident-b'));

        $this->assertNotNull($second->resolved('incident-a'));
        $this->assertNotNull($first->resolved('incident-b'));

        $first->forgetResolved('incident-a');
        $this->assertNull($second->resolved('incident-a'));
        $this->assertNotNull($second->resolved('incident-b'));

        $second->forgetResolved('incident-b');
        $this->assertFalse(get_option(FailureIncidentStore::resolvedOptionName()));
    }

    /**
     * A closed incident with a resolution time.
     */
    private function resolvedIncident(string $id): FailureIncident
    {
        return FailureIncident::start($id, $this->now, $this->error(), null)->withResolution($this->now + 60);
    }

    /**
     * A wp_mail failure with the given message.
     */
    private function error(string $message = 'Connection refused'): WP_Error
    {
        return new WP_Error('wp_mail_failed', $message, ['to' => ['to@example.org'], 'subject' => 'Invoice']);
    }

    /**
     * The args of every queued failure-alert event, oldest first.
     *
     * @return list<array<int,mixed>>
     */
    private function queuedAlerts(): array
    {
        $queued = [];
        foreach (_get_cron_array() as $hooks) {
            foreach ($hooks[Config::FAILURE_ALERT_HOOK] ?? [] as $event) {
                $queued[] = $event['args'];
            }
        }

        return $queued;
    }

    /**
     * Run and unschedule every queued failure-alert event, standing in for a WP-Cron request.
     */
    private function runQueuedAlerts(): void
    {
        foreach (_get_cron_array() as $timestamp => $hooks) {
            foreach ($hooks[Config::FAILURE_ALERT_HOOK] ?? [] as $event) {
                wp_unschedule_event($timestamp, Config::FAILURE_ALERT_HOOK, $event['args']);
                $this->handler->handle(...$event['args']);
            }
        }
    }
}

/**
 * Records the event and running totals of every alert it is handed, accepting or rejecting on demand.
 */
final class RecordingFailureNotificationChannel implements FailureNotificationChannelInterface
{
    public bool $accepts = true;

    /**
     * @var list<array{event:string,failed_count:int,duration_seconds:int}>
     */
    public array $events = [];

    /**
     * The channel key matched against alert instances.
     */
    public function key(): string
    {
        return 'email';
    }

    /**
     * Record the alert and report the configured outcome.
     */
    public function send(NotificationMessage $notification, array $settings): ChannelDeliveryResult
    {
        $incident       = $notification->toArray()['incident'];
        $this->events[] = [
            'event'            => $notification->eventType(),
            'failed_count'     => $incident['failed_count'],
            'duration_seconds' => $incident['duration_seconds'],
        ];

        return $this->accepts ? ChannelDeliveryResult::delivered() : ChannelDeliveryResult::failed('Rejected.');
    }
}
