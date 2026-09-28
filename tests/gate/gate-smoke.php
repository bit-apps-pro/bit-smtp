<?php

/** Gate smoke test: verifies entry file never fatals and respects PHP floor via vendor-less sandbox. */
\define('ABSPATH', __DIR__ . '/');

function add_action($hook, $cb)
{
    $GLOBALS['__actions'][] = $hook;
}
function is_multisite()
{
    return false;
}
function esc_html__($t, $d = 'default')
{
    return $t;
}
function esc_html($t)
{
    return $t;
}
function __($t, $d = 'default')
{
    return $t;
}
function plugin_dir_path($f)
{
    return rtrim(\dirname($f), '/') . '/';
}

$pluginRoot = \dirname(__DIR__, 2);
$sandbox    = sys_get_temp_dir() . '/bit-smtp-gate-smoke';

@mkdir($sandbox . '/backend', 0777, true);
copy($pluginRoot . '/bit_smtp.php', $sandbox . '/bit_smtp.php');
copy($pluginRoot . '/backend/bootstrap.php', $sandbox . '/backend/bootstrap.php');

register_shutdown_function(function () use ($sandbox) {
    @unlink($sandbox . '/bit_smtp.php');
    @unlink($sandbox . '/backend/bootstrap.php');
    @rmdir($sandbox . '/backend');
    @rmdir($sandbox);
});

$GLOBALS['__actions'] = [];
$belowFloor           = PHP_VERSION_ID < 80100;

require $sandbox . '/bit_smtp.php';

if ($belowFloor) {
    if (!\in_array('admin_notices', $GLOBALS['__actions'], true)) {
        fwrite(STDERR, "FAIL: below floor but no admin_notices notice registered\n");
        exit(1);
    }
    if (class_exists('BitApps\\SMTP\\Plugin', false)) {
        fwrite(STDERR, "FAIL: plugin was loaded below the floor\n");
        exit(1);
    }
    echo 'OK: graceful notice, plugin not loaded (PHP ' . PHP_VERSION . ")\n";
} else {
    if (!\in_array('admin_notices', $GLOBALS['__actions'], true)) {
        fwrite(STDERR, "FAIL: at/above floor but bootstrap did not run (no admin_notices registered)\n");
        exit(1);
    }
    if (class_exists('BitApps\\SMTP\\Plugin', false)) {
        fwrite(STDERR, "FAIL: real Plugin class loaded — vendor/autoload.php leaked into the sandbox\n");
        exit(1);
    }
    echo 'OK: at/above floor (PHP ' . PHP_VERSION . ")\n";
}
exit(0);
