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
        $recipient  = $data['recipient']   ?? '';
        $status     = $data['status']      ?? '';
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
        $hashData = $logId . '|' . $this->recipient . '|' . $this->status . '|' . ($this->occurredAt ?? '');

        return hash('sha256', $hashData);
    }

    private static function normalizeOccurredAt($raw): ?string
    {
        if (empty($raw)) {
            return null;
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
