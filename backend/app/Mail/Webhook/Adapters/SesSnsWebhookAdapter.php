<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Webhook\Adapters;

use BitApps\SMTP\Mail\Aws\Sns\SnsMessage;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\Contracts\WebhookAdapterInterface;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Mail\Webhook\WebhookRequest;

/**
 * Maps an Amazon SES bounce/complaint/delivery notification — delivered inside an SNS Notification's
 * `Message` string — to per-recipient DeliveryEvents, correlated by the SES-assigned `mail.messageId`
 * (the id SesTransport captures at send). A confirmation/other SNS type or malformed JSON yields [].
 */
class SesSnsWebhookAdapter implements WebhookAdapterInterface
{
    public function parseEvents(WebhookRequest $request): array
    {
        $sns = SnsMessage::fromArray($request->decoded());
        if (!$sns->isNotification()) {
            return [];
        }

        $ses = json_decode($sns->message(), true);
        if (!\is_array($ses)) {
            return [];
        }

        // Identity feedback uses `notificationType`; configuration-set publishing uses `eventType`.
        $type      = (string) ($ses['notificationType'] ?? $ses['eventType'] ?? '');
        $messageId = $this->messageId($ses);

        switch ($type) {
            case 'Bounce':
                return $this->bounceEvents($ses, $messageId);
            case 'Complaint':
                return $this->complaintEvents($ses, $messageId);
            case 'Delivery':
                return $this->deliveryEvents($ses, $messageId);
            default:
                return [];
        }
    }

    /**
     * @param array<string,mixed> $ses
     *
     * @return DeliveryEvent[]
     */
    private function bounceEvents(array $ses, ?string $messageId): array
    {
        $bounce  = \is_array($ses['bounce'] ?? null) ? $ses['bounce'] : [];
        $type    = (string) ($bounce['bounceType'] ?? '');
        $subType = (string) ($bounce['bounceSubType'] ?? '');
        $detail  = trim($type . ($subType !== '' ? '/' . $subType : ''));

        return $this->events(
            $this->emails($bounce['bouncedRecipients'] ?? []),
            $messageId,
            DeliveryStatus::BOUNCED,
            // Only a permanent (hard) bounce is a terminal outcome; a transient one may still deliver.
            $type === 'Permanent',
            $detail !== '' ? $detail : null,
            $bounce['timestamp'] ?? null
        );
    }

    /**
     * @param array<string,mixed> $ses
     *
     * @return DeliveryEvent[]
     */
    private function complaintEvents(array $ses, ?string $messageId): array
    {
        $complaint = \is_array($ses['complaint'] ?? null) ? $ses['complaint'] : [];

        return $this->events(
            $this->emails($complaint['complainedRecipients'] ?? []),
            $messageId,
            DeliveryStatus::SPAM,
            true,
            isset($complaint['complaintFeedbackType']) ? (string) $complaint['complaintFeedbackType'] : null,
            $complaint['timestamp'] ?? null
        );
    }

    /**
     * @param array<string,mixed> $ses
     *
     * @return DeliveryEvent[]
     */
    private function deliveryEvents(array $ses, ?string $messageId): array
    {
        $delivery   = \is_array($ses['delivery'] ?? null) ? $ses['delivery'] : [];
        $recipients = \is_array($delivery['recipients'] ?? null) ? $delivery['recipients'] : [];

        return $this->events(
            array_values(array_filter(array_map('strval', $recipients), static fn (string $r): bool => $r !== '')),
            $messageId,
            DeliveryStatus::DELIVERED,
            true,
            null,
            $delivery['timestamp'] ?? null
        );
    }

    /**
     * SES bounce/complaint recipients are `[{emailAddress: ...}]`; delivery recipients are plain
     * strings, handled by the caller. Extracts the email addresses, dropping malformed entries.
     *
     * @param mixed $list
     *
     * @return string[]
     */
    private function emails($list): array
    {
        if (!\is_array($list)) {
            return [];
        }

        $emails = [];
        foreach ($list as $entry) {
            if (\is_array($entry) && !empty($entry['emailAddress'])) {
                $emails[] = (string) $entry['emailAddress'];
            }
        }

        return $emails;
    }

    /**
     * @param string[] $emails
     * @param mixed    $occurredAt
     *
     * @return DeliveryEvent[]
     */
    private function events(array $emails, ?string $messageId, string $status, bool $terminal, ?string $detail, $occurredAt): array
    {
        $events = [];
        foreach ($emails as $email) {
            $events[] = DeliveryEvent::fromArray([
                'message_id'  => $messageId,
                'tracking_id' => null,
                'recipient'   => $email,
                'status'      => $status,
                'terminal'    => $terminal,
                'detail'      => $detail,
                'occurred_at' => \is_string($occurredAt) ? $occurredAt : null,
            ]);
        }

        return $events;
    }

    /**
     * @param array<string,mixed> $ses
     */
    private function messageId(array $ses): ?string
    {
        $mail = \is_array($ses['mail'] ?? null) ? $ses['mail'] : [];

        return !empty($mail['messageId']) ? (string) $mail['messageId'] : null;
    }
}
