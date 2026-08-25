<?php

namespace BitApps\SMTP;

/*
 * Main class for the plugin.
 *
 * @since 1.0.0-alpha
 */

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection as DB;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Application;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\MigrationHelper;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\Telemetry;
use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\TelemetryConfig;
use BitApps\SMTP\HTTP\Middleware\CapabilityCheckerMiddleware;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\HTTP\Services\WebhookProvisioningService;
use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Notifications\NotificationChannelTester;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Providers\AbilitiesServiceProvider;
use BitApps\SMTP\Providers\CliServiceProvider;
use BitApps\SMTP\Providers\CoreServiceProvider;
use BitApps\SMTP\Providers\HttpServiceProvider;
use BitApps\SMTP\Providers\InstallerProvider;
use BitApps\SMTP\Providers\InstallerServiceProvider;
use BitApps\SMTP\Providers\MailServiceProvider;
use BitApps\SMTP\Providers\NotificationServiceProvider;
use BitApps\SMTP\Providers\PrivacyServiceProvider;

final class Plugin
{
    /**
     * Main instance of the plugin.
     *
     * @since 1.0.0-alpha
     *
     * @var null|Plugin
     */
    private static $_instance;

    private $_registeredMiddleware = [];

    private Application $app;

    /**
     * Initialize the Plugin with hooks.
     */
    public function __construct()
    {
        $this->app = new Application();
        $this->app->register(new CoreServiceProvider($this->app));
        $this->app->register(new MailServiceProvider($this->app));
        $this->app->register(new NotificationServiceProvider($this->app));
        // GDPR exporter/eraser + policy content wire via boot() filters; no load-time side effects.
        $this->app->register(new PrivacyServiceProvider($this->app));
        $this->app->register(new HttpServiceProvider($this->app));
        // Installer/Abilities register() calls are side-effecting (WP hook wiring): they must run
        // here, at load time, not deferred to boot() -- activation fires before init:11 ever runs.
        $this->app->register(new InstallerServiceProvider($this->app));
        $this->app->register(new AbilitiesServiceProvider($this->app));
        // CLI commands must be added during WP-CLI's early bootstrap (before init), so its register()
        // also runs at load time; it is a strict no-op outside an active WP-CLI request.
        $this->app->register(new CliServiceProvider($this->app));

        Hooks::addAction('plugins_loaded', [$this, 'loaded']);

        $this->initWPTelemetry();
    }

    /**
     * The wp-kit container/application backing this plugin's services.
     */
    public function app(): Application
    {
        return $this->app;
    }

    /**
     * Kept for backward compatibility with external callers; installer hooks now wire via
     * InstallerServiceProvider::register() at load time, not through this method.
     */
    public function registerInstaller()
    {
        $installerProvider = new InstallerProvider();
        $installerProvider->register();
    }

    /**
     * Load the plugin.
     */
    public function loaded()
    {
        Hooks::doAction(Config::withPrefix('loaded'));
        Hooks::addAction('init', [$this, 'registerProviders'], 11);
        Hooks::addFilter('plugin_action_links_' . Config::get('BASENAME'), [$this, 'actionLinks']);
        $this->maybeMigrateDB();
    }

    public function middlewares()
    {
        return [
            'cap' => CapabilityCheckerMiddleware::class,
        ];
    }

    public function getMiddleware($name)
    {
        if (isset($this->_registeredMiddleware[$name])) {
            return $this->_registeredMiddleware[$name];
        }

        $middlewares = $this->middlewares();
        if (isset($middlewares[$name]) && class_exists($middlewares[$name]) && method_exists($middlewares[$name], 'handle')) {
            $this->_registeredMiddleware[$name] = new $middlewares[$name]();
        } else {
            return false;
        }

        return $this->_registeredMiddleware[$name];
    }

