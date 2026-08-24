<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Tests\Fake\FakeWpdb;

/**
 * Installs a FakeWpdb as $GLOBALS['wpdb'] so BitApps\WPDatabase's query builder can compile and
 * "execute" SQL with no database, and tears it back down afterward.
 */
trait InteractsWithFakeWpdb
{
    /**
     * Installs a fresh FakeWpdb, wires the plugin's table prefix into Connection (mirrors what the
     * plugin's bootstrap does against the real $wpdb), and returns the fake for query assertions.
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
