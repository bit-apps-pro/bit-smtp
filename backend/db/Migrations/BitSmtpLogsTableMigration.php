<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Blueprint;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Schema;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;

if (! \defined('ABSPATH')) {
    exit;
}

final class BitSmtpLogsTableMigration extends Migration
{
    public function up()
    {
        Schema::withPrefix(Connection::wpPrefix() . Config::VAR_PREFIX)->create(
            'logs',
            function (Blueprint $table) {
                $table->id();
                $table->tinyint('status');
                $table->longtext('subject');
                $table->longtext('to_addr');
                $table->longtext('details')->nullable();
                $table->text('debug_info')->nullable();
                $table->tinyint('retry_count')->defaultValue(0);
                $table->varchar('connection', 191)->nullable();

                $table->timestamps();
            }
        );

        // Schema::create() above is a CREATE TABLE IF NOT EXISTS, so it never alters an existing
        // table: sites upgrading from DB_VERSION < 1.3 need the column added explicitly, guarded so
        // re-running this migration (every activation, not just the version-gated upgrade) can't fail
        // on a duplicate column.
        $this->addConnectionColumnIfMissing();
    }

    public function down()
    {
        Schema::drop('logs');
    }

    private function addConnectionColumnIfMissing()
    {
        $table  = Connection::wpPrefix() . Config::VAR_PREFIX . 'logs';
        $exists = Connection::get_var(
            Connection::prepare('SHOW COLUMNS FROM `' . $table . '` LIKE %s', ['connection'])
        );

        if ($exists) {
            return;
        }

        Connection::query("ALTER TABLE `{$table}` ADD COLUMN `connection` VARCHAR(191) NULL");
    }
}
