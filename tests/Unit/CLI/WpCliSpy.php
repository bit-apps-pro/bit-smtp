<?php

/**
 * Global-namespace recording stand-in for WP-CLI command bus; inert when WP_CLI constant undefined.
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
