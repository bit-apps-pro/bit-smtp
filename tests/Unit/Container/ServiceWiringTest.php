<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Container;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\Application;
use BitApps\SMTP\Mail\Dispatch\WpMailBridge;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
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
 * Covers the wp-kit Application/ServiceProvider wiring adopted in Plugin.php: the legacy accessors
 * still resolve the right (and, for singletons, the same) instances, and boot() force-resolves
 * WpMailBridge so wp_mail is never silently left unhooked (regression guard for C1).
 *
 * Only Core/Mail/Notification providers are booted here -- Http/Installer/Abilities register real WP
 * admin/activation hooks (register_activation_hook, rest_api_init routing, etc.) that are out of
 * scope for this container-wiring test and are exercised by their own suites.
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

        // LogService reads/writes settings through Config::getOption()/get_option(); a blanket false
        // keeps every gate (logging_enabled, continuity backfill) on its no-op short-circuit path.
        Functions\when('get_option')->justReturn(false);
    }

    protected function tearDown(): void
    {
        // Plugin::$_instance is a process-wide static; reset it so this test can't leak a rigged
        // Plugin instance into any test that runs later in the same PHPUnit process.
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

    public function testLoggerAccessorReturnsTheSameSingletonInstanceAcrossCalls(): void
    {
        $plugin = $this->bootedPlugin();

        self::assertSame($plugin->logger(), $plugin->logger());
    }

    /**
     * C1 regression: without boot() force-resolving WpMailBridge, its constructor (which registers
     * pre_wp_mail) never runs, and mail silently stops routing through the plugin.
     */
    public function testBootRegistersThePreWpMailFilterWithoutAnyExplicitSmtpProviderCall(): void
    {
        $plugin = $this->pluginWithApp($this->newApplication());

        self::assertFalse(has_filter('pre_wp_mail'), 'precondition: nothing has booted yet');

        $plugin->app()->boot();

        self::assertNotFalse(has_filter('pre_wp_mail'), 'boot() must force-resolve WpMailBridge');
    }

    /**
     * Builds a Plugin wired to a booted Application, standing in for Plugin::load() without its
     * WP-admin/activation side effects.
     */
    private function bootedPlugin(): Plugin
    {
        $app    = $this->newApplication();
        $plugin = $this->pluginWithApp($app);
        $app->boot();

        return $plugin;
    }

    /**
     * The same 3 providers Plugin::__construct() registers that carry pure service bindings.
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
     * Constructs a Plugin without running its side-effecting constructor, wires the given Application
     * onto it via reflection, and publishes it as the singleton Plugin::instance() returns.
     */
    private function pluginWithApp(Application $app): Plugin
    {
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();

        // Reflection is fully accessible by default since PHP 8.1; setAccessible() is unneeded.
        (new ReflectionProperty(Plugin::class, 'app'))->setValue($plugin, $app);

        $this->setStaticInstance($plugin);

        return $plugin;
    }

    private function setStaticInstance(?Plugin $plugin): void
    {
        (new ReflectionProperty(Plugin::class, '_instance'))->setValue(null, $plugin);
    }
}
