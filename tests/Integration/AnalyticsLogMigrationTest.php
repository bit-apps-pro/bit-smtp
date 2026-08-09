<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Plugin;
use BitSmtpLogsTableMigration;
use RuntimeException;

/**
 * Exercises the logs migration against the real WordPress test database for both new installs and
 * sites that already have the pre-analytics log table.
 *
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsLogMigrationTest extends IntegrationTestCase
{
    private string $logsTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logsTable = $this->tableName();
    }

    public function testFreshMigrationCreatesNullableAttributionColumnsAndIndexes(): void
    {
        $this->dropLogsTable();

        $this->migrateLogs();
        $this->assertAnalyticsColumnsAreNullable();
        $this->assertAnalyticsIndexesExist();

        // Activations invoke this migration more than once. A second run must retain the schema
        // without duplicate-column or duplicate-index errors.
        $this->migrateLogs();
        $this->assertAnalyticsColumnsAreNullable();
        $this->assertAnalyticsIndexesExist();
    }

    public function testUpgradeMigrationPreservesLegacyRowsWithNullAttribution(): void
    {
        $this->dropLogsTable();
        $this->createLegacyLogsTable();
        $this->seedLegacyLog();

        $this->migrateLogs();
        $this->migrateLogs();

        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM `{$this->logsTable}` WHERE id = 1");

        $this->assertNotNull($row);
        $this->assertSame('Legacy subject', $row->subject);
        $this->assertNull($row->source_plugin);
        $this->assertNull($row->routing_type);
        $this->assertNull($row->routing_rule_index);
        $this->assertNull($row->subject_pattern);
        $this->assertNull($row->recipient_count);
        $this->assertAnalyticsColumnsAreNullable();
        $this->assertAnalyticsIndexesExist();
    }

    public function testUpgradeLeavesLegacyTimestampsUnqualifiedWithoutGuessingAcrossTimezoneChanges(): void
    {
        $this->dropLogsTable();
        $this->createLegacyLogsTable();
        $this->seedLegacyLog('2026-11-01 01:30:00');
        $previousTimezone = get_option('timezone_string');
        update_option('timezone_string', 'America/New_York');

        try {
            $this->migrateLogs();
            update_option('timezone_string', 'Asia/Dhaka');
            $this->migrateLogs();

            global $wpdb;
            $row = $wpdb->get_row("SELECT created_at, created_at_utc FROM `{$this->logsTable}` WHERE id = 1");

            $this->assertSame('2026-11-01 01:30:00', $row->created_at);
            // A legacy local display timestamp has no historical offset. Never invent a UTC value
            // from the currently configured timezone, including an ambiguous DST fall-back hour.
            $this->assertNull($row->created_at_utc);

            $legacy              = Log::where('id', 1)->first();
            $legacy->retry_count = 1;
            $legacy->save();
            $row = $wpdb->get_row("SELECT created_at_utc FROM `{$this->logsTable}` WHERE id = 1");
            $this->assertNull($row->created_at_utc, 'Updating legacy metadata must not infer or double-convert a UTC timestamp.');
            $this->assertAnalyticsIndexesExist();
        } finally {
            update_option('timezone_string', $previousTimezone);
        }
    }

    public function testLogModelAcceptsAttributionAndPreservesANullRuleIndex(): void
    {
        $log = new Log([
            'source_plugin'      => 'woocommerce',
            'routing_type'       => 'rule',
            'routing_rule_index' => '4',
            'subject_pattern'    => 'Order <number>',
            'recipient_count'    => '2',
        ]);

        $this->assertSame('woocommerce', $log->source_plugin);
        $this->assertSame('rule', $log->routing_type);
        $this->assertSame(4, $log->routing_rule_index);
        $this->assertSame('Order <number>', $log->subject_pattern);
        $this->assertSame(2, $log->recipient_count);

        $log->routing_rule_index = null;
        $this->assertNull($log->routing_rule_index);
        $log->subject_pattern = null;
        $log->recipient_count = null;
        $this->assertNull($log->subject_pattern);
        $this->assertNull($log->recipient_count);
    }

    public function testAnalyticsConnectionIdRangeQueriesUseTheUtcCompositeIndex(): void
    {
        $this->migrateLogs();
        global $wpdb;

        $wpdb->insert($this->logsTable, [
            'status'         => 1,
            'subject'        => 'Subject',
            'to_addr'        => '[]',
            'connection_id'  => 'conn_primary',
            'created_at'     => '2026-03-01 00:00:00',
            'created_at_utc' => '2026-03-01 00:00:00',
            'updated_at'     => '2026-03-01 00:00:00',
        ]);
        $plan = $wpdb->get_row($wpdb->prepare(
            "EXPLAIN SELECT COUNT(*) FROM `{$this->logsTable}` WHERE connection_id = %s AND created_at_utc >= %s AND created_at_utc < %s",
            'conn_primary',
            '2026-03-01 00:00:00',
            '2026-03-02 00:00:00'
        ));

        $this->assertNotNull($plan);
        $this->assertSame('idx_connection_id_created_utc', $plan->key);
    }

    public function testMaybeMigrateDbUpgradesAOneSixLogsTableAtTheCurrentPluginVersion(): void
    {
        $this->dropLogsTable();
        $this->createLegacyLogsTable();
        $this->seedLegacyLog();

        $previousVersion   = Config::getOption('version');
        $previousDbVersion = Config::getOption('db_version');
        Config::updateOption('version', Config::VERSION, true);
        Config::updateOption('db_version', '1.6', true);
        wp_set_current_user(1);

        try {
            Plugin::maybeMigrateDB();

            $this->assertLegacyAnalyticsUpgrade();
            $this->assertSame('1.7', Config::getOption('db_version'));

            Plugin::maybeMigrateDB();

            $this->assertLegacyAnalyticsUpgrade();
            $this->assertSame('1.7', Config::getOption('db_version'));
        } finally {
            wp_set_current_user(0);
            $this->migrateLogs();
            Config::updateOption('version', $previousVersion, true);
            Config::updateOption('db_version', $previousDbVersion, true);
        }
    }

    public function testMigrationFailureDoesNotAdvanceDbVersionAndCanBeRetried(): void
    {
        $this->dropLogsTable();
        $this->createLegacyLogsTable();
        global $wpdb;
        $this->assertNotFalse($wpdb->query("ALTER TABLE `{$this->logsTable}` ADD COLUMN `created_at_utc` TEXT NULL"));

        $previousVersion   = Config::getOption('version');
        $previousDbVersion = Config::getOption('db_version');
        Config::updateOption('version', Config::VERSION, true);
        Config::updateOption('db_version', '1.6', true);
        wp_set_current_user(1);
        $previousSuppressErrors = $wpdb->suppress_errors(true);

        try {
            try {
                Plugin::maybeMigrateDB();
                self::fail('A failed analytics DDL statement must propagate from migration.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('idx_source_created_utc', $exception->getMessage());
            }
            self::assertSame('1.6', Config::getOption('db_version'));

            $this->assertNotFalse($wpdb->query("ALTER TABLE `{$this->logsTable}` MODIFY COLUMN `created_at_utc` DATETIME NULL"));
            Plugin::maybeMigrateDB();
            self::assertSame('1.7', Config::getOption('db_version'));
        } finally {
            $wpdb->suppress_errors($previousSuppressErrors);
            wp_set_current_user(0);
            $this->migrateLogs();
            Config::updateOption('version', $previousVersion, true);
            Config::updateOption('db_version', $previousDbVersion, true);
        }
    }

    private function migrateLogs(): void
    {
        (new BitSmtpLogsTableMigration())->up();
    }

    private function assertAnalyticsColumnsAreNullable(): void
    {
        global $wpdb;

        foreach (['source_plugin', 'routing_type', 'routing_rule_index', 'subject_pattern', 'recipient_count', 'created_at_utc'] as $column) {
            $definition = $wpdb->get_row(
                $wpdb->prepare("SHOW COLUMNS FROM `{$this->logsTable}` LIKE %s", $column)
            );

            $this->assertNotNull($definition, "{$column} should be present on the logs table");
            $this->assertSame('YES', $definition->Null, "{$column} must preserve legacy rows as null");
        }
    }

    private function assertLegacyAnalyticsUpgrade(): void
    {
        global $wpdb;

        $row = $wpdb->get_row("SELECT * FROM `{$this->logsTable}` WHERE id = 1");

        $this->assertNotNull($row);
        $this->assertSame('Legacy subject', $row->subject);
        $this->assertNull($row->source_plugin);
        $this->assertNull($row->routing_type);
        $this->assertNull($row->routing_rule_index);
        $this->assertNull($row->subject_pattern);
        $this->assertNull($row->recipient_count);
        $this->assertAnalyticsColumnsAreNullable();
        $this->assertAnalyticsIndexesExist();
    }

    private function assertAnalyticsIndexesExist(): void
    {
        global $wpdb;

        /** @var array<int,object{Key_name:string,Column_name:string,Seq_in_index:string}> $rows */
        $rows   = $wpdb->get_results("SHOW INDEX FROM `{$this->logsTable}`");
        $actual = [];
        foreach ($rows as $row) {
            $actual[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }

        $expected = [
            'idx_created_at'                => ['created_at'],
            'idx_source_created_utc'        => ['source_plugin', 'created_at_utc'],
            'idx_connection_id_created_utc' => ['connection_id', 'created_at_utc'],
            'idx_created_at_utc'            => ['created_at_utc'],
        ];

        foreach ($expected as $index => $columns) {
            $this->assertArrayHasKey($index, $actual);
            $this->assertSame($columns, array_values($actual[$index]));
        }

        // Analytics no longer filters by legacy display timestamps, raw connection, or status.
        // Retaining these composite write indexes would add write cost without helping a query.
        foreach ([
            'idx_source_created',
            'idx_connection_created',
            'idx_connection_id_created',
            'idx_status_created',
            'idx_connection_created_utc',
            'idx_status_created_utc',
        ] as $unusedIndex) {
            $this->assertArrayNotHasKey($unusedIndex, $actual);
        }
    }

    private function createLegacyLogsTable(): void
    {
        global $wpdb;

        $result = $wpdb->query(
            "CREATE TABLE `{$this->logsTable}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `status` TINYINT NOT NULL,
                `subject` LONGTEXT NOT NULL,
                `to_addr` LONGTEXT NOT NULL,
                `details` LONGTEXT NULL,
                `debug_info` TEXT NULL,
                `retry_count` TINYINT NOT NULL DEFAULT 0,
                `connection` VARCHAR(191) NULL,
                `connection_id` VARCHAR(191) NULL,
                `message_id` VARCHAR(191) NULL,
                `tracking_id` VARCHAR(64) NULL,
                `delivery_status` VARCHAR(32) NULL,
                `delivery_updated_at` DATETIME NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_connection_id` (`connection_id`),
                KEY `idx_message_id` (`message_id`),
                KEY `idx_tracking_id` (`tracking_id`)
            ) {$wpdb->get_charset_collate()}"
        );

        $this->assertNotFalse($result);
    }

    private function seedLegacyLog(?string $createdAt = null): void
    {
        global $wpdb;

        $row = [
            'id'      => 1,
            'status'  => 1,
            'subject' => 'Legacy subject',
            'to_addr' => '["legacy@example.test"]',
        ];
        if ($createdAt !== null) {
            $row['created_at'] = $createdAt;
            $row['updated_at'] = $createdAt;
        }

        $inserted = $wpdb->insert(
            $this->logsTable,
            $row,
            $createdAt === null ? ['%d', '%d', '%s', '%s'] : ['%d', '%d', '%s', '%s', '%s', '%s']
        );

        $this->assertSame(1, $inserted);
    }

    private function dropLogsTable(): void
    {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS `{$this->logsTable}`");
    }

    private function tableName(): string
    {
        return $GLOBALS['wpdb']->prefix . Config::VAR_PREFIX . 'logs';
    }
}
