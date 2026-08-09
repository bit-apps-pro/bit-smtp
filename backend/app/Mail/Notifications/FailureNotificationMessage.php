<?php

namespace BitApps\SMTP\Mail\Notifications;

final class FailureNotificationMessage
{
    public const MAX_LENGTH = 3500;

    private const MAX_FIELD_LENGTH = 300;

    private const MAX_RECIPIENTS = 10;

    private const MAX_ATTEMPTS = 5;

    public static function plainText(FailureNotification $notification): string
    {
        $payload    = $notification->toArray();
        $site       = \is_array($payload['site'] ?? null) ? $payload['site'] : [];
        $error      = \is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $mail       = \is_array($payload['mail'] ?? null) ? $payload['mail'] : [];
        $connection = \is_array($payload['connection'] ?? null) ? $payload['connection'] : [];
        $recipients = \is_array($mail['recipients'] ?? null) ? $mail['recipients'] : [];
        $attempts   = \is_array($payload['attempts'] ?? null) ? $payload['attempts'] : [];

        $lines = [
            ($notification->isTest() ? '[TEST] ' : '') . 'Email send failed',
            'Site: ' . self::field($site['name'] ?? ''),
            'URL: ' . self::field($site['url'] ?? ''),
            'Failed at: ' . self::field($payload['failed_at'] ?? ''),
            'Error [' . self::field($error['code'] ?? '') . ']: ' . self::field($error['message'] ?? ''),
            'Connection: ' . self::connection($connection),
            'Subject: ' . self::field($mail['subject'] ?? ''),
            'Recipients: ' . self::recipients($recipients),
        ];

        $attemptLines = self::attempts($attempts);
        if ($attemptLines !== []) {
            $lines[] = 'Attempts:';
            foreach ($attemptLines as $attempt) {
                $lines[] = $attempt;
            }
        }

        return self::truncate(implode("\n", $lines), self::MAX_LENGTH);
    }

    /**
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
     * @param mixed $value
     */
    private static function field($value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $value      = strip_tags((string) $value);
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        return self::truncate(trim($normalized ?? ''), self::MAX_FIELD_LENGTH);
    }

    private static function truncate(string $value, int $limit): string
    {
        if (\strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit - 3) . '...';
    }
}
