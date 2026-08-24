<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitSmtpRetryQueueMigration;

/**
 * Exercises the mail_retry_queue schema migration against the real WordPress test database: the
 * expected columns exist with the expected nullability, and re-running up() is a no-op (proving the
 * CREATE TABLE IF NOT EXISTS idempotency the migration relies on).
 *
 * @internal
 *
 * @coversNothing
 */
final class RetryQueueMigrationTest extends IntegrationTestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $GLOBALS['wpdb']->prefix . Config::VAR_PREFIX . 'mail_retry_queue';
    }

    public function testMigrationCreatesTheExpectedColumnsAndNullability(): void
    {
        (new BitSmtpRetryQueueMigration())->up();

        $expected = [
            'id'               => 'NO',
            'log_id'           => 'YES',
            'payload'          => 'NO',
            'connection_chain' => 'NO',
            'attempts'         => 'NO',
            'max_attempts'     => 'NO',
            'failure_class'    => 'YES',
            'next_attempt_at'  => 'NO',
            'claim_token'      => 'YES',
            'locked_at'        => 'YES',
            'created_at'       => 'NO',
            'updated_at'       => 'NO',
        ];

        global $wpdb;
        foreach ($expected as $column => $nullable) {
            $definition = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$this->table}` LIKE %s", $column));
            $this->assertNotNull($definition, "{$column} should be present on the mail_retry_queue table");
            $this->assertSame($nullable, $definition->Null, "{$column} nullability mismatch");
        }
    }

    public function testMigrationIsIdempotentAcrossRepeatedActivations(): void
    {
        (new BitSmtpRetryQueueMigration())->up();
        (new BitSmtpRetryQueueMigration())->up();

        global $wpdb;
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->table)));
        $this->assertSame($this->table, $tableExists);
    }

    public function testDownDropsTheRealPrefixedTableOnUninstallWithPurge(): void
    {
        (new BitSmtpRetryQueueMigration())->up();

        global $wpdb;
        $this->assertSame(
            $this->table,
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->table))),
            'sanity: the prefixed table exists before teardown'
        );

        // uninstall_purge defaults to true, so down() must drop the *prefixed* table. A bare
        // Schema::drop() emits an unprefixed name that matches nothing and leaks the encrypted payloads.
        (new BitSmtpRetryQueueMigration())->down();

        $this->assertNull(
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->table))),
            'down() must drop the real prefixed mail_retry_queue table on uninstall-with-purge'
        );

        // Restore the table so this class stays order-independent for any later suite that TRUNCATEs it.
        (new BitSmtpRetryQueueMigration())->up();
    }
}
