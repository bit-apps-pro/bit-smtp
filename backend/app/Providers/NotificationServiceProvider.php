<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\Container;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Notifications\Channels\EmailFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\SlackFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\TelegramFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\Channels\WebhookFailureNotificationChannel;
use BitApps\SMTP\Mail\Notifications\FailureNotificationChannelRegistry;
use BitApps\SMTP\Mail\Notifications\FailureNotificationGate;
use BitApps\SMTP\Mail\Notifications\FailureNotifier;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;

/**
 * Binds the failure-notification stack: the channel registry, the notifier that dispatches through
 * it, and its admin test harness.
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
            ])
        );

        $this->app->singleton(
            FailureNotifier::class,
            static fn (Container $app): FailureNotifier => new FailureNotifier(
                $app->make(MailConfigService::class),
                new FailureNotificationGate(),
                $app->make(FailureNotificationChannelRegistry::class)
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
}
