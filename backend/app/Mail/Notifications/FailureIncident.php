<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Mail\Connections\Connection;
use WP_Error;

/**
 * Immutable send-failure outage state; serializes to option and cron snapshot.
 */
final class FailureIncident
{
    /**
     * Timeout before re-queueing pending alert; prevents silent incident loss.
     */
    public const PENDING_TIMEOUT_SECONDS = 600;

    private const MAX_FIELD_LENGTH = 300;

    private const MAX_RECIPIENTS = 10;

    private const MAX_ATTEMPTS = 5;

    /**
     * Built only through start() and fromArray().
     *
     * @param array{code:string,message:string}                         $error
     * @param null|array{id:string,name:string,provider:string}         $connection
     * @param array{subject:string,recipients:list<string>}             $mail
     * @param list<array{connection:string,status:string,error:string}> $attempts
     */
    private function __construct(
        private string $id,
        private int $startedAt,
        private int $lastFailedAt,
        private int $failedCount,
        private array $error,
        private ?array $connection,
        private array $mail,
        private array $attempts,
        private bool $openedNotified,
        private ?int $lastNotifiedAt,
        private ?int $pendingSince,
        private int $sequence,
        private ?int $resolvedAt
    ) {
    }

    /**
     * A fresh incident for the first failure of an outage.
     */
    public static function start(string $id, int $now, WP_Error $error, ?Connection $connection): self
    {
        $details = self::failureDetails($error, $connection);

        return new self($id, $now, $now, 1, $details['error'], $details['connection'], $details['mail'], $details['attempts'], false, null, null, 0, null);
    }

    /**
     * Rebuild an incident from its stored or scheduled array form; null for anything malformed.
     *
     * @param mixed $data
     */
    public static function fromArray($data): ?self
    {
        if (!\is_array($data) || !self::isNonEmptyString($data['id'] ?? null) || !\is_int($data['started_at'] ?? null)) {
            return null;
        }

        $error      = \is_array($data['error'] ?? null) ? $data['error'] : [];
        $connection = \is_array($data['connection'] ?? null) ? $data['connection'] : null;
        $mail       = \is_array($data['mail'] ?? null) ? $data['mail'] : [];

        return new self(
            $data['id'],
            $data['started_at'],
            self::intOr($data['last_failed_at'] ?? null, $data['started_at']),
            max(1, self::intOr($data['failed_count'] ?? null, 1)),
            ['code' => self::text($error['code'] ?? ''), 'message' => self::text($error['message'] ?? '')],
            $connection === null ? null : self::connectionFields($connection),
            ['subject' => self::text($mail['subject'] ?? ''), 'recipients' => self::boundedRecipients($mail['recipients'] ?? [])],
            self::boundedAttempts($data['attempts'] ?? []),
            ($data['opened_notified'] ?? false) === true,
            self::nullableInt($data['last_notified_at'] ?? null),
            self::nullableInt($data['pending_since'] ?? null),
            self::intOr($data['sequence'] ?? null, 0),
            self::nullableInt($data['resolved_at'] ?? null)
        );
    }

    /**
     * The array form stored in the incident option and passed as the cron snapshot.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'started_at'       => $this->startedAt,
            'last_failed_at'   => $this->lastFailedAt,
            'failed_count'     => $this->failedCount,
            'error'            => $this->error,
            'connection'       => $this->connection,
            'mail'             => $this->mail,
            'attempts'         => $this->attempts,
            'opened_notified'  => $this->openedNotified,
            'last_notified_at' => $this->lastNotifiedAt,
            'pending_since'    => $this->pendingSince,
            'sequence'         => $this->sequence,
            'resolved_at'      => $this->resolvedAt,
        ];
    }

    /**
     * Count another failed send and remember its details as the latest.
     */
    public function withFailure(int $now, WP_Error $error, ?Connection $connection): self
    {
        $details = self::failureDetails($error, $connection);
        $next    = clone $this;

        $next->lastFailedAt = $now;
        ++$next->failedCount;
        $next->error      = $details['error'];
        $next->connection = $details['connection'];
        $next->mail       = $details['mail'];
        $next->attempts   = $details['attempts'];

        return $next;
    }

    /**
     * The alert event this incident owes right now, or null while one is in flight or none is due.
     */
    public function dueAlert(int $now, int $reminderSeconds): ?string
    {
        if ($this->pendingSince !== null && $now - $this->pendingSince < self::PENDING_TIMEOUT_SECONDS) {
            return null;
        }

        if (!$this->openedNotified) {
            return FailureNotification::EVENT_OPENED;
        }

        if ($reminderSeconds > 0 && $now - (int) $this->lastNotifiedAt >= $reminderSeconds) {
            return FailureNotification::EVENT_REMINDER;
        }

        return null;
    }

    /**
     * Mark a new alert as in flight; the bumped sequence makes its cron snapshot unique.
     */
    public function withAlertPending(int $now): self
    {
        $next = clone $this;

        $next->pendingSince = $now;
        ++$next->sequence;

        return $next;
    }

    /**
     * Record how the in-flight alert went; an undelivered one leaves the incident owing it again.
     */
    public function withAlertOutcome(string $event, bool $delivered, int $now): self
    {
        $next = clone $this;

        $next->pendingSince = null;
        if ($delivered) {
            $next->lastNotifiedAt = $now;
            $next->openedNotified = $next->openedNotified || $event === FailureNotification::EVENT_OPENED;
        }

        return $next;
    }

    /**
     * This incident closed at the given time.
     */
    public function withResolution(int $now): self
    {
        $next = clone $this;

        $next->resolvedAt = $now;

        return $next;
    }

