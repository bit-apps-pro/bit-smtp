<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Mail\Connections\Connection;
use WP_Error;

final class FailureNotification
{
    private string $siteName;

    private string $siteUrl;

    private string $failedAt;

    private string $error;

    private string $errorCode;

    private array $recipients;

    private string $subject;

    private ?Connection $connection;

    private array $attempts;

    private function __construct(
        string $siteName,
        string $siteUrl,
        string $failedAt,
        string $error,
        string $errorCode,
        array $recipients,
        string $subject,
        ?Connection $connection,
        array $attempts
    ) {
        $this->siteName   = $siteName;
        $this->siteUrl    = $siteUrl;
        $this->failedAt   = $failedAt;
        $this->error      = $error;
        $this->errorCode  = $errorCode;
        $this->recipients = $recipients;
        $this->subject    = $subject;
        $this->connection = $connection;
        $this->attempts   = $attempts;
    }

    public static function fromError(WP_Error $error, ?Connection $connection = null): self
    {
        $data       = $error->get_error_data();
        $data       = \is_array($data) ? $data : [];
        $recipients = $data['to'] ?? [];
        if (!\is_array($recipients)) {
            $recipients = explode(',', (string) $recipients);
        }

        return new self(
            (string) get_bloginfo('name'),
            home_url('/'),
            gmdate(DATE_ATOM),
            implode('; ', $error->get_error_messages()),
            (string) $error->get_error_code(),
            array_values(array_filter(array_map(static function ($recipient): string {
                return \is_scalar($recipient) ? trim((string) $recipient) : '';
            }, $recipients))),
            (string) ($data['subject'] ?? ''),
            $connection,
            isset($data['attempts']) && \is_array($data['attempts']) ? $data['attempts'] : []
        );
    }

    public function emailSubject(): string
    {
        return \sprintf('[Bit SMTP] Email sending failed on %s', $this->siteName);
    }

    public function emailBody(): string
    {
        $lines = [
            'Site: ' . $this->siteName,
            'URL: ' . $this->siteUrl,
            'Failed at: ' . $this->failedAt,
            'Error: ' . $this->error,
            'Connection: ' . $this->connectionLabel(),
            'Subject: ' . $this->subject,
            'Recipients: ' . implode(', ', $this->recipients),
        ];

        if ($this->attempts !== []) {
            $lines[] = 'Attempts:';
            foreach ($this->attempts as $attempt) {
                if (!\is_array($attempt)) {
                    continue;
                }

                $lines[] = \sprintf(
                    '- %s: %s%s',
                    (string) ($attempt['connection'] ?? 'unknown'),
                    (string) ($attempt['status'] ?? 'failed'),
                    !empty($attempt['error']) ? ' (' . (string) $attempt['error'] . ')' : ''
                );
            }
        }

        return implode("\n", $lines);
    }

    public function toArray(): array
    {
        return [
            'event'     => 'email_send_failed',
            'failed_at' => $this->failedAt,
            'site'      => [
                'name' => $this->siteName,
                'url'  => $this->siteUrl,
            ],
            'error' => [
                'code'    => $this->errorCode,
                'message' => $this->error,
            ],
            'mail' => [
                'subject'    => $this->subject,
                'recipients' => $this->recipients,
            ],
            'connection' => $this->connection === null ? null : [
                'id'       => $this->connection->getId(),
                'name'     => $this->connection->label(),
                'provider' => $this->connection->getProvider(),
            ],
            'attempts' => $this->attempts,
        ];
    }

    private function connectionLabel(): string
    {
        return $this->connection !== null ? $this->connection->label() : 'WordPress default mailer';
    }
}
