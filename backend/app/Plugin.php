<?php

namespace BitApps\SMTP;

/*
 * Main class for the plugin.
 *
 * @since 1.0.0-alpha
 */

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection as DB;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\RequestType;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\MigrationHelper;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\Telemetry;
use BitApps\SMTP\Deps\BitApps\WPTelemetry\Telemetry\TelemetryConfig;
use BitApps\SMTP\HTTP\Middleware\NonceCheckerMiddleware;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\ConnectionResolver;
use BitApps\SMTP\Mail\Credentials\DatabaseCredentialResolver;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Mail\Message\MimeBuilder;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesProvider;
use BitApps\SMTP\Mail\Providers\AmazonSes\SesTransport;
use BitApps\SMTP\Mail\Providers\Gmail\GmailProvider;
use BitApps\SMTP\Mail\Providers\Gmail\GmailTransport;
use BitApps\SMTP\Mail\Providers\OtherSmtp\OtherSmtpProvider;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridProvider;
use BitApps\SMTP\Mail\Providers\SendGrid\SendGridTransport;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Providers\HookProvider;
use BitApps\SMTP\Providers\InstallerProvider;
use BitApps\SMTP\Views\Layout;
use Exception;

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

    private array $_container = [];

    /**
     * Initialize the Plugin with hooks.
     */
    public function __construct()
    {
        $this->registerInstaller();
        Hooks::addAction('plugins_loaded', [$this, 'loaded']);

        $this->initWPTelemetry();
    }

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
            'nonce' => NonceCheckerMiddleware::class,
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
     * Instantiate the Provider class.
     */
    public function registerProviders()
    {
        if (RequestType::is('admin')) {
            new Layout();
        }

        new HookProvider();

        $apiClient     = new ApiClient(new HttpClient());
        $mimeBuilder   = new MimeBuilder();
        $tokenProvider = new OAuth2TokenProvider($apiClient, $this->mailConfigService());
        $sigV4Signer   = new SigV4Signer();

        $registry = new ProviderRegistry();
        $registry->register(new OtherSmtpProvider(new SmtpTransport(new DatabaseCredentialResolver())));
        $registry->register(new SendGridProvider(new SendGridTransport($apiClient)));
        $registry->register(new GmailProvider(new GmailTransport($apiClient, $tokenProvider, $mimeBuilder)));
        $registry->register(new SesProvider(new SesTransport($apiClient, $sigV4Signer, $mimeBuilder)));
        $this->_container['providerRegistry'] = $registry;

        $this->_container['smtpProvider'] = new WpMailBridge($registry, new ConnectionResolver(), new MailMessageFactory());
    }

    /**
     * Get the wp_mail bridge instance. Accessor name kept for backward compatibility.
     *
     * @return WpMailBridge
     */
    public function smtpProvider()
    {
        return $this->_container['smtpProvider'];
    }

    public function providerRegistry(): ProviderRegistry
    {
        return $this->_container['providerRegistry'];
    }

    public function logger(): LogService
    {
        if (!isset($this->_container['logService'])) {
            $this->_container['logService'] = new LogService();
        }

        return $this->_container['logService'];
    }

    public function mailConfigService(): MailConfigService
    {
        if (!isset($this->_container['mailConfigService'])) {
            $this->_container['mailConfigService'] = new MailConfigService();
        }

        return $this->_container['mailConfigService'];
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

        if (version_compare(Config::getOption('version'), Config::VERSION, '<')) {
            // here we checked version. updated version number updates to option through this migration
            try {
                MigrationHelper::migrate(InstallerProvider::migration());
            } catch (Exception $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- we want to log this error
                error_log('BIT SMTP Migration Error: ' . $e->getMessage());
            }
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
