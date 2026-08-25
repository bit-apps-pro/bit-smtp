<?php

namespace BitApps\SMTP\Tests\Unit\CLI;

use BitApps\SMTP\Deps\BitApps\WPKit\Container\Application;
use BitApps\SMTP\Providers\CliServiceProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use WP_CLI;

// Load the global-namespace WP_CLI recorder so this test can assert on real add_command() calls.
require_once __DIR__ . '/WpCliSpy.php';

/**
 * Covers CliServiceProvider's guard contract: a strict no-op off WP-CLI (register() never touches
 * the command bus), and full command registration when the WP-CLI context is active.
 *
 * @internal
 *
 * @coversNothing
 */
final class CliServiceProviderTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_CLI::$commands = [];
    }

    public function testRegisterIsANoOpWhenNotInWpCliContext(): void
    {
        $provider = new class(new Application()) extends CliServiceProvider {
            public bool $registeredCommands = false;

            protected function registerCommands(): void
            {
                $this->registeredCommands = true;
            }
        };

        $provider->register();

        $this->assertFalse($provider->registeredCommands, 'no command wiring may run outside a WP-CLI request');
        $this->assertSame([], WP_CLI::$commands);
    }

    public function testRegistersEveryCommandWhenTheWpCliContextIsActive(): void
    {
        $provider = new class(new Application()) extends CliServiceProvider {
            protected function isCliContext(): bool
            {
                return true;
            }
        };

        $provider->register();

        $this->assertSame([
            'bit-smtp send-test',
            'bit-smtp logs',
            'bit-smtp retry flush',
            'bit-smtp retry status',
            'bit-smtp health check',
            'bit-smtp export',
            'bit-smtp import',
        ], array_keys(WP_CLI::$commands));

        foreach (WP_CLI::$commands as $callable) {
            $this->assertIsCallable($callable);
        }
    }
}
