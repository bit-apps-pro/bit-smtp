<?php

/**
 * Integration-tier bootstrap: boots the real WordPress test suite (wp-phpunit) against the
 * docker test DB, then loads the plugin on `muplugins_loaded` so its hooks are live.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');

$_tests_dir = getenv('WP_PHPUNIT__DIR');
if (!$_tests_dir) {
    fwrite(STDERR, "WP_PHPUNIT__DIR is not set — is wp-phpunit installed?\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () {
    require dirname(__DIR__) . '/bit_smtp.php';
});

require $_tests_dir . '/includes/bootstrap.php';
