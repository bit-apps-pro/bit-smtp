<?php

/**
 * Unit-tier bootstrap: composer autoload only. No WordPress runtime.
 * WP functions are stubbed per-test via Brain Monkey (see BaseUnitTestCase).
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';

// Neutralise the `\defined('ABSPATH') || exit()` guards so guarded classes can be loaded for mocking.
\defined('ABSPATH') || \define('ABSPATH', \dirname(__DIR__) . '/');

// Minimal WP doubles for classes that type-hint them; real WP is only present in the integration tier.
require_once __DIR__ . '/stubs/WP_Error.php';
require_once __DIR__ . '/stubs/WP_Site.php';
