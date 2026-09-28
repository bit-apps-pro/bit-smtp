<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use Throwable;

class WebhookFailureNotificationChannel implements FailureNotificationChannelInterface
{
    /**
     * @var callable():int
     */
    private $clock;

    /**
     * Use the given clock for the signed timestamp (defaults to time()).
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * The channel key, matched against each alert channel instance's `type`.
     */
    public function key(): string
    {
        return 'webhook';
    }

    /**
     * POST the alert as signed JSON; delivered only on a 2xx.
     *
     * @param array<string,mixed> $settings
     */
    public function send(NotificationMessage $notification, array $settings): ChannelDeliveryResult
    {
        $url    = isset($settings['url']) ? trim((string) $settings['url']) : '';
        $secret = isset($settings['signing_secret']) ? trim((string) $settings['signing_secret']) : '';
        if ($url === '' || $secret === '') {
            return ChannelDeliveryResult::failed(__('The webhook URL or signing secret is missing.', 'bit-smtp'));
        }

        $body = wp_json_encode($notification->toArray());
        if (!\is_string($body)) {
            return ChannelFailureReason::unencodable();
        }

        $timestamp = (string) ($this->clock)();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        try {
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
        } catch (Throwable) {
            return ChannelFailureReason::requestFailed();
        }
        if (is_wp_error($response)) {
            return ChannelFailureReason::transport($response, [$url, $secret]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return $status >= 200 && $status < 300 ? ChannelDeliveryResult::delivered() : ChannelFailureReason::httpStatus($status);
    }
}
