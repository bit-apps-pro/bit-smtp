<?php

/**
 * Global-namespace recording stand-in for WP-CLI's command bus, used only by CliServiceProviderTest.
 * Declaring the CLASS is inert for every other test: `defined('WP_CLI')` (the constant) stays false,
 * so CliServiceProvider still no-ops on the web. Loaded via an explicit require, never autoloaded.
 */

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            /**
             * @var array<string,callable>
             */
            public static array $commands = [];

            /**
             * Record a registered command name -> callable, mirroring WP_CLI::add_command's signature.
             *
             * @param callable|object|string $callable
             * @param array<string,mixed>    $args
             * @param mixed                  $name
             */
            public static function add_command($name, $callable, $args = []): void
            {
                self::$commands[$name] = $callable;
            }
        }
    }
}
