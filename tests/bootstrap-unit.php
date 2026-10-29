<?php

/** Unit bootstrap: composer autoload, no WP runtime; Brain Monkey stubs WP, minimal doubles. */

require_once \dirname(__DIR__) . '/vendor/autoload.php';

\defined('ABSPATH') || \define('ABSPATH', \dirname(__DIR__) . '/');
// Debug logging on so DebugLog output stays assertable via expectOutputRegex().
\defined('WP_DEBUG')     || \define('WP_DEBUG', true);
\defined('WP_DEBUG_LOG') || \define('WP_DEBUG_LOG', true);

require_once __DIR__ . '/stubs/WP_Error.php';