    /**
     * Boots all registered service providers. Kept as the init:11 hook target (name and timing
     * unchanged) for backward compatibility.
     */
    public function registerProviders()
    {
        $this->app->boot();
    }

    /**
     * Get the wp_mail bridge instance. Accessor name kept for backward compatibility.
     *
     * @return WpMailBridge
     */
    public function smtpProvider()
    {
        return $this->app->make(WpMailBridge::class);
    }

    public function providerRegistry(): ProviderRegistry
    {
        return $this->app->make(ProviderRegistry::class);
    }

    public function logger(): LogService
    {
        return $this->app->make(LogService::class);
    }

    public function mailConfigService(): MailConfigService
    {
        return $this->app->make(MailConfigService::class);
    }

    public function notificationChannelTester(): NotificationChannelTester
    {
        return $this->app->make(NotificationChannelTester::class);
    }

    public function apiClient(): ApiClient
    {
        return $this->app->make(ApiClient::class);
    }

    /**
     * Resolves connection-independent HTTP auth strategies.
     */
    public function authResolver(): AuthorizationResolver
    {
        return $this->app->make(AuthorizationResolver::class);
    }

    public function webhookProvisioningService(): WebhookProvisioningService
    {
        return $this->app->make(WebhookProvisioningService::class);
    }

    /**
     * Plugin action links.
     *
     * @param array $links Array of links
     *
     * @return array
     */
    public function actionLinks($links)
    {
        $linksToAdd = Config::get('PLUGIN_PAGE_LINKS');
        foreach ($linksToAdd as $link) {
            $links[] = '<a href="' . $link['url'] . '">' . $link['title'] . '</a>';
        }

        return $links;
    }

    public static function maybeMigrateDB()
    {
        if (!Capabilities::check('manage_options')) {
            return;
        }

        if (version_compare(Config::getOption('version'), '1.2', '<')) {
            Config::deleteOption('global_post_content');
            Config::deleteOption('new_product_nav_btn_hide');
        }

        // Gate on the schema version too, not just the plugin version: a schema-only migration (e.g.
        // encrypting stored credentials) ships without a plugin-version bump, so installs already at
        // Config::VERSION must still run it when their db_version is behind.
        $behindVersion   = version_compare(Config::getOption('version'), Config::VERSION, '<');
        $behindDbVersion = version_compare(Config::getOption('db_version', '0'), Config::DB_VERSION, '<');

        if ($behindVersion || $behindDbVersion) {
            // BitSmtpPluginOptions::up() writes version and db_version only after preceding migrations
            // complete. Let any schema failure propagate so the version gate remains retryable.
            MigrationHelper::migrate(InstallerProvider::migration());
        }
    }

    /**
     * Retrieves the main instance of the plugin.
     *
     * @since 1.0.0-alpha
     *
     * @return Plugin plugin main instance
     */
    public static function instance()
    {
        return static::$_instance;
    }

    /**
     * Loads the plugin main instance and initializes it.
     *
     * @return bool True if the plugin main instance could be loaded, false otherwise
     *
     * @since 1.0.0-alpha
     */
    public static function load()
    {
        if (static::$_instance !== null) {
            return false;
        }

        static::$_instance = new static();

        DB::setPluginPrefix(Config::VAR_PREFIX);

        return true;
    }

    public function initWPTelemetry()
    {
        TelemetryConfig::setSlug(Config::SLUG);
        TelemetryConfig::setTitle(Config::TITLE);
        TelemetryConfig::setVersion(Config::VERSION);
        TelemetryConfig::setPrefix(Config::VAR_PREFIX);

        TelemetryConfig::setServerBaseUrl('https://wp-api.bitapps.pro/public/');
        TelemetryConfig::setTermsUrl('https://bitapps.pro/terms-of-service/');
        TelemetryConfig::setPolicyUrl('https://bitapps.pro/privacy-policy/');

        Telemetry::report()->addPluginData()->init();
        Telemetry::feedback()->init();
    }
}
