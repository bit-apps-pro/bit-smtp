<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;

class WebhookFailureNotificationChannel implements FailureNotificationChannelInterface
{
    /**
     * @var callable():int
     */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    public function key(): string
    {
        return 'webhook';
    }

    public function send(NotificationMessage $notification, array $settings): bool
    {
        $url    = isset($settings['url']) ? trim((string) $settings['url']) : '';
        $secret = isset($settings['signing_secret']) ? trim((string) $settings['signing_secret']) : '';
        if ($url === '' || $secret === '') {
            return false;
        }

        $body = wp_json_encode($notification->toArray());
        if (!\is_string($body)) {
            return false;
        }

        $timestamp = (string) ($this->clock)();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $response = wp_safe_remote_post($url, [
            'headers' => [
                'Content-Type'         => 'application/json',
                'X-Bit-SMTP-Event'     => $notification->eventType(),
                'X-Bit-SMTP-Timestamp' => $timestamp,
                'X-Bit-SMTP-Signature' => 'v1=' . $signature,
            ],
            'body'        => $body,
            'timeout'     => 5,
            'redirection' => 0,
        ]);
        if (is_wp_error($response)) {
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);

        return $status >= 200 && $status < 300;
    }
}