    /**
     * True when an opened alert reached someone or is still in flight, so a resolution is worth sending.
     */
    public function announcesResolution(): bool
    {
        return $this->openedNotified || $this->pendingSince !== null;
    }

    /**
     * True when this is the given incident at the given alert sequence.
     */
    public function matchesAlert(string $id, int $sequence): bool
    {
        return $this->id === $id && $this->sequence === $sequence;
    }

    /**
     * The alert sequence, bumped for every alert queued.
     */
    public function sequence(): int
    {
        return $this->sequence;
    }

    /**
     * The incident's opaque identifier.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Unix time of the first failure.
     */
    public function startedAt(): int
    {
        return $this->startedAt;
    }

    /**
     * Unix time of the latest failure.
     */
    public function lastFailedAt(): int
    {
        return $this->lastFailedAt;
    }

    /**
     * Failed sends counted since the incident started (approximate under concurrency).
     */
    public function failedCount(): int
    {
        return $this->failedCount;
    }

    /**
     * Unix time the incident closed, or null while open.
     */
    public function resolvedAt(): ?int
    {
        return $this->resolvedAt;
    }

    /**
     * The latest failure's error code and bounded message.
     *
     * @return array{code:string,message:string}
     */
    public function error(): array
    {
        return $this->error;
    }

    /**
     * The latest failure's connection identity, or null for the WordPress default mailer.
     *
     * @return null|array{id:string,name:string,provider:string}
     */
    public function connection(): ?array
    {
        return $this->connection;
    }

    /**
     * The latest failed message's subject and bounded recipient list.
     *
     * @return array{subject:string,recipients:list<string>}
     */
    public function mail(): array
    {
        return $this->mail;
    }

    /**
     * The latest failure's bounded per-connection attempt summary.
     *
     * @return list<array{connection:string,status:string,error:string}>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * Extract the bounded, body-free details of one failed send.
     *
     * @return array{error:array{code:string,message:string},connection:null|array{id:string,name:string,provider:string},mail:array{subject:string,recipients:list<string>},attempts:list<array{connection:string,status:string,error:string}>}
     */
    private static function failureDetails(WP_Error $error, ?Connection $connection): array
    {
        $data       = $error->get_error_data();
        $data       = \is_array($data) ? $data : [];
        $recipients = $data['to'] ?? [];

        return [
            'error' => [
                'code'    => self::text($error->get_error_code()),
                'message' => self::text(implode('; ', $error->get_error_messages())),
            ],
            'connection' => $connection === null ? null : self::connectionFields([
                'id'       => $connection->getId(),
                'name'     => $connection->label(),
                'provider' => $connection->getProvider(),
            ]),
            'mail' => [
                'subject'    => self::text($data['subject'] ?? ''),
                'recipients' => self::boundedRecipients(\is_array($recipients) ? $recipients : explode(',', (string) $recipients)),
            ],
            'attempts' => self::boundedAttempts($data['attempts'] ?? []),
        ];
    }

    /**
     * Reduce a connection identity to its bounded id/name/provider strings.
     *
     * @param array<mixed> $connection
     *
     * @return array{id:string,name:string,provider:string}
     */
    private static function connectionFields(array $connection): array
    {
        return [
            'id'       => self::text($connection['id'] ?? ''),
            'name'     => self::text($connection['name'] ?? ''),
            'provider' => self::text($connection['provider'] ?? ''),
        ];
    }

    /**
     * Keep at most MAX_RECIPIENTS non-empty scalar recipients.
     *
     * @param mixed $recipients
     *
     * @return list<string>
     */
    private static function boundedRecipients($recipients): array
    {
        if (!\is_array($recipients)) {
            return [];
        }

        $clean = array_values(array_filter(array_map(self::text(...), $recipients), static fn (string $recipient): bool => $recipient !== ''));

        return \array_slice($clean, 0, self::MAX_RECIPIENTS);
    }

    /**
     * Keep at most MAX_ATTEMPTS attempts, reduced to their bounded connection/status/error strings.
     *
     * @param mixed $attempts
     *
     * @return list<array{connection:string,status:string,error:string}>
     */
    private static function boundedAttempts($attempts): array
    {
        if (!\is_array($attempts)) {
            return [];
        }

        $clean = [];
        foreach (array_filter($attempts, 'is_array') as $attempt) {
            $clean[] = [
                'connection' => self::text($attempt['connection'] ?? 'unknown'),
                'status'     => self::text($attempt['status'] ?? 'failed'),
                'error'      => self::text($attempt['error'] ?? ''),
            ];
            if (\count($clean) === self::MAX_ATTEMPTS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * A trimmed scalar clamped to MAX_FIELD_LENGTH characters; '' for anything non-scalar.
     *
     * @param mixed $value
     */
    private static function text($value): string
    {
        return \is_scalar($value) ? mb_substr(trim((string) $value), 0, self::MAX_FIELD_LENGTH) : '';
    }

    /**
     * True for a non-empty string.
     *
     * @param mixed $value
     */
    private static function isNonEmptyString($value): bool
    {
        return \is_string($value) && $value !== '';
    }

    /**
     * The value when it is an int, otherwise the default.
     *
     * @param mixed $value
     */
    private static function intOr($value, int $default): int
    {
        return \is_int($value) ? $value : $default;
    }

    /**
     * The value when it is an int, otherwise null.
     *
     * @param mixed $value
     */
    private static function nullableInt($value): ?int
    {
        return \is_int($value) ? $value : null;
    }
}
