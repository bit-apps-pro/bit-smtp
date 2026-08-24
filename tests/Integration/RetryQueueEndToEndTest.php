<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Mail\Dispatch\RetryWorker;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * End-to-end: a retryable send failure with retry_enabled on lands a row in mail_retry_queue, and a
 * RetryWorker::process() run re-dispatches it through the same connection id, delivering once that
 * connection becomes reachable and removing the row.
 *
 * @internal
 *
 * @coversNothing
 */
final class RetryQueueEndToEndTest extends IntegrationTestCase
{
    private string $queueTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueTable = $GLOBALS['wpdb']->prefix . Config::VAR_PREFIX . 'mail_retry_queue';
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$this->queueTable}`");
    }

    public function testRetryableFailureEnqueuesAndTheWorkerDeliversOnceTheConnectionBecomesReachable(): void
    {
        PluginSettings::make()->set('retry_enabled', true)->set('retry_max_attempts', 3)->save();

        $this->storeV2([
            $this->connection('conn_primary', '127.0.0.1', 2),
        ], 'conn_primary', []);

        $sent = wp_mail('to@example.org', 'Retry Me', 'Body');
        $this->assertFalse($sent, 'the unreachable primary must fail the initial send');

        $rows = $this->queueRows();
        $this->assertCount(1, $rows, 'a retryable failure with retry_enabled must enqueue exactly one row');
        $this->assertSame('conn_primary', $rows[0]->connection_chain);
        $this->assertSame(FailureCategory::TRANSIENT, $rows[0]->failure_class);
        $this->assertSame(0, (int) $rows[0]->attempts);
        $this->assertNull($rows[0]->log_id);
        // The real enqueue path schedules the first attempt ~5 minutes out (RetryWorker::computeDelay),
        // not immediately; backdate it here so this test can exercise a due claim without sleeping.
        $this->backdateQueueRowToBeDue((int) $rows[0]->id);

        // Same connection id, now pointed at a reachable mailpit host: the worker must re-dispatch
        // dispatchRetry() by connection id, so this repoint is what makes the retry succeed.
        $this->storeV2([
            $this->connection('conn_primary', self::SMTP_HOST, self::SMTP_PORT),
        ], 'conn_primary', []);

        (new RetryWorker(new RetryQueue(), Plugin::instance()->smtpProvider()))->process();

        $this->assertEmpty($this->queueRows(), 'a delivered retry must be removed from the queue');
        $this->assertNotEmpty($this->mailpitMessages(), 'the worker-driven retry should deliver to mailpit');
    }

    /**
     * @return array<int,object{id:string,connection_chain:string,failure_class:?string,attempts:string,log_id:?string}>
     */
    private function queueRows(): array
    {
        global $wpdb;

        return $wpdb->get_results("SELECT * FROM `{$this->queueTable}`");
    }

    /**
     * Force a just-enqueued row's next_attempt_at into the past so claimDue() picks it up without
     * this test having to sleep out the real ~5-minute first-attempt backoff.
     */
    private function backdateQueueRowToBeDue(int $id): void
    {
        global $wpdb;
        $wpdb->update($this->queueTable, ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $id], ['%s'], ['%d']);
    }

    /**
     * @param array<int,array<string,mixed>> $connections
     * @param string[]                       $fallbackIds
     */
    private function storeV2(array $connections, string $defaultId, array $fallbackIds): void
    {
        $this->storeOptions([
            'schema_version'          => 2,
            'enabled'                 => true,
            'default_connection_id'   => $defaultId,
            'fallback_connection_ids' => $fallbackIds,
            'connections'             => $connections,
            'features'                => [],
        ]);

        Plugin::instance()->mailConfigService()->reload();
    }

    /**
     * @return array<string,mixed>
     */
    private function connection(string $id, string $host, int $port): array
    {
        return [
            'id'           => $id,
            'provider'     => 'other_smtp',
            'kind'         => 'smtp',
            'name'         => $id,
            'enabled'      => true,
            'fromEmail'    => 'from@example.org',
            'fromName'     => 'From',
            'replyToEmail' => '',
            'settings'     => ['host' => $host, 'port' => $port, 'encryption' => 'none', 'auth' => false],
            'credentials'  => [],
        ];
    }
}
