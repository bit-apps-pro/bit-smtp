<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\FailureNotification;
use BitApps\SMTP\Mail\Notifications\FailureNotificationMessage;
use Throwable;

final class TelegramFailureNotificationChannel implements FailureNotificationChannelInterface
{
    public function key(): string
    {
        return 'telegram';
    }

    /**
     * @param array<string,mixed> $settings
     */
    public function send(FailureNotification $notification, array $settings): bool
    {
        $token  = self::setting($settings, 'bot_token');
        $chatId = self::setting($settings, 'chat_id');
        if (!self::isBotToken($token) || !self::isChatId($chatId)) {
            return false;
        }

        try {
            $response = wp_safe_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', [
                'body' => [
                    'chat_id'                  => $chatId,
                    'text'                     => FailureNotificationMessage::plainText($notification),
                    'disable_web_page_preview' => 'true',
                ],
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
        if ($status < 200 || $status >= 300) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (!\is_string($body)) {
            return false;
        }

        $payload = json_decode($body, true);

        return \is_array($payload) && ($payload['ok'] ?? false) === true;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private static function setting(array $settings, string $key): string
    {
        return isset($settings[$key]) && \is_scalar($settings[$key]) ? trim((string) $settings[$key]) : '';
    }

    private static function isBotToken(string $token): bool
    {
        return preg_match('/^\d{6,20}:[A-Za-z0-9_-]{20,}$/', $token) === 1;
    }

    private static function isChatId(string $chatId): bool
    {
        return preg_match('/^-?\d{1,20}$/', $chatId) === 1;
    }
}
