<?php

/**
 * WP test-suite config for the integration tier.
 * DB points at the ephemeral docker `db` service (docker-compose.test.yml), NOT any real site DB.
 * ABSPATH reuses the surrounding WordPress core (read-only core files; all data lives in the test DB).
 */

define('ABSPATH', dirname(__DIR__, 4) . '/');

define('WP_DEFAULT_THEME', 'default');
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Bit SMTP Test');
define('WP_PHP_BINARY', 'php');

define('DB_NAME', getenv('WP_TEST_DB_NAME') ?: 'wp_test_db');
define('DB_USER', getenv('WP_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TEST_DB_PASSWORD') ?: 'root');
define('DB_HOST', getenv('WP_TEST_DB_HOST') ?: '127.0.0.1:3307');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';
