<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationMessage;
use Throwable;

final class SlackFailureNotificationChannel implements FailureNotificationChannelInterface
{
    public function key(): string
    {
        return 'slack';
    }

    /**
     * @param array<string,mixed> $settings
     */
    public function send(FailureNotification $notification, array $settings): bool
    {
        $url = self::setting($settings, 'webhook_url');
        if (!self::isIncomingWebhookUrl($url)) {
            return false;
        }

        $body = wp_json_encode(['text' => FailureNotificationMessage::plainText($notification)]);
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
     * @param array<string,mixed> $settings
     */
    private static function setting(array $settings, string $key): string
    {
        return isset($settings[$key]) && \is_scalar($settings[$key]) ? trim((string) $settings[$key]) : '';
    }

    private static function isIncomingWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '')   === 'hooks.slack.com'
            && !isset($parts['port'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && isset($parts['path'])
            && str_starts_with($parts['path'], '/services/')
            && \strlen($parts['path']) > \strlen('/services/');
    }
}
