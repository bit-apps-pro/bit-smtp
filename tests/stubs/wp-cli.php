<?php

/** Minimal WP-CLI stub for phpstan symbol resolution; never required at runtime. */

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
