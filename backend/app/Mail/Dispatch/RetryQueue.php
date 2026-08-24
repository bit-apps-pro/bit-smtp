<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection as DbConnection;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Mail\Exceptions\CredentialCipherException;
use BitApps\SMTP\Mail\Message\MailMessage;
use InvalidArgumentException;

/**
 * Durable store for sends deferred to a later retry attempt. Talks to the real `$wpdb` directly
 * (not the Schema/Blueprint/Model DSL) because claiming due rows needs a raw conditional
 * `UPDATE ... ORDER BY ... LIMIT` that the DSL doesn't expose.
 */
class RetryQueue
{
    /**
     * Encrypt and persist one deferred send; the row becomes claimable once `next_attempt_at` arrives.
     *
     * @param array<string,mixed> $mailData
     * @param string[]            $connectionIds
     */
    public function enqueue(MailMessage $message, array $mailData, array $connectionIds, string $failureClass, ?int $logId, int $maxAttempts, int $firstDelaySeconds): void
    {
        global $wpdb;

        $payload = CredentialCipher::encrypt((string) wp_json_encode([
            'message'   => $message->toArray(),
            'mail_data' => $mailData,
        ]));
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert(
            $this->table(),
            [
                'log_id'           => $logId,
                'payload'          => $payload,
                'connection_chain' => implode(',', $connectionIds),
                'attempts'         => 0,
                'max_attempts'     => $maxAttempts,
                'failure_class'    => $failureClass,
                'next_attempt_at'  => gmdate('Y-m-d H:i:s', time() + $firstDelaySeconds),
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            ['%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Atomically claim up to $limit due-and-unlocked (or stale-locked) rows for this worker run and
     * return them decoded, oldest-due first. Safe to call from overlapping cron runs. Each row
     * carries this run's claim_token so the worker can re-assert ownership (renewClaim) before it
     * actually sends.
     *
     * @return array<int,array{id:int,log_id:?int,message:MailMessage,mail_data:array<string,mixed>,connection_ids:string[],attempts:int,max_attempts:int,claim_token:string}>
     */
    public function claimDue(int $limit, int $lockTtlSeconds = 600): array
    {
        global $wpdb;

        $table       = $this->table();
        $token       = wp_generate_uuid4();
        $now         = gmdate('Y-m-d H:i:s');
        $staleBefore = gmdate('Y-m-d H:i:s', time() - $lockTtlSeconds);

        // Row-level locking during this UPDATE is what makes two concurrent claimDue() calls (e.g.
        // overlapping cron runs) mutually exclusive: a row a first UPDATE has already claimed (fresh
        // claim_token + locked_at) fails a second UPDATE's WHERE until locked_at goes stale, so the
        // same row can never be claimed by two callers at once.
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET claim_token = %s, locked_at = %s, updated_at = %s
             WHERE next_attempt_at <= %s AND (claim_token IS NULL OR locked_at < %s)
             ORDER BY next_attempt_at ASC LIMIT %d",
            $token,
            $now,
            $now,
            $now,
            $staleBefore,
            $limit
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE claim_token = %s ORDER BY next_attempt_at ASC",
            $token
        ), ARRAY_A);

        $claimed = [];
        foreach ($rows as $row) {
            $decoded = $this->decodeRow($row);
            if ($decoded !== null) {
                $decoded['claim_token'] = $token;
                $claimed[]              = $decoded;
            }
        }

        return $claimed;
    }

    /**
     * Re-assert and extend this worker's lock on a claimed row immediately before dispatch. Returns
     * false when the claim was lost to an overlapping worker -- a long batch can outlive its lock and
     * let the tail be reclaimed -- so the caller skips the row instead of delivering it a second time.
     */
    public function renewClaim(int $id, string $token): bool
    {
        global $wpdb;

        $table = $this->table();
        $now   = gmdate('Y-m-d H:i:s');

        // Push the stale horizon forward so this row can't be reclaimed while its own send is in flight.
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET locked_at = %s, updated_at = %s WHERE id = %d AND claim_token = %s",
            $now,
            $now,
            $id,
            $token
        ));

        // Confirm ownership by re-reading the token: a MySQL UPDATE that changes no column value still
        // reports zero affected rows, so the affected-count can't tell "not ours" from "already fresh".
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT claim_token FROM `{$table}` WHERE id = %d",
            $id
        )) === $token;
    }

    /**
     * Push a claimed row back into the queue for a later attempt and release its claim.
     */
    public function reschedule(int $id, int $attempts, string $failureClass, int $delaySeconds): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            [
                'attempts'        => $attempts,
                'failure_class'   => $failureClass,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delaySeconds),
                'claim_token'     => null,
                'locked_at'       => null,
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%d', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Remove a row: either it delivered, or its retry budget/eligibility is exhausted.
     */
    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    /**
     * Total rows currently queued, for the admin retry-queue panel.
     */
    public function depth(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $this->table() . '`');
    }

    /**
     * List queued rows as display metadata (never the encrypted payload) for the admin panel,
     * oldest-due first.
     *
     * @return array<int,array{id:int,attempts:int,max_attempts:int,failure_class:?string,connection_chain:string,next_attempt_at:string,created_at:string,locked:bool}>
     */
    public function pending(int $limit = 100): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, attempts, max_attempts, failure_class, connection_chain, next_attempt_at, created_at, locked_at
             FROM `{$this->table()}` ORDER BY next_attempt_at ASC LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_map(static function (array $row): array {
            return [
                'id'               => (int) $row['id'],
                'attempts'         => (int) $row['attempts'],
                'max_attempts'     => (int) $row['max_attempts'],
                'failure_class'    => $row['failure_class'] !== null ? (string) $row['failure_class'] : null,
                'connection_chain' => (string) $row['connection_chain'],
                'next_attempt_at'  => (string) $row['next_attempt_at'],
                'created_at'       => (string) $row['created_at'],
                'locked'           => $row['locked_at'] !== null,
            ];
        }, $rows ?: []);
    }

    /**
     * Discard every queued row (admin "clear queue" action); returns the number removed, or false on a
     * database error so the caller reports the failure instead of a misleading "0 discarded".
     *
     * @return false|int
     */
    public function clear()
    {
        global $wpdb;

        return $wpdb->query('DELETE FROM `' . $this->table() . '`');
    }

    /**
     * Decode a raw queue row into its typed shape; a corrupted/unrecoverable payload (rotated
     * wp_salt, malformed JSON) is skipped rather than allowed to crash the whole claimed batch.
     *
     * @param array<string,mixed> $row
     *
     * @return null|array{id:int,log_id:?int,message:MailMessage,mail_data:array<string,mixed>,connection_ids:string[],attempts:int,max_attempts:int}
     */
    private function decodeRow(array $row): ?array
    {
        try {
            $decoded = json_decode(CredentialCipher::decrypt($row['payload']), true);
            if (!\is_array($decoded) || !isset($decoded['message'], $decoded['mail_data'])) {
                throw new InvalidArgumentException('malformed retry payload');
            }

            $chain = (string) $row['connection_chain'];

            return [
                'id'             => (int) $row['id'],
                'log_id'         => $row['log_id'] !== null ? (int) $row['log_id'] : null,
                'message'        => MailMessage::fromArray($decoded['message']),
                'mail_data'      => $decoded['mail_data'],
                'connection_ids' => $chain === '' ? [] : explode(',', $chain),
                'attempts'       => (int) $row['attempts'],
                'max_attempts'   => (int) $row['max_attempts'],
            ];
        } catch (CredentialCipherException | InvalidArgumentException $e) {
            // A poisoned row (rotated wp_salt, corrupted/malformed payload) can never be decoded, so
            // drop it outright -- leaving it in place would re-lock it every cron tick and inflate
            // depth() forever. Never log the payload/decrypted content itself, only the row id.
            $this->delete((int) $row['id']);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- degrade gracefully per row
            error_log('Bit SMTP: retry queue row ' . $row['id'] . ' could not be decoded, deleted.');

            return null;
        }
    }

    /**
     * Fully-qualified retry queue table name, built the same way every other migration/model in
     * this plugin derives its prefixed table name.
     */
    private function table(): string
    {
        return DbConnection::getPrefix() . 'mail_retry_queue';
    }
}
