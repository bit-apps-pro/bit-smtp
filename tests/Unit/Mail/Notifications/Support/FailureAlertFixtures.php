<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Support;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Config\MailSettings;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\AlertScheduler;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureAlertHandler;
use BitApps\SMTP\Mail\Notifications\FailureIncident;
use BitApps\SMTP\Mail\Notifications\FailureIncidentStore;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use BitApps\SMTP\Tests\Fake\InMemoryFailureNotificationGate;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Shared wiring for send-failure alert tests: controllable clock, in-memory incidents, notifier/handler builders.
 */
trait FailureAlertFixtures
{
    private int $now = 1700000000;

    private InMemoryFailureNotificationGate $gate;

    private InMemoryFailureNotificationGate $resolvedGate;

    /**
     * @var list<array{timestamp:int,hook:string,args:array<int,mixed>}>
     */
    private array $scheduled = [];

    /**
     * Stub the WordPress functions the alert path touches and capture scheduled cron events.
     */
    private function setUpFailureAlertFixtures(): void
    {
        $this->gate         = new InMemoryFailureNotificationGate();
        $this->resolvedGate = new InMemoryFailureNotificationGate();
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('spawn_cron')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Example Site');
        Functions\when('home_url')->justReturn('https://example.test/');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_date')->alias(static fn (string $format, int $timestamp): string => gmdate($format, $timestamp));
        Functions\when('wp_generate_uuid4')->alias(static function (): string {
            static $next = 0;

            return 'incident-' . ++$next;
        });
        Functions\when('wp_schedule_single_event')->alias(function (int $timestamp, string $hook, array $args): bool {
            $this->scheduled[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];

            return true;
        });
    }

    /**
     * A request-path notifier over the given channels and alerts settings.
     *
     * @param FailureNotificationChannelInterface[] $channels
     * @param array<string,mixed>                   $alerts
     */
    private function notifier(array $channels, array $alerts): FailureNotifier
    {
        $config = $this->config($alerts);

        return new FailureNotifier($this->dispatcher($config, $channels), $this->store(), new AlertScheduler(), fn (): int => $this->now);
    }

    /**
     * A cron handler over the given channels and alerts settings.
     *
     * @param FailureNotificationChannelInterface[] $channels
     * @param array<string,mixed>                   $alerts
     */
    private function handler(array $channels, array $alerts): FailureAlertHandler
    {
        return new FailureAlertHandler($this->dispatcher($this->config($alerts), $channels), $this->store(), new AlertScheduler(), fn (): int => $this->now);
    }

    /**
     * The incident store over the shared in-memory open and resolved rows.
     */
    private function store(): FailureIncidentStore
    {
        return new FailureIncidentStore($this->gate, $this->resolvedGate);
    }

    /**
     * The currently stored incident, if any.
     */
    private function storedIncident(): ?FailureIncident
    {
        return $this->store()->current();
    }

    /**
     * The closed incidents kept for their resolved alert, by id.
     *
     * @return array<string,mixed>
     */
    private function keptResolved(): array
    {
        $resolved = $this->resolvedGate->state === null ? [] : json_decode($this->resolvedGate->state, true);

        return \is_array($resolved) ? $resolved : [];
    }

    /**
     * The incident a scheduled event refers to: the open one, or else the kept resolved one.
     */
    private function scheduledIncident(int $index): ?FailureIncident
    {
        [$event, $id] = $this->scheduled[$index]['args'];

        return $event === FailureNotification::EVENT_RESOLVED ? $this->store()->resolved($id) : $this->storedIncident();
    }

    /**
     * Enabled alerts with one enabled email instance and the given reminder spacing.
     *
     * @return array<string,mixed>
     */
    private function emailAlerts(int $reminderMinutes = 60): array
    {
        return [
            'enabled'          => true,
            'reminder_minutes' => $reminderMinutes,
            'channels'         => [
                ['id' => 'ch_email01', 'type' => 'email', 'enabled' => true, 'settings' => ['recipients' => ['ops@example.com']]],
            ],
        ];
    }

    /**
     * A mocked channel adapter registered under the given key.
     */
    private function channel(string $key): FailureNotificationChannelInterface
    {
        $channel = Mockery::mock(FailureNotificationChannelInterface::class);
        $channel->shouldReceive('key')->andReturn($key);

        return $channel;
    }

    /**
     * Run every captured cron event through the handler, in order, then clear the queue.
     */
    private function runScheduled(FailureAlertHandler $handler): void
    {
        $events          = $this->scheduled;
        $this->scheduled = [];
        foreach ($events as $event) {
            $handler->handle(...$event['args']);
        }
    }

    /**
     * The real dispatcher over the given config and channels.
     *
     * @param FailureNotificationChannelInterface[] $channels
     */
    private function dispatcher(MailConfigService $config, array $channels): AlertChannelDispatcher
    {
        return new AlertChannelDispatcher($config, new FailureNotificationChannelRegistry($channels));
    }

    /**
     * A config service mock whose settings carry the given alerts blob.
     *
     * @param array<string,mixed> $alerts
     */
    private function config(array $alerts): MailConfigService
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

        return $config;
    }
}
