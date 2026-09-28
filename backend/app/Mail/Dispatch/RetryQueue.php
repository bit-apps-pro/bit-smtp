<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Deps\BitApps\WPDatabase\QueryBuilder;
use BitApps\SMTP\Mail\Credentials\CredentialCipher;
use BitApps\SMTP\Mail\Exceptions\CredentialCipherException;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Model\RetryQueueEntry;
use InvalidArgumentException;

/**
 * Durable store for sends deferred to a later retry attempt, backed by the RetryQueueEntry model.
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
        $payload = CredentialCipher::encrypt((string) wp_json_encode([
            'message'   => $message->toArray(),
            'mail_data' => $mailData,
        ]));
        $now = self::utcNow();

        RetryQueueEntry::query()->insert([
            'log_id'           => $logId,
            'payload'          => $payload,
            'connection_chain' => implode(',', $connectionIds),
            'attempts'         => 0,
            'max_attempts'     => $maxAttempts,
            'failure_class'    => $failureClass,
            'next_attempt_at'  => self::utcIn($firstDelaySeconds),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    /**
     * Atomically claim due rows for this worker run; safe from overlapping cron; returns decoded oldest-first.
     *
     * @return array<int,array{id:int,log_id:?int,message:MailMessage,mail_data:array<string,mixed>,connection_ids:string[],attempts:int,max_attempts:int,claim_token:string}>
     */
    public function claimDue(int $limit, int $lockTtlSeconds = 600): array
    {
        $token       = wp_generate_uuid4();
        $now         = self::utcNow();
        $staleBefore = self::utcIn(-$lockTtlSeconds);

        $candidateIds = array_map(
            static fn (RetryQueueEntry $entry): int => (int) $entry->id,
            $this->claimable($now, $staleBefore)->orderBy('next_attempt_at')->take((string) $limit)->get(['id'])->all()
        );
        if ($candidateIds === []) {
            return [];
        }

        $this->claimable($now, $staleBefore)
            ->whereIn('id', $candidateIds)
            ->update(['claim_token' => $token, 'locked_at' => $now, 'updated_at' => $now]);

        $claimed = [];
        foreach (RetryQueueEntry::query()->where('claim_token', $token)->orderBy('next_attempt_at')->get()->all() as $entry) {
            $decoded = $this->decodeRow($entry->toArray());
            if ($decoded !== null) {
                $decoded['claim_token'] = $token;
                $claimed[]              = $decoded;
            }
        }

        return $claimed;
    }

    /**
     * Re-assert and extend lock; returns false if claim lost to overlapping worker.
     */
    public function renewClaim(int $id, string $token): bool
    {
        $now = self::utcNow();

        RetryQueueEntry::query()
            ->where('id', $id)
            ->where('claim_token', $token)
            ->update(['locked_at' => $now, 'updated_at' => $now]);

        $entry = RetryQueueEntry::query()->select(['claim_token'])->where('id', $id)->first();

        return $entry instanceof RetryQueueEntry && $entry->claim_token === $token;
    }

    /**
     * Push a claimed row back into the queue for a later attempt and release its claim.
     */
    public function reschedule(int $id, int $attempts, string $failureClass, int $delaySeconds): void
    {
        RetryQueueEntry::query()->where('id', $id)->update([
            'attempts'        => $attempts,
            'failure_class'   => $failureClass,
            'next_attempt_at' => self::utcIn($delaySeconds),
            'claim_token'     => null,
            'locked_at'       => null,
            'updated_at'      => self::utcNow(),
        ]);
    }

    /**
     * Remove a row: either it delivered, or its retry budget/eligibility is exhausted.
     */
    public function delete(int $id): void
    {
        RetryQueueEntry::query()->where('id', $id)->delete();
    }

    /**
     * Total rows currently queued, for the admin retry-queue panel.
     */
    public function depth(): int
    {
        return RetryQueueEntry::query()->count();
    }

    /**
     * List queued rows as display metadata (never encrypted payload) for the admin panel, oldest-due first.
     *
     * @return array<int,array{id:int,attempts:int,max_attempts:int,failure_class:?string,connection_chain:string,next_attempt_at:string,created_at:string,locked:bool}>
     */
    public function pending(int $limit = 100): array
    {
        $entries = RetryQueueEntry::query()
            ->orderBy('next_attempt_at')
            ->take((string) $limit)
            ->get(['id', 'attempts', 'max_attempts', 'failure_class', 'connection_chain', 'next_attempt_at', 'created_at', 'locked_at'])
            ->all();

        return array_map(static function (RetryQueueEntry $entry): array {
            return [
                'id'               => (int) $entry->id,
                'attempts'         => (int) $entry->attempts,
                'max_attempts'     => (int) $entry->max_attempts,
                'failure_class'    => $entry->failure_class !== null ? (string) $entry->failure_class : null,
                'connection_chain' => (string) $entry->connection_chain,
                'next_attempt_at'  => (string) $entry->next_attempt_at,
                'created_at'       => (string) $entry->created_at,
                'locked'           => $entry->locked_at !== null,
            ];
        }, $entries);
    }

    /**
     * Discard every queued row (admin "clear queue" action); returns count or false on database error.
     *
     * @return false|int
     */
    public function clear()
    {
        return RetryQueueEntry::query()->whereNotNull('id')->delete();
    }

    /**
     * Decode a raw queue row into its typed shape; a corrupted/unrecoverable payload (rotated wp_salt, malformed JSON) is deleted rather than allowed to crash the whole claimed batch or cause infinite re-locks.
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
            $this->delete((int) $row['id']);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log -- degrade gracefully per row
            error_log('Bit SMTP: retry queue row ' . $row['id'] . ' could not be decoded, deleted.');

            return null;
        }
    }

    /**
     * Due rows that are unclaimed or whose claim went stale before $staleBefore.
     */
    private function claimable(string $now, string $staleBefore): QueryBuilder
    {
        return RetryQueueEntry::query()
            ->where('next_attempt_at', '<=', $now)
            ->where(static function (QueryBuilder $lease) use ($staleBefore): void {
                $lease->whereNull('claim_token')->orWhere('locked_at', '<', $staleBefore);
            });
    }

    /**
     * The current UTC time as a MySQL DATETIME string.
     */
    private static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * The UTC time $seconds from now as a MySQL DATETIME string.
     */
    private static function utcIn(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }
}
