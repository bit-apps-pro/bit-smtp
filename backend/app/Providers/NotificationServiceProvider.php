<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Container;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Notifications\AlertChannelDispatcher;
use BitApps\SMTP\Mail\Notifications\AlertScheduler;
use BitApps\SMTP\Mail\Notifications\Channels\DiscordFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\EmailFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\SlackFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\TelegramFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\WebhookFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\FailureAlertHandler;
use BitApps\SMTP\Mail\Notifications\FailureIncidentStore;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use BitApps\SMTP\Mail\Notifications\HealthAlertHandler;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;

/**
 * Bind alerting stack: channel registry, request-path notifiers, cron handlers, admin test harness.
 */
class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register notification singletons; pure wiring, no side effects.
     */
    public function register(): void
    {
        $this->app->singleton(
            FailureNotificationChannelRegistry::class,
            static fn (): FailureNotificationChannelRegistry => new FailureNotificationChannelRegistry([
                new EmailFailureNotificationChannel(),
                new WebhookFailureNotificationChannel(),
                new SlackFailureNotificationChannel(),
                new TelegramFailureNotificationChannel(),
                new DiscordFailureNotificationChannel(),
            ])
        );

        $this->app->singleton(
            AlertChannelDispatcher::class,
            static fn (Container $app): AlertChannelDispatcher => new AlertChannelDispatcher(
                $app->make(MailConfigService::class),
                $app->make(FailureNotificationChannelRegistry::class)
            )
        );

        $this->app->singleton(AlertScheduler::class, static fn (): AlertScheduler => new AlertScheduler());

        $this->app->singleton(
            FailureIncidentStore::class,
            static fn (): FailureIncidentStore => new FailureIncidentStore(new FailureNotificationGate())
        );

        $this->app->singleton(
            FailureNotifier::class,
            static fn (Container $app): FailureNotifier => new FailureNotifier(
                $app->make(AlertChannelDispatcher::class),
                $app->make(FailureIncidentStore::class),
                $app->make(AlertScheduler::class)
            )
        );

        $this->app->singleton(
            FailureAlertHandler::class,
            static fn (Container $app): FailureAlertHandler => new FailureAlertHandler(
                $app->make(AlertChannelDispatcher::class),
                $app->make(FailureIncidentStore::class),
                $app->make(AlertScheduler::class)
            )
        );

        $this->app->singleton(
            HealthAlertHandler::class,
            static fn (Container $app): HealthAlertHandler => new HealthAlertHandler(
                $app->make(AlertChannelDispatcher::class),
                $app->make(ConnectionHealthService::class),
                $app->make(MailConfigService::class)
            )
        );

        $this->app->singleton(
            HealthNotifier::class,
            static fn (Container $app): HealthNotifier => new HealthNotifier(
                $app->make(HealthAlertHandler::class),
                $app->make(AlertScheduler::class)
            )
        );

        $this->app->singleton(
            NotificationChannelTester::class,
            static fn (Container $app): NotificationChannelTester => new NotificationChannelTester(
                $app->make(MailConfigService::class),
                $app->make(FailureNotificationChannelRegistry::class)
            )
        );
    }

    /**
     * Hook the background send-failure and health alert delivery; handlers resolve lazily per event.
     */
    public function boot(): void
    {
        $app = $this->app;

        Hooks::addAction(
            Config::FAILURE_ALERT_HOOK,
            static function ($event = null, $incidentId = null, $sequence = null, $attempt = 0) use ($app): void {
                $app->make(FailureAlertHandler::class)->handle($event, $incidentId, $sequence, $attempt);
            },
            10,
            4
        );

        Hooks::addAction(
            Config::HEALTH_ALERT_HOOK,
            static function ($event = null, $connectionId = null, $sequence = null) use ($app): void {
                $app->make(HealthAlertHandler::class)->handle($event, $connectionId, $sequence);
            },
            10,
            3
        );
    }
}
