<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Message\MailMessage;

/**
 * Injects a delivery-webhook correlation token into an outgoing message on the channel a provider
 * round-trips (Postmark metadata, Brevo header), so an inbound webhook can be matched back to its
 * log row even when the provider's own message-id is unavailable.
 */
class TrackingIdStamper
{
    /**
     * The message-metadata key that carries our delivery-webhook correlation token. Declared by every
     * metadata-channel provider on send and read back by its webhook adapter on receive — one literal,
     * so the two sides can never drift. (Brevo correlates over a header, not this key.)
     */
    public const METADATA_KEY = 'bit_tracking_id';

    public function generate(): string
    {
        return wp_generate_uuid4();
    }

    /**
     * Return a copy of the message carrying $trackingId on the channel declared by $tracking
     * (['channel' => 'metadata'|'header', 'key' => string]); the message is returned unchanged when
     * the provider declares no tracking channel or the channel is unrecognized.
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
