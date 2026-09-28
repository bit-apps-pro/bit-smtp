<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\CLI\ExportCommand;
use BitApps\SMTP\CLI\HealthCheckCommand;
use BitApps\SMTP\CLI\ImportCommand;
use BitApps\SMTP\CLI\LogsCommand;
use BitApps\SMTP\CLI\RetryFlushCommand;
use BitApps\SMTP\CLI\RetryStatusCommand;
use BitApps\SMTP\CLI\SendTestCommand;
use BitApps\SMTP\CLI\WpCliReporter;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\Container;
use BitApps\SMTP\Deps\BitApps\WPKit\Container\ServiceProvider;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Import\ImportService;
use BitApps\SMTP\Mail\Import\PluginImporterRegistry;
use WP_CLI;

\defined('ABSPATH') || exit();

/**
 * Register WP-CLI commands; strict no-op outside active WP-CLI (zero side effects on web requests).
 */
class CliServiceProvider extends ServiceProvider
{
    /**
     * Container-resolved command classes keyed by WP-CLI command name (autowired, dependencies pre-bound).
     *
     * @var array<string,class-string>
     */
    private const COMMANDS = [
        'bit-smtp send-test'    => SendTestCommand::class,
        'bit-smtp logs'         => LogsCommand::class,
        'bit-smtp retry flush'  => RetryFlushCommand::class,
        'bit-smtp retry status' => RetryStatusCommand::class,
        'bit-smtp health check' => HealthCheckCommand::class,
        'bit-smtp export'       => ExportCommand::class,
        'bit-smtp import'       => ImportCommand::class,
    ];

    /**
     * Wire CLI commands only under WP-CLI (normal requests short-circuit before WP_CLI reference).
     */
    public function register(): void
    {
        if (!$this->isCliContext()) {
            return;
        }

        $this->bindImportService();
        $this->registerCommands();
    }

    /**
     * Bind ImportService with built-in importer registry (explicit binding: registry constructor takes array).
     */
    protected function bindImportService(): void
    {
        $this->app->singleton(
            ImportService::class,
            static fn (Container $app): ImportService => new ImportService(
                PluginImporterRegistry::withDefaults(),
                $app->make(MailConfigService::class)
            )
        );
    }

    /**
     * True only during an active WP-CLI request (the WP_CLI constant is defined and truthy).
     */
    protected function isCliContext(): bool
    {
        return \defined('WP_CLI') && WP_CLI;
    }

    /**
     * Register commands to WP-CLI as closures that resolve from container (keeps command classes WP_CLI-free).
     */
    protected function registerCommands(): void
    {
        foreach (self::COMMANDS as $name => $class) {
            WP_CLI::add_command($name, $this->handler($class));
        }
    }

    /**
     * WP-CLI callable that resolves command class and dispatches its run() with CLI args and reporter.
     *
     * @param class-string $class
     */
    private function handler(string $class): callable
    {
        $container = $this->app;

        return static function (array $args, array $assocArgs) use ($container, $class): void {
            $container->make($class)->run($args, $assocArgs, new WpCliReporter());
        };
    }
}
