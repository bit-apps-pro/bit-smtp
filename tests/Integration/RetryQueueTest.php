<?php

namespace BitApps\SMTP\Tests\Integration;

use BitApps\SMTP\Config;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitSmtpRetryQueueMigration;

/**
 * Drives RetryQueue's enqueue/claim/reschedule/delete against the real test DB, including the
 * concurrency property the whole claim design exists to guarantee: two back-to-back claimDue()
 * calls (simulating overlapping cron runs) never return overlapping rows.
 *
 * @internal
 *
 * @coversNothing
 */
final class RetryQueueTest extends IntegrationTestCase
{
    private RetryQueue $queue;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        (new BitSmtpRetryQueueMigration())->up();
        $this->table = $GLOBALS['wpdb']->prefix . Config::VAR_PREFIX . 'mail_retry_queue';
        $this->truncate();
        $this->queue = new RetryQueue();
    }

    public function testEnqueueThenClaimDueRoundTripsTheMessageAndMailDataAndEncryptsThePayloadAtRest(): void
    {
        $message = MailMessage::fromArray([
            'to'      => ['a@example.com'],
            'cc'      => ['cc@example.com'],
            'subject' => 'Secret Subject',
            'body'    => 'Secret Body Content',
            'from'    => 'from@example.com',
        ]);
        $mailData = ['subject' => 'Secret Subject', 'to' => ['a@example.com']];

        $this->queue->enqueue($message, $mailData, ['conn_a', 'conn_b'], FailureCategory::TRANSIENT, null, 5, -60);

        global $wpdb;
        $raw = $wpdb->get_row("SELECT * FROM `{$this->table}`", ARRAY_A);
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('Secret Subject', $raw['payload'], 'the payload column must not carry the plaintext subject');
        $this->assertStringNotContainsString('Secret Body Content', $raw['payload'], 'the payload column must not carry the plaintext body');

        $claimed = $this->queue->claimDue(10);
        $this->assertCount(1, $claimed);
        $row = $claimed[0];

        $this->assertSame(['a@example.com'], $row['message']->getTo());
        $this->assertSame(['cc@example.com'], $row['message']->getCc());
        $this->assertSame('Secret Subject', $row['message']->getSubject());
        $this->assertSame('Secret Body Content', $row['message']->getBody());
        $this->assertSame('from@example.com', $row['message']->getFrom());
        $this->assertSame($mailData, $row['mail_data']);
        $this->assertSame(['conn_a', 'conn_b'], $row['connection_ids']);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame(5, $row['max_attempts']);
        $this->assertNull($row['log_id']);
    }

    public function testRescheduleUpdatesTheRowAndReleasesTheClaimSoItBecomesClaimableAgain(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B']);
        $this->queue->enqueue($message, [], ['conn_a'], FailureCategory::TRANSIENT, null, 5, -60);

        $claimed = $this->queue->claimDue(10);
        $this->assertCount(1, $claimed);
        $id = $claimed[0]['id'];

        // Still locked immediately after the first claim: a second claim right now must see nothing.
        $this->assertCount(0, $this->queue->claimDue(10));

        $this->queue->reschedule($id, 1, FailureCategory::RATE_LIMITED, -60);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->table}` WHERE id = %d", $id), ARRAY_A);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame(FailureCategory::RATE_LIMITED, $row['failure_class']);
        $this->assertNull($row['claim_token']);
        $this->assertNull($row['locked_at']);

        $reclaimed = $this->queue->claimDue(10);
        $this->assertCount(1, $reclaimed, 'a rescheduled row with a past next_attempt_at must be claimable again');
        $this->assertSame($id, $reclaimed[0]['id']);
        $this->assertSame(1, $reclaimed[0]['attempts']);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B']);
        $this->queue->enqueue($message, [], ['conn_a'], FailureCategory::TRANSIENT, null, 5, -60);
        $id = $this->queue->claimDue(10)[0]['id'];

        $this->queue->delete($id);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->table}` WHERE id = %d", $id));
        $this->assertNull($row);
        $this->assertSame(0, $this->queue->depth());
    }

    public function testTwoBackToBackClaimsNeverReturnOverlappingRows(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B']);
        for ($i = 0; $i < 6; ++$i) {
            $this->queue->enqueue($message, [], ['conn_a'], FailureCategory::TRANSIENT, null, 5, -60);
        }

        $this->assertSame(6, $this->queue->depth());

        // Simulates two overlapping cron runs racing claimDue() against the same due backlog.
        $first  = $this->queue->claimDue(4);
        $second = $this->queue->claimDue(4);

        $firstIds  = array_column($first, 'id');
        $secondIds = array_column($second, 'id');

        $this->assertCount(4, $firstIds);
        $this->assertCount(2, $secondIds, 'the second claim only gets the remaining unclaimed rows');
        $this->assertEmpty(array_intersect($firstIds, $secondIds), 'the two claims must never share a row id');
    }

    public function testRenewClaimFailsOnceAnOverlappingWorkerHasReclaimedTheRow(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B']);
        $this->queue->enqueue($message, [], ['conn_a'], FailureCategory::TRANSIENT, null, 5, -60);

        $claimed = $this->queue->claimDue(10);
        $id      = $claimed[0]['id'];
        $token   = $claimed[0]['claim_token'];

        $this->assertTrue($this->queue->renewClaim($id, $token), 'the original owner renews its own claim');

        // Simulate an overlapping cron run reclaiming the (stale) row under a different token.
        global $wpdb;
        $wpdb->update($this->table, ['claim_token' => 'other-worker'], ['id' => $id]);

        $this->assertFalse(
            $this->queue->renewClaim($id, $token),
            'a row reclaimed by another worker must not renew for the stale owner -- this is what prevents a double send'
        );
    }

    public function testClaimDueReapsAnUndecodablePoisonRowInsteadOfReLockingItForever(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($this->table, [
            'payload'          => 'not-a-valid-encrypted-payload',
            'connection_chain' => 'conn_a',
            'attempts'         => 0,
            'max_attempts'     => 3,
            'failure_class'    => FailureCategory::TRANSIENT,
            'next_attempt_at'  => gmdate('Y-m-d H:i:s', time() - 60),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->assertSame(1, $this->queue->depth());
        $this->assertCount(0, $this->queue->claimDue(10), 'an undecodable row is skipped, not returned');
        $this->assertSame(0, $this->queue->depth(), 'and is reaped so it cannot re-lock and inflate depth every tick');
    }

    private function truncate(): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE `{$this->table}`");
    }
}
