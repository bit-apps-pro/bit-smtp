<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Container;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Application;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Notifications\AlertScheduler;
use BitApps\SMTP\Mail\Notifications\FailureAlertHandler;
use BitApps\SMTP\Mail\Notifications\HealthAlertHandler;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Providers\CoreServiceProvider;
use BitApps\SMTP\Providers\MailServiceProvider;
use BitApps\SMTP\Providers\NotificationServiceProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Golden\Support\WpStubs;
use Brain\Monkey\Functions;
use ReflectionClass;
use ReflectionProperty;

/**
 * Covers wp-kit Application/ServiceProvider wiring: legacy accessors resolve correctly, boot() force-resolves WpMailBridge.
 *
 * @internal
 *
 * @coversNothing
 */
final class ServiceWiringTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::install();

        Functions\when('get_option')->justReturn(false);
    }

    protected function tearDown(): void
    {
        $this->setStaticInstance(null);
        parent::tearDown();
    }

    public function testProviderRegistryResolvesAllFourteenMailProviders(): void
    {
        $plugin = $this->bootedPlugin();

        $registry = $plugin->providerRegistry();

        self::assertInstanceOf(ProviderRegistry::class, $registry);
        self::assertCount(14, $registry->all());
    }

    public function testSmtpProviderAccessorReturnsTheWpMailBridge(): void
    {
        $plugin = $this->bootedPlugin();

        self::assertInstanceOf(WpMailBridge::class, $plugin->smtpProvider());
    }

    /**
     * Task 18: MailServiceProvider threads send_timeout_seconds pref into SmtpTransport; defaults to schema default (30).
     */
    public function testOtherSmtpTransportReceivesTheDefaultSendTimeoutSecondsFromPluginSettings(): void
    {
        $plugin = $this->bootedPlugin();

        $transport = $plugin->providerRegistry()->get('other_smtp')->transport();

        self::assertInstanceOf(SmtpTransport::class, $transport);
        self::assertSame(30, (new ReflectionProperty(SmtpTransport::class, 'timeoutSeconds'))->getValue($transport));
    }

    public function testLoggerAccessorReturnsTheSameSingletonInstanceAcrossCalls(): void
    {
        $plugin = $this->bootedPlugin();

        self::assertSame($plugin->logger(), $plugin->logger());
    }

    /**
     * C1 regression: boot() must force-resolve WpMailBridge so pre_wp_mail filter registers.
     */
    public function testBootRegistersThePreWpMailFilterWithoutAnyExplicitSmtpProviderCall(): void
    {
        $plugin = $this->pluginWithApp($this->newApplication());

        self::assertFalse(has_filter('pre_wp_mail'), 'precondition: nothing has booted yet');

        $plugin->app()->boot();

        self::assertNotFalse(has_filter('pre_wp_mail'), 'boot() must force-resolve WpMailBridge');
    }

    public function testBootHooksTheBackgroundFailureAlertHandler(): void
    {
        $plugin = $this->pluginWithApp($this->newApplication());

        self::assertFalse(has_action(Config::FAILURE_ALERT_HOOK), 'precondition: nothing has booted yet');

        $plugin->app()->boot();

        self::assertNotFalse(has_action(Config::FAILURE_ALERT_HOOK));
        self::assertInstanceOf(FailureAlertHandler::class, $plugin->app()->make(FailureAlertHandler::class));
    }

    public function testBootHooksTheBackgroundHealthAlertHandler(): void
    {
        $plugin = $this->pluginWithApp($this->newApplication());

        self::assertFalse(has_action(Config::HEALTH_ALERT_HOOK), 'precondition: nothing has booted yet');

        $plugin->app()->boot();

        self::assertNotFalse(has_action(Config::HEALTH_ALERT_HOOK));
        self::assertInstanceOf(HealthAlertHandler::class, $plugin->app()->make(HealthAlertHandler::class));
        self::assertSame($plugin->app()->make(AlertScheduler::class), $plugin->app()->make(AlertScheduler::class));
    }

    /**
     * Builds Plugin wired to booted Application, mimics Plugin::load() without admin/activation side effects.
     */
    private function bootedPlugin(): Plugin
    {
        $app    = $this->newApplication();
        $plugin = $this->pluginWithApp($app);
        $app->boot();

        return $plugin;
    }

    /**
     * Creates Application with the 3 core providers Plugin::__construct() registers.
     */
    private function newApplication(): Application
    {
        $app = new Application();
        $app->register(new CoreServiceProvider($app));
        $app->register(new MailServiceProvider($app));
        $app->register(new NotificationServiceProvider($app));

        return $app;
    }

    /**
     * Constructs Plugin without constructor, wires Application via reflection, sets as Plugin::instance() singleton.
     */
    private function pluginWithApp(Application $app): Plugin
    {
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty(Plugin::class, 'app'))->setValue($plugin, $app);

        $this->setStaticInstance($plugin);

        return $plugin;
    }

    private function setStaticInstance(?Plugin $plugin): void
    {
        (new ReflectionProperty(Plugin::class, '_instance'))->setValue(null, $plugin);
    }
}
