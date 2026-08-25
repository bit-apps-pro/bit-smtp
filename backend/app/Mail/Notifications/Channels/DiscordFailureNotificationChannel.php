<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use Throwable;

final class DiscordFailureNotificationChannel implements FailureNotificationChannelInterface
{
    /**
     * Discord rejects a message whose `content` exceeds 2000 characters; the alert text is clamped to
     * this ceiling before it is sent.
     */
    private const MAX_CONTENT_LENGTH = 2000;

    /**
     * The channel key, doubling as its `features['alerts']` settings key.
     */
    public function key(): string
    {
        return 'discord';
    }

    /**
     * POST the alert as a Discord webhook message; true only on a 2xx from a validated webhook URL.
     *
     * @param array<string,mixed> $settings
     */
    public function send(NotificationMessage $notification, array $settings): bool
    {
        $url = self::setting($settings, 'webhook_url');
        if (!self::isWebhookUrl($url)) {
            return false;
        }

        $body = wp_json_encode(['content' => self::truncate($notification->chatText())]);
        if (!\is_string($body)) {
            return false;
        }

        try {
            $response = wp_safe_remote_post($url, [
                'headers'     => ['Content-Type' => 'application/json'],
                'body'        => $body,
                'timeout'     => 5,
                'redirection' => 0,
            ]);
        } catch (Throwable) {
            return false;
        }
        if (is_wp_error($response)) {
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);

        return $status >= 200 && $status < 300;
    }

    /**
     * Read a scalar setting as a trimmed string, defaulting to '' for missing or non-scalar values.
     *
     * @param array<string,mixed> $settings
     */
    private static function setting(array $settings, string $key): string
    {
        return isset($settings[$key]) && \is_scalar($settings[$key]) ? trim((string) $settings[$key]) : '';
    }

    /**
     * Clamp the chat text to Discord's 2000-character content ceiling, counting multibyte characters.
     */
    private static function truncate(string $text): string
    {
        return mb_substr($text, 0, self::MAX_CONTENT_LENGTH);
    }

    /**
     * True only for an exact Discord webhook URL: https, a discord(app).com host, an `/api/webhooks/`
     * path carrying the id/token, and no port, credentials, query, or fragment.
     */
    private static function isWebhookUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && \in_array($parts['host'] ?? '', ['discord.com', 'discordapp.com'], true)
            && !isset($parts['port'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && isset($parts['path'])
            && str_starts_with($parts['path'], '/api/webhooks/')
            && \strlen($parts['path']) > \strlen('/api/webhooks/');
    }
}
