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
 * Registers the `wp bit-smtp ...` command surface. A strict no-op outside an active WP-CLI request:
 * the guard means register() has zero side effects on a normal web request, so nothing here ever
 * touches a page load.
 */
class CliServiceProvider extends ServiceProvider
{
    /**
     * The container-resolved command classes keyed by their WP-CLI command name. Each is autowired
     * (its service dependencies are already bound by CoreServiceProvider/MailServiceProvider).
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
     * Wire the CLI commands only under WP-CLI; a normal request short-circuits before any WP_CLI
     * reference, so this provider is inert on the web.
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
     * Bind ImportService with the built-in importer registry. An explicit binding is required because
     * the registry's constructor takes a plain array the container cannot autowire; ImportCommand then
     * autowires ImportService normally.
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
     * Bind every command to WP-CLI as a thin closure that resolves the command from the container and
     * runs it against a live WpCliReporter, keeping the command classes themselves WP_CLI-free.
     */
    protected function registerCommands(): void
    {
        foreach (self::COMMANDS as $name => $class) {
            WP_CLI::add_command($name, $this->handler($class));
        }
    }

    /**
     * A WP-CLI callable that resolves the given command class and dispatches its run() with the CLI
     * args, assoc args, and the production reporter.
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
