<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Message\MailMessage;

/**
 * Inject delivery-webhook correlation token into outgoing message for webhook matching.
 */
class TrackingIdStamper
{
    /**
     * Metadata key for delivery-webhook correlation token (stable across send/receive).
     */
    public const METADATA_KEY = 'bit_tracking_id';

    public function generate(): string
    {
        return wp_generate_uuid4();
    }

    /**
     * Return message copy with tracking stamped on declared channel; unchanged if channel absent/invalid.
     *
     * @param array{channel?: string, key?: string} $tracking
     */
    public function stamp(MailMessage $message, array $tracking, string $trackingId): MailMessage
    {
        $channel = $tracking['channel'] ?? '';
        $key     = (string) ($tracking['key'] ?? '');
        if ($key === '') {
            return $message;
        }

        $data = $message->toArray();

        if ($channel === 'metadata') {
            $data['metadata'] = array_merge($data['metadata'] ?? [], [$key => $trackingId]);
        } elseif ($channel === 'header') {
            $data['headers'] = array_merge($data['headers'] ?? [], [$key => $trackingId]);
        } else {
            return $message;
        }

        return MailMessage::fromArray($data);
    }
}
