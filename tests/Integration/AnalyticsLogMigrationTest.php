<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Model\Log;
use BitSmtpLogsTableMigration;

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
        $this->assertAnalyticsColumnsAreNullable();
        $this->assertAnalyticsIndexesExist();
    }

    public function testLogModelAcceptsAttributionAndPreservesANullRuleIndex(): void
    {
        $log = new Log([
            'source_plugin'      => 'woocommerce',
            'routing_type'       => 'rule',
            'routing_rule_index' => '4',
        ]);

        $this->assertSame('woocommerce', $log->source_plugin);
        $this->assertSame('rule', $log->routing_type);
        $this->assertSame(4, $log->routing_rule_index);

        $log->routing_rule_index = null;
        $this->assertNull($log->routing_rule_index);
    }

    private function migrateLogs(): void
    {
        (new BitSmtpLogsTableMigration())->up();
    }

    private function assertAnalyticsColumnsAreNullable(): void
    {
        global $wpdb;

        foreach (['source_plugin', 'routing_type', 'routing_rule_index'] as $column) {
            $definition = $wpdb->get_row(
                $wpdb->prepare("SHOW COLUMNS FROM `{$this->logsTable}` LIKE %s", $column)
            );

            $this->assertNotNull($definition, "{$column} should be present on the logs table");
            $this->assertSame('YES', $definition->Null, "{$column} must preserve legacy rows as null");
        }
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
            'idx_source_created'     => ['source_plugin', 'created_at'],
            'idx_connection_created' => ['connection', 'created_at'],
            'idx_status_created'     => ['status', 'created_at'],
        ];

        foreach ($expected as $index => $columns) {
            $this->assertArrayHasKey($index, $actual);
            $this->assertSame($columns, array_values($actual[$index]));
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
                PRIMARY KEY (`id`)
            ) {$wpdb->get_charset_collate()}"
        );

        $this->assertNotFalse($result);
    }

    private function seedLegacyLog(): void
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->logsTable,
            [
                'id'      => 1,
                'status'  => 1,
                'subject' => 'Legacy subject',
                'to_addr' => '["legacy@example.test"]',
            ],
            ['%d', '%d', '%s', '%s']
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
