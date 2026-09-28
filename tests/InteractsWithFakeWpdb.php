<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Tests\Fake\FakeWpdb;

/**
 * Installs/tears down FakeWpdb for compiling/executing SQL without a database.
 */
trait InteractsWithFakeWpdb
{
    /**
     * Installs FakeWpdb, wires table prefix into Connection, returns fake for query assertions.
     */
    protected function installFakeWpdb(): FakeWpdb
    {
        $fake            = new FakeWpdb();
        $GLOBALS['wpdb'] = $fake;
        Connection::setPluginPrefix('bit_smtp_');

        return $fake;
    }

    /**
     * Drops the fake wpdb so a later test never sees a stale $GLOBALS['wpdb'].
     */
    protected function resetFakeWpdb(): void
    {
        unset($GLOBALS['wpdb']);
    }
}
