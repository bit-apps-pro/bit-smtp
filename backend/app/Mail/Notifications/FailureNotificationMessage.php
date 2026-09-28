<?php

namespace BitApps\SMTP\Mail\Notifications;

/**
 * Render FailureNotification as bounded plain text with state-specific details.
 */
final class FailureNotificationMessage
{
    public const MAX_LENGTH = 3500;

    private const MAX_FIELD_LENGTH = 300;

    private const MAX_RECIPIENTS = 10;

    private const MAX_ATTEMPTS = 5;

    /**
     * The headline followed by the state-specific detail lines, clamped to MAX_LENGTH bytes.
     */
    public static function plainText(FailureNotification $notification): string
    {
        $payload = $notification->toArray();
        $site    = \is_array($payload['site'] ?? null) ? $payload['site'] : [];

        $lines = array_merge(
            [
                ($notification->isTest() ? '[TEST] ' : '') . self::field($notification->headline()),
                'Site: ' . self::field($site['name'] ?? ''),
                'URL: ' . self::field($site['url'] ?? ''),
            ],
            $notification->eventType() === FailureNotification::EVENT_OPENED
                ? self::openedLines($payload)
                : self::incidentLines($payload, $notification->eventType() === FailureNotification::EVENT_RESOLVED)
        );

        return self::truncate(implode("\n", $lines), self::MAX_LENGTH);
    }

    /**
     * Details of the failure that opened the incident: error, connection, message, and attempts.
     *
     * @param array<string,mixed> $payload
     *
     * @return list<string>
     */
    private static function openedLines(array $payload): array
    {
        $mail       = \is_array($payload['mail'] ?? null) ? $payload['mail'] : [];
        $recipients = \is_array($mail['recipients'] ?? null) ? $mail['recipients'] : [];
        $attempts   = self::attempts(\is_array($payload['attempts'] ?? null) ? $payload['attempts'] : []);

        $lines = [
            'Failed at: ' . self::field($payload['failed_at'] ?? ''),
            'Error ' . self::error($payload),
            'Connection: ' . self::connection(\is_array($payload['connection'] ?? null) ? $payload['connection'] : []),
            'Subject: ' . self::field($mail['subject'] ?? ''),
            'Recipients: ' . self::recipients($recipients),
        ];

        return $attempts === [] ? $lines : array_merge($lines, ['Attempts:'], $attempts);
    }

    /**
     * Running totals of an ongoing or resolved incident plus its latest error and connection.
     *
     * @param array<string,mixed> $payload
     *
     * @return list<string>
     */
    private static function incidentLines(array $payload, bool $resolved): array
    {
        $incident = \is_array($payload['incident'] ?? null) ? $payload['incident'] : [];

        return [
            'Failing since: ' . self::field($incident['started_at'] ?? ''),
            $resolved
                ? 'Resolved at: ' . self::field($incident['resolved_at'] ?? '')
                : 'Last failure: ' . self::field($incident['last_failed_at'] ?? ''),
            'Failed emails: ' . self::field($incident['failed_count'] ?? 0),
            'Last error ' . self::error($payload),
            'Last connection: ' . self::connection(\is_array($payload['connection'] ?? null) ? $payload['connection'] : []),
        ];
    }

    /**
     * "[code]: message" for the payload's error.
     *
     * @param array<string,mixed> $payload
     */
    private static function error(array $payload): string
    {
        $error = \is_array($payload['error'] ?? null) ? $payload['error'] : [];

        return '[' . self::field($error['code'] ?? '') . ']: ' . self::field($error['message'] ?? '');
    }

    /**
     * "name (provider)" for a connection, or the WordPress default mailer when there is none.
     *
     * @param array<string,mixed> $connection
     */
    private static function connection(array $connection): string
    {
        if ($connection === []) {
            return 'WordPress default mailer';
        }

        $name     = self::field($connection['name'] ?? '');
        $provider = self::field($connection['provider'] ?? '');

        return $provider === '' ? $name : $name . ' (' . $provider . ')';
    }

    /**
     * At most MAX_RECIPIENTS sanitized recipients, comma-separated.
     *
     * @param array<mixed> $recipients
     */
    private static function recipients(array $recipients): string
    {
        $safe = [];
        foreach (\array_slice($recipients, 0, self::MAX_RECIPIENTS) as $recipient) {
            $safe[] = self::field($recipient);
        }

        return implode(', ', array_filter($safe, static fn (string $recipient): bool => $recipient !== ''));
    }

    /**
     * One "- connection: status (error)" line per attempt, at most MAX_ATTEMPTS.
     *
     * @param array<mixed> $attempts
     *
     * @return list<string>
     */
    private static function attempts(array $attempts): array
    {
        $lines = [];
        foreach (\array_slice($attempts, 0, self::MAX_ATTEMPTS) as $attempt) {
            if (!\is_array($attempt)) {
                continue;
            }

            $line = '- ' . self::field($attempt['connection'] ?? 'unknown')
                . ': ' . self::field($attempt['status'] ?? 'failed');
            $error = self::field($attempt['error'] ?? '');
            if ($error !== '') {
                $line .= ' (' . $error . ')';
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * A scalar as tag-free, single-line text clamped to MAX_FIELD_LENGTH; '' for non-scalars.
     *
     * @param mixed $value
     */
    private static function field($value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $value      = wp_strip_all_tags((string) $value);
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        return self::truncate(trim($normalized ?? ''), self::MAX_FIELD_LENGTH);
    }

    /**
     * Clamp to $limit bytes with a "..." suffix without splitting a UTF-8 character.
     */
    private static function truncate(string $value, int $limit): string
    {
        if (\strlen($value) <= $limit) {
            return $value;
        }

        $prefix = substr($value, 0, $limit - 3);
        while ($prefix !== '' && preg_match('//u', $prefix) !== 1) {
            $prefix = substr($prefix, 0, -1);
        }

        return $prefix . '...';
    }
}
