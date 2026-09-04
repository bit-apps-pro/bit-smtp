<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use BitApps\SMTP\Model\LogEngagementEvent;
use BitApps\SMTP\Settings\PluginSettings;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * Base for integration tests. Real WordPress (wp-phpunit) is loaded by the bootstrap; the
 * ephemeral docker `db` and `mailpit` services back the run. Extends plain TestCase because
 * wp-phpunit's WP_UnitTestCase is not PHPUnit 12 compatible; DB state is reset per test here.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const MAILPIT_API = 'http://127.0.0.1:8025/api/v1';

    protected const SMTP_HOST = '127.0.0.1';

    protected const SMTP_PORT = 1025;

    protected function setUp(): void
    {
        parent::setUp();
        Config::deleteOption('options');
        Config::deleteOption('failure_notification_active');
        // Preferences are seeded once at install time and otherwise untouched by this reset, so a
        // test that relies on the legacy-option fallback (no blob written yet) needs a clean slate.
        delete_option(PluginSettings::OPTION_NAME);
        $this->clearMailpit();
    }

    /**
     * Empties $tables with foreign-key enforcement suspended for the duration. The schema is InnoDB
     * with enforced constraints, and MySQL refuses TRUNCATE outright on a table a foreign key
     * references, so the parent log table cannot be cleared without this.
     */
    protected function truncateTables(string ...$tables): void
    {
        $tables = $this->withCascadeChildren($tables);

        $this->withoutForeignKeyChecks(static function ($wpdb) use ($tables): void {
            foreach ($tables as $table) {
                $wpdb->query("TRUNCATE TABLE `{$table}`");
            }
        });
    }

    /**
     * Drops $tables with foreign-key enforcement suspended, for migration tests that rebuild a table
     * a child table still references.
     */
    protected function dropTables(string ...$tables): void
    {
        $this->withoutForeignKeyChecks(static function ($wpdb) use ($tables): void {
            foreach ($tables as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
        });
    }

    /**
     * Persist the plugin option (legacy flat array or v2 schema).
     *
     * @param array<string,mixed> $options
     */
    protected function storeOptions(array $options): void
    {
        Config::updateOption('options', $options);
    }

    protected function clearMailpit(): void
    {
        wp_remote_request(self::MAILPIT_API . '/messages', ['method' => 'DELETE']);
    }

    /**
     * Swap wp-phpunit's MockPHPMailer (which captures mail without sending) for a real PHPMailer,
     * so wp_mail() performs a genuine SMTP send to mailpit through our phpmailer_init config.
     */
    protected function useRealPhpMailer(): void
    {
        // WP loads PHPMailer/SMTP lazily; require them so phpmailer_init can reference SMTP constants.
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        global $phpmailer;
        $phpmailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    }

    /**
     * @return array<int,array<string,mixed>> Mailpit message summaries, newest first
     */
    protected function mailpitMessages(): array
    {
        $response = wp_remote_get(self::MAILPIT_API . '/messages');
        $body     = json_decode(wp_remote_retrieve_body($response), true);

        return isset($body['messages']) && \is_array($body['messages']) ? $body['messages'] : [];
    }

    /**
     * @return array<string,mixed>|null Full latest delivered message, or null when the box is empty
     */
    protected function latestMailpitMessage(): ?array
    {
        $response = wp_remote_get(self::MAILPIT_API . '/message/latest');
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * @return array<string,array<int,string>> Raw headers of the delivered message, keyed by name
     */
    protected function mailpitHeaders(string $id): array
    {
        $response = wp_remote_get(self::MAILPIT_API . '/message/' . $id . '/headers');
        $headers  = json_decode(wp_remote_retrieve_body($response), true);

        return \is_array($headers) ? $headers : [];
    }

    /**
     * Precedes each cascade parent in $tables with its child tables, mirroring the schema's ON DELETE
     * CASCADE. Enforcement is suspended while truncating, so clearing a parent on its own would
     * strand child rows pointing at ids that no longer exist and bleed them into later tests.
     *
     * @param array<int,string> $tables
     *
     * @return array<int,string>
     */
    private function withCascadeChildren(array $tables): array
    {
        $logsTable = (new Log())->getTable();
        $expanded  = [];

        foreach ($tables as $table) {
            if ($table === $logsTable) {
                foreach ([(new LogDeliveryEvent())->getTable(), (new LogEngagementEvent())->getTable()] as $child) {
                    if ($this->tableExists($child)) {
                        $expanded[] = $child;
                    }
                }
            }

            $expanded[] = $table;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Whether $table is present, so an inferred cascade child is skipped rather than erroring out in a
     * migration test that has deliberately dropped it. Caller-named tables are never filtered.
     */
    private function tableExists(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Runs $callback against $wpdb with FOREIGN_KEY_CHECKS off, restoring it even when the callback
     * throws. Scoped this narrowly on purpose: enforcement stays on inside the tests themselves, so
     * the ON DELETE CASCADE behavior they assert is still real.
     *
     * @param callable(wpdb):void $callback
     */
    private function withoutForeignKeyChecks(callable $callback): void
    {
        global $wpdb;

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $callback($wpdb);
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
