<?php

/**
 * Minimal WP-CLI stub, used ONLY as a phpstan `scanFiles` symbol source (never required at runtime).
 * WP-CLI is not a composer dependency, so without this phpstan cannot resolve the WP_CLI class or the
 * WP_CLI\Utils\format_items() function that WpCliReporter/CliServiceProvider reference behind their
 * `defined('WP_CLI')` guard. Kept deliberately tiny: only the surface this plugin actually calls.
 */

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            /**
             * @param callable|object|string $callable
             * @param array<string,mixed>    $args
             * @param mixed                  $name
             */
            public static function add_command($name, $callable, $args = [])
            {
            }

            public static function success($message)
            {
            }

            public static function error($message)
            {
            }

            public static function warning($message)
            {
            }

            public static function line($message = '')
            {
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (!\function_exists('WP_CLI\\Utils\\format_items')) {
        /**
         * @param array<int,array<string,mixed>|object> $items
         * @param array<int,string>|string              $fields
         */
        function format_items(string $format, $items, $fields): void
        {
        }
    }
}
