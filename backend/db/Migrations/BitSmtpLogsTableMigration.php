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
                $table->varchar('message_id', 191)->nullable();
                $table->varchar('tracking_id', 64)->nullable();

                $table->timestamps();
            }
        );

        // Schema::create() above is a CREATE TABLE IF NOT EXISTS, so it never alters an existing
        // table: sites upgrading from an older DB_VERSION need the columns added explicitly, each
        // guarded so re-running this migration (every activation, not just the version-gated
        // upgrade) can't fail on a duplicate column or index.
        $this->addConnectionColumnIfMissing();
        $this->addWebhookCorrelationColumnsIfMissing();
    }

    public function down()
    {
        Schema::drop('logs');
    }

    private function addConnectionColumnIfMissing()
    {
        $table = Connection::wpPrefix() . Config::VAR_PREFIX . 'logs';

        $this->addColumnIfMissing($table, 'connection', 'ADD COLUMN `connection` VARCHAR(191) NULL');
    }

    private function addWebhookCorrelationColumnsIfMissing()
    {
        $table = Connection::wpPrefix() . Config::VAR_PREFIX . 'logs';

        $this->addColumnIfMissing($table, 'message_id', 'ADD COLUMN `message_id` VARCHAR(191) NULL');
        $this->addColumnIfMissing($table, 'tracking_id', 'ADD COLUMN `tracking_id` VARCHAR(64) NULL');
        $this->addIndexIfMissing($table, 'idx_message_id', 'ADD INDEX `idx_message_id` (`message_id`)');
        $this->addIndexIfMissing($table, 'idx_tracking_id', 'ADD INDEX `idx_tracking_id` (`tracking_id`)');
    }

    private function addColumnIfMissing($table, $column, $alter)
    {
        $exists = Connection::get_var(
            Connection::prepare('SHOW COLUMNS FROM `' . $table . '` LIKE %s', [$column])
        );

        if ($exists) {
            return;
        }

        Connection::query("ALTER TABLE `{$table}` {$alter}");
    }

    private function addIndexIfMissing($table, $index, $alter)
    {
        // SHOW INDEX (not SHOW COLUMNS) — an index isn't a column, and re-ADDing an existing one fatals.
        $exists = Connection::get_var(
            Connection::prepare('SHOW INDEX FROM `' . $table . '` WHERE Key_name = %s', [$index])
        );

        if ($exists) {
            return;
        }

        Connection::query("ALTER TABLE `{$table}` {$alter}");
    }
}
