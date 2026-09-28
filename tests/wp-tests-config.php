<?php

/** Integration-tier WP test config: ephemeral docker DB, shared WP core, isolated test data. */
$corePath = \dirname(__DIR__, 4);
if (!is_file($corePath . '/wp-settings.php')) {
    $corePath = \dirname(__DIR__, 6);
}

\define('ABSPATH', $corePath . '/');

\define('WP_DEFAULT_THEME', 'default');
\define('WP_TESTS_DOMAIN', 'example.org');
\define('WP_TESTS_EMAIL', 'admin@example.org');
\define('WP_TESTS_TITLE', 'Bit SMTP Test');
\define('WP_PHP_BINARY', 'php');

\define('DB_NAME', getenv('WP_TEST_DB_NAME') ?: 'wp_test_db');
\define('DB_USER', getenv('WP_TEST_DB_USER') ?: 'root');
\define('DB_PASSWORD', getenv('WP_TEST_DB_PASSWORD') ?: 'root');
\define('DB_HOST', getenv('WP_TEST_DB_HOST') ?: '127.0.0.1:3307');
\define('DB_CHARSET', 'utf8');
\define('DB_COLLATE', '');

$table_prefix = 'wptests_';
