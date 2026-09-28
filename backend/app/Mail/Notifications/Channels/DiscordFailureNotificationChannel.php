<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Config\AlertChannelSettings;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use Throwable;

final class DiscordFailureNotificationChannel implements FailureNotificationChannelInterface
{
    /**
     * Discord message content ceiling; clamp alert text before sending.
     */
    private const MAX_CONTENT_LENGTH = 2000;

    private const MAX_ERROR_LENGTH = 120;

    /**
     * The channel key, matched against each alert channel instance's `type`.
     */
    public function key(): string
    {
        return 'discord';
    }

    /**
     * POST the alert as a Discord webhook message; delivered only on a 2xx from a validated webhook URL.
     *
     * @param array<string,mixed> $settings
     */
    public function send(NotificationMessage $notification, array $settings): ChannelDeliveryResult
    {
        $url = AlertChannelSettings::scalarString($settings, 'webhook_url');
        if (!AlertChannelSettings::isDiscordWebhookUrl($url)) {
            return ChannelDeliveryResult::failed(__('The Discord webhook URL is not valid.', 'bit-smtp'));
        }

        $body = wp_json_encode(['content' => self::truncate($notification->chatText())]);
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

        return $status >= 200 && $status < 300 ? ChannelDeliveryResult::delivered() : self::rejected($status, $response, $url);
    }

    /**
     * Build rejection result from Discord's JSON response message (redacted).
     *
     * @param array<string,mixed> $response
     */
    private static function rejected(int $status, array $response, string $url): ChannelDeliveryResult
    {
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $message = \is_array($payload) && \is_string($payload['message'] ?? null)
            ? ChannelFailureReason::redact($payload['message'], self::MAX_ERROR_LENGTH, [$url])
            : '';

        if ($message === '') {
            // translators: %d: HTTP status code
            return ChannelDeliveryResult::failed(\sprintf(__('Discord rejected the request (HTTP %d).', 'bit-smtp'), $status));
        }

        // translators: 1: HTTP status code, 2: Discord's error message
        return ChannelDeliveryResult::failed(\sprintf(__('Discord rejected the request (HTTP %1$d: %2$s).', 'bit-smtp'), $status, $message));
    }

    /**
     * Clamp the chat text to Discord's 2000-character content ceiling, counting multibyte characters.
     */
    private static function truncate(string $text): string
    {
        return mb_substr($text, 0, self::MAX_CONTENT_LENGTH);
    }
}
