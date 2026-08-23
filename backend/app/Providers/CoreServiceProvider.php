<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Deps\BitApps\WPKit\Cache\CacheManager;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Container;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\Deps\BitApps\WPKit\Cron\Scheduler;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\Deps\BitApps\WPKit\Settings\SettingsRepository;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\HTTP\Services\WebhookProvisioningService;
use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\WebhookProvisionerFactory;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Binds the plugin's foundational singletons: logging, mail config, HTTP client/auth, cache,
 * preferences, cron scheduling, and webhook provisioning.
 */
class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register core singleton bindings; pure wiring, no side effects.
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

        $this->app->singleton(SettingsRepository::class, static fn (): SettingsRepository => PluginSettings::make());

        $this->app->singleton(Scheduler::class, static fn (): Scheduler => new Scheduler());

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
}
