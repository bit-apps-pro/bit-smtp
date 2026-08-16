<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Blueprint;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Schema;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\HTTP\Services\LogService;

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
                $table->varchar('sender', 191)->nullable();
                $table->longtext('details')->nullable();
                $table->text('debug_info')->nullable();
                $table->tinyint('retry_count')->defaultValue(0);
                $table->varchar('connection', 191)->nullable();
                $table->varchar('connection_id', 191)->nullable();
                $table->varchar('message_id', 191)->nullable();
                $table->varchar('tracking_id', 64)->nullable();
                $table->varchar('delivery_status', 32)->nullable();
                $table->datetime('delivery_updated_at')->nullable();
                $table->varchar('source_plugin', 191)->nullable();
                $table->varchar('routing_type', 32)->nullable();
                $table->integer('routing_rule_index')->nullable();
                $table->varchar('subject_pattern', 191)->nullable();
                $table->integer('recipient_count')->nullable();
                $table->datetime('created_at_utc')->nullable();

                $table->timestamps();
            }
        );

        // Schema::create() above is a CREATE TABLE IF NOT EXISTS, so it never alters an existing
        // table: sites upgrading from an older DB_VERSION need the columns added explicitly, each
        // guarded so re-running this migration (every activation, not just the version-gated
        // upgrade) can't fail on a duplicate column or index.
        $this->addConnectionColumnIfMissing();
        $this->addWebhookCorrelationColumnsIfMissing();
        $this->addDeliveryColumnsIfMissing();
        $this->addAnalyticsColumnsIfMissing();
        $this->addSenderColumnIfMissing();
        $this->createDeliveryEventsTableIfMissing();
        LogService::initializeLoggingContinuity();
    }

    public function down()
    {
        Schema::drop('log_delivery_events');
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

        $this->addColumnIfMissing($table, 'connection_id', 'ADD COLUMN `connection_id` VARCHAR(191) NULL');
        $this->addColumnIfMissing($table, 'message_id', 'ADD COLUMN `message_id` VARCHAR(191) NULL');
        $this->addColumnIfMissing($table, 'tracking_id', 'ADD COLUMN `tracking_id` VARCHAR(64) NULL');
        $this->addIndexIfMissing($table, 'idx_connection_id', 'ADD INDEX `idx_connection_id` (`connection_id`)');
        $this->addIndexIfMissing($table, 'idx_message_id', 'ADD INDEX `idx_message_id` (`message_id`)');
        $this->addIndexIfMissing($table, 'idx_tracking_id', 'ADD INDEX `idx_tracking_id` (`tracking_id`)');
    }

    private function addDeliveryColumnsIfMissing()
    {
        $table = Connection::wpPrefix() . Config::VAR_PREFIX . 'logs';

        $this->addColumnIfMissing($table, 'delivery_status', 'ADD COLUMN `delivery_status` VARCHAR(32) NULL');
        $this->addColumnIfMissing($table, 'delivery_updated_at', 'ADD COLUMN `delivery_updated_at` DATETIME NULL');
    }

    private function addAnalyticsColumnsIfMissing()
    {
        $table = Connection::wpPrefix() . Config::VAR_PREFIX . 'logs';

        $this->addColumnIfMissing($table, 'source_plugin', 'ADD COLUMN `source_plugin` VARCHAR(191) NULL');
        $this->addColumnIfMissing($table, 'routing_type', 'ADD COLUMN `routing_type` VARCHAR(32) NULL');
        $this->addColumnIfMissing($table, 'routing_rule_index', 'ADD COLUMN `routing_rule_index` INT NULL');
        $this->addColumnIfMissing($table, 'subject_pattern', 'ADD COLUMN `subject_pattern` VARCHAR(191) NULL');
        $this->addColumnIfMissing($table, 'recipient_count', 'ADD COLUMN `recipient_count` INT NULL');
        $this->addColumnIfMissing($table, 'created_at_utc', 'ADD COLUMN `created_at_utc` DATETIME NULL');
        $this->addIndexIfMissing($table, 'idx_created_at', 'ADD INDEX `idx_created_at` (`created_at`)');
        $this->addIndexIfMissing($table, 'idx_source_created_utc', 'ADD INDEX `idx_source_created_utc` (`source_plugin`, `created_at_utc`)');
        $this->addIndexIfMissing($table, 'idx_connection_id_created_utc', 'ADD INDEX `idx_connection_id_created_utc` (`connection_id`, `created_at_utc`)');
        $this->addIndexIfMissing($table, 'idx_created_at_utc', 'ADD INDEX `idx_created_at_utc` (`created_at_utc`)');
        $this->removeUnusedAnalyticsIndexes($table);
    }

    private function addSenderColumnIfMissing()
    {
        $table = Connection::wpPrefix() . Config::VAR_PREFIX . 'logs';

        $this->addColumnIfMissing($table, 'sender', 'ADD COLUMN `sender` VARCHAR(191) NULL');
    }

    private function createDeliveryEventsTableIfMissing()
    {
        Schema::withPrefix(Connection::wpPrefix() . Config::VAR_PREFIX)->create(
            'log_delivery_events',
            function (Blueprint $table) {
                $table->id();
                $table->bigInt('log_id')->index();
                $table->varchar('recipient', 254);
                $table->varchar('status', 32);
                $table->tinyint('terminal')->defaultValue(0);
                $table->text('detail')->nullable();
                $table->datetime('occurred_at')->nullable();
                // Dedup key is one NOT-NULL hash, not a composite over nullable columns: MySQL treats
                // NULLs as distinct in a unique index, which would let provider retries insert duplicates.
                $table->char('event_hash', 64)->unique();

                $table->timestamps();
            }
        );
    }

    private function addColumnIfMissing($table, $column, $alter)
    {
        $exists = Connection::get_var(
            Connection::prepare('SHOW COLUMNS FROM `' . $table . '` LIKE %s', [$column])
        );
        $this->throwOnDatabaseError('read column ' . $column);

        if ($exists) {
            return;
        }

        if (Connection::query("ALTER TABLE `{$table}` {$alter}") === false) {
            $this->throwOnDatabaseError('add column ' . $column);

            throw new RuntimeException('Unable to add analytics column ' . $column . '.');
        }
    }

    private function addIndexIfMissing($table, $index, $alter)
    {
        // SHOW INDEX (not SHOW COLUMNS) — an index isn't a column, and re-ADDing an existing one fatals.
        $exists = Connection::get_var(
            Connection::prepare('SHOW INDEX FROM `' . $table . '` WHERE Key_name = %s', [$index])
        );
        $this->throwOnDatabaseError('read index ' . $index);

        if ($exists) {
            return;
        }

        if (Connection::query("ALTER TABLE `{$table}` {$alter}") === false) {
            $this->throwOnDatabaseError('add index ' . $index);

            throw new RuntimeException('Unable to add analytics index ' . $index . '.');
        }
    }

    private function removeUnusedAnalyticsIndexes($table)
    {
        // Analytics filters only use created_at_utc, source_plugin, and connection_id. Keep
        // idx_created_at for retention deletion, but remove the former display-time/raw-
        // connection/status composites so they do not impose write cost after this upgrade.
        foreach ([
            'idx_source_created',
            'idx_connection_created',
            'idx_connection_id_created',
            'idx_status_created',
            'idx_connection_created_utc',
            'idx_status_created_utc',
        ] as $index) {
            $exists = Connection::get_var(
                Connection::prepare('SHOW INDEX FROM `' . $table . '` WHERE Key_name = %s', [$index])
            );
            $this->throwOnDatabaseError('read index ' . $index);

            if (!$exists) {
                continue;
            }

            if (Connection::query("ALTER TABLE `{$table}` DROP INDEX `{$index}`") === false) {
                $this->throwOnDatabaseError('remove index ' . $index);

                throw new RuntimeException('Unable to remove unused analytics index ' . $index . '.');
            }
        }
    }

    private function throwOnDatabaseError(string $operation): void
    {
        $error = (string) Connection::prop('last_error');
        if ($error !== '') {
            throw new RuntimeException('Unable to ' . $operation . ': ' . $error);
        }
    }
}
