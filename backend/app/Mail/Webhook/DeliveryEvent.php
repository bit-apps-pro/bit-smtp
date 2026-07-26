<?php

namespace BitApps\SMTP\Mail\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

class DeliveryEvent
{
    private ?string $messageId;

    private ?string $trackingId;

    private string $recipient;

    private string $status;

    private bool $terminal;

    private ?string $detail;

    private ?string $occurredAt;

    private function __construct(
        ?string $messageId,
        ?string $trackingId,
        string $recipient,
        string $status,
        bool $terminal,
        ?string $detail,
        ?string $occurredAt
    ) {
        $this->messageId  = $messageId;
        $this->trackingId = $trackingId;
        $this->recipient  = $recipient;
        $this->status     = $status;
        $this->terminal   = $terminal;
        $this->detail     = $detail;
        $this->occurredAt = $occurredAt;
    }

    public static function fromArray(array $data): self
    {
        $messageId  = $data['message_id']  ?? null;
        $trackingId = $data['tracking_id'] ?? null;
        // Cast defensively: a malformed payload may carry a non-string where a string is expected,
        // and the adapter contract requires parsing never to throw (a TypeError is not an Exception).
        $recipient  = (string) ($data['recipient'] ?? '');
        $status     = (string) ($data['status']    ?? '');
        $terminal   = (bool) ($data['terminal'] ?? false);
        $detail     = $data['detail'] ?? null;
        $occurredAt = self::normalizeOccurredAt($data['occurred_at'] ?? null);

        return new self($messageId, $trackingId, $recipient, $status, $terminal, $detail, $occurredAt);
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function trackingId(): ?string
    {
        return $this->trackingId;
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function occurredAt(): ?string
    {
        return $this->occurredAt;
    }

    public function correlationKeys(): array
    {
        return [
            'message_id'  => $this->messageId  === '' ? null : $this->messageId,
            'tracking_id' => $this->trackingId === '' ? null : $this->trackingId,
        ];
    }

    public function hash(int $logId): string
    {
        // Include terminal + detail so two genuinely-distinct no-timestamp events don't collide,
        // while an identical replay still hashes equal and dedups.
        $hashData = $logId . '|' . $this->recipient . '|' . $this->status . '|' . ($this->occurredAt ?? '')
            . '|' . ($this->terminal ? '1' : '0') . '|' . ($this->detail ?? '');

        return hash('sha256', $hashData);
    }

    private static function normalizeOccurredAt($raw): ?string
    {
        // A bare unix timestamp (int, float, or all-digit string) is not a parseable date string;
        // render it to UTC directly so adapters can hand us the raw provider value unmodified.
        if (\is_int($raw) || \is_float($raw)) {
            return gmdate('Y-m-d H:i:s', (int) $raw);
        }

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            return gmdate('Y-m-d H:i:s', (int) $raw);
        }

        try {
            $dt  = new DateTimeImmutable($raw);
            $utc = $dt->setTimezone(new DateTimeZone('UTC'));

            return $utc->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}
