<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Config\AlertChannelSettings;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use Throwable;

final class SlackFailureNotificationChannel implements FailureNotificationChannelInterface
{
    private const ERROR_CODE_PATTERN = '/^[a-z_]{1,40}$/';

    /**
     * The channel key, matched against each alert channel instance's `type`.
     */
    public function key(): string
    {
        return 'slack';
    }

    /**
     * POST the alert to a Slack incoming webhook; delivered only on a 2xx from a validated webhook URL.
     *
     * @param array<string,mixed> $settings
     */
    public function send(NotificationMessage $notification, array $settings): ChannelDeliveryResult
    {
        $url = AlertChannelSettings::scalarString($settings, 'webhook_url');
        if (!AlertChannelSettings::isSlackIncomingWebhookUrl($url)) {
            return ChannelDeliveryResult::failed(__('The Slack webhook URL is not valid.', 'bit-smtp'));
        }

        $body = wp_json_encode(['text' => $notification->chatText()]);
        if (!\is_string($body)) {
            return ChannelFailureReason::unencodable();
        }

        try {
            $response = wp_safe_remote_post($url, [
                'headers'     => ['Content-Type' => 'application/json'],
                'body'        => $body,
                'timeout'     => 5,
                'redirection' => 0,
            ]);
        } catch (Throwable) {
            return ChannelFailureReason::requestFailed();
        }
        if (is_wp_error($response)) {
            return ChannelFailureReason::transport($response, [$url]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return $status >= 200 && $status < 300 ? ChannelDeliveryResult::delivered() : self::rejected($status, $response);
    }

    /**
     * Slack's rejection, naming its plain-text error code (e.g. no_service) only when the body is one.
     *
     * @param array<string,mixed> $response
     */
    private static function rejected(int $status, array $response): ChannelDeliveryResult
    {
        $code = trim((string) wp_remote_retrieve_body($response));

        if (preg_match(self::ERROR_CODE_PATTERN, $code) !== 1) {
            // translators: %d: HTTP status code
            return ChannelDeliveryResult::failed(\sprintf(__('Slack rejected the request (HTTP %d).', 'bit-smtp'), $status));
        }

        // translators: 1: HTTP status code, 2: Slack's error code such as no_service
        return ChannelDeliveryResult::failed(\sprintf(__('Slack rejected the request (HTTP %1$d: %2$s).', 'bit-smtp'), $status, $code));
    }
}
