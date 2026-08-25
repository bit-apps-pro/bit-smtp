<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Cache\CacheManager;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Container;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\Deps\BitApps\WPKit\Cron\Scheduler;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\HTTP\Services\WebhookProvisioningService;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsRepository;
use BitApps\SMTP\Mail\Analytics\MailAnalyticsService;
use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Mail\Dispatch\RetryWorker;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\ConnectionHealthStore;
use BitApps\SMTP\Mail\Health\HealthProbeResolver;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Mail\Health\HealthRecorder;
use BitApps\SMTP\Mail\Health\Probes\SmtpConnectionProbe;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\WebhookProvisionerFactory;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Binds the plugin's foundational singletons: logging, mail config, HTTP client/auth, cache,
 * preferences, cron scheduling, and webhook provisioning.
 */
class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register core singleton bindings and the retention cron job spec; no WordPress hook or DB
     * side effects happen here -- those are deferred to boot().
     */
    public function register(): void
    {
        $this->app->singleton(LogService::class, static fn (): LogService => new LogService());

        $this->app->singleton(MailConfigService::class, static fn (): MailConfigService => new MailConfigService());

        $this->app->singleton(ApiClient::class, static fn (): ApiClient => new ApiClient(new HttpClient()));

        $this->app->singleton(
            AuthorizationResolver::class,
            static fn (Container $app): AuthorizationResolver => new AuthorizationResolver(
                $app->make(OAuth2TokenProvider::class),
                $app->make(SigV4Signer::class)
            )
        );

        $this->app->singleton(CacheManager::class, static fn (): CacheManager => new CacheManager([
            'default' => 'transient',
            'prefix'  => 'bit_smtp_',
        ]));

        $this->app->singleton(MailAnalyticsRepository::class, static fn (): MailAnalyticsRepository => new MailAnalyticsRepository());

        // Shared by AnalyticsController and AbilitiesProvider so both read through the same overview
        // cache instead of each maintaining an independent (and independently stale) copy.
        $this->app->singleton(
            MailAnalyticsService::class,
            static fn (Container $app): MailAnalyticsService => new MailAnalyticsService(
                $app->make(MailAnalyticsRepository::class),
                $app->make(CacheManager::class)->store('transient')
            )
        );

        $this->app->singleton(SettingsRepository::class, static fn (): SettingsRepository => PluginSettings::make());

        $this->app->singleton(Scheduler::class, static fn (): Scheduler => new Scheduler());

        $this->configureRetentionCron();

        $this->app->singleton(RetryQueue::class, static fn (): RetryQueue => new RetryQueue());

        $this->configureRetryCron();

        $this->app->singleton(ConnectionHealthStore::class, static fn (): ConnectionHealthStore => new ConnectionHealthStore());

        $this->app->singleton(
            ConnectionHealthService::class,
            static fn (Container $app): ConnectionHealthService => new ConnectionHealthService(
                $app->make(ConnectionHealthStore::class),
                $app->make(MailConfigService::class)
            )
        );

        $this->app->singleton(
            HealthRecorder::class,
            static fn (Container $app): HealthRecorder => new HealthRecorder(
                $app->make(ConnectionHealthService::class),
                $app->make(HealthNotifier::class)
            )
        );

        $this->app->singleton(SmtpTransport::class, static fn (): SmtpTransport => new SmtpTransport(
            new DatabaseCredentialResolver(),
            (int) PluginSettings::make()->get('send_timeout_seconds', 30)
        ));

        $this->app->singleton(
            SmtpConnectionProbe::class,
            static fn (Container $app): SmtpConnectionProbe => new SmtpConnectionProbe(
                $app->make(SmtpTransport::class),
                new FailureClassifier()
            )
        );

        $this->app->singleton(
            HealthProbeResolver::class,
            static fn (Container $app): HealthProbeResolver => new HealthProbeResolver(
                $app->make(SmtpConnectionProbe::class)
            )
        );

        $this->app->singleton(
            HealthProbeRunner::class,
            static fn (Container $app): HealthProbeRunner => new HealthProbeRunner(
                $app->make(MailConfigService::class),
                $app->make(HealthProbeResolver::class),
                $app->make(ConnectionHealthService::class),
                $app->make(HealthNotifier::class)
            )
        );

        $this->configureHealthCron();

        $this->app->singleton(
            WebhookProvisioningService::class,
            static fn (Container $app): WebhookProvisioningService => new WebhookProvisioningService(
                $app->make(MailConfigService::class),
                $app->make(ProviderRegistry::class),
                $app->make(AuthorizationResolver::class),
                $app->make(ApiClient::class),
                new WebhookProvisionerFactory()
            )
        );
    }

    /**
     * Wire the Scheduler onto WP cron: filters/actions/scheduled events every request (init:11).
     * register() only configured the job spec; the actual add_action()/wp_schedule_event() calls
     * are side effects that belong here, not in register().
     */
    public function boot(): void
    {
        $this->app->make(Scheduler::class)->boot();
    }

    /**
     * Configure the daily log-retention cleanup job on the Scheduler. LogService is resolved from
     * the container lazily, inside the job callback, so this stays free of the option/DB side
     * effects LogService's constructor performs -- those happen only when the job actually fires.
     */
    private function configureRetentionCron(): void
    {
        $container = $this->app;

        $this->app->make(Scheduler::class)->job(
            Config::RETENTION_GC_HOOK,
            'daily',
            static function () use ($container): void {
                $container->make(LogService::class)->deleteOlder();
            }
        );
    }

    /**
     * Configure the every-5-minutes retry-queue processing job on the Scheduler. The retry_enabled
     * check happens inside the job callback (not by skipping registration), so toggling the
     * preference off/on takes effect immediately without re-scheduling/clearing the WP cron event.
     */
    private function configureRetryCron(): void
    {
        $container = $this->app;

        $this->app->make(Scheduler::class)
            ->addSchedule('bit_smtp_five_minutes', 300, 'Every 5 minutes')
            ->job(
                Config::RETRY_QUEUE_HOOK,
                'bit_smtp_five_minutes',
                static function () use ($container): void {
                    if (!PluginSettings::make()->get('retry_enabled', false)) {
                        return;
                    }

                    $container->make(RetryWorker::class)->process();
                }
            );
    }

    /**
     * Configure the health-probe job on an hourly base tick. Both the enabled check and the
     * interval-elapsed gate run inside the callback (not by skipping registration), so toggling
     * health_check_enabled or the interval preference takes effect without re-scheduling; the gate
     * throttles the actual probe to the configured cadence, and HealthProbeRunner is resolved lazily.
     */
    private function configureHealthCron(): void
    {
        $container = $this->app;

        $this->app->make(Scheduler::class)->job(
            Config::HEALTH_CHECK_HOOK,
            'hourly',
            static function () use ($container): void {
                $settings = PluginSettings::make();
                if (!$settings->get('health_check_enabled', false)) {
                    return;
                }

                $lastRun  = (int) get_option(HealthProbeRunner::LAST_RUN_OPTION, 0);
                $interval = self::intervalSeconds((string) $settings->get('health_check_interval', 'daily'));
                if ($lastRun > 0 && (time() - $lastRun) < $interval) {
                    return;
                }

                // Stamp the interval marker for the SCHEDULED run only (run() no longer does), so a
                // manual "Check now" can't shift the cadence.
                update_option(HealthProbeRunner::LAST_RUN_OPTION, time(), false);
                $container->make(HealthProbeRunner::class)->run();
            }
        );
    }

    /**
     * Seconds between active probe runs for a health_check_interval preference value.
     */
    private static function intervalSeconds(string $interval): int
    {
        switch ($interval) {
            case 'hourly':
                return HOUR_IN_SECONDS;

            case 'twicedaily':
                return 12 * HOUR_IN_SECONDS;

            default:
                return DAY_IN_SECONDS;
        }
    }
}
