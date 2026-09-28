<?php

namespace BitApps\SMTP\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Config\AlertChannelSettings;
use BitApps\SMTP\Mail\Notifications\ChannelDeliveryResult;
use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use Throwable;

final class TelegramFailureNotificationChannel implements FailureNotificationChannelInterface
{
    private const MAX_ERROR_LENGTH = 120;

    /**
     * The channel key, matched against each alert channel instance's `type`.
     */
    public function key(): string
    {
        return 'telegram';
    }

    /**
     * Send the alert through the Bot API's sendMessage; delivered only when Telegram answers `ok: true`.
     *
     * @param array<string,mixed> $settings
     */
    public function send(NotificationMessage $notification, array $settings): ChannelDeliveryResult
    {
        $token  = AlertChannelSettings::scalarString($settings, 'bot_token');
        $chatId = AlertChannelSettings::scalarString($settings, 'chat_id');
        if (!AlertChannelSettings::isTelegramBotToken($token) || !AlertChannelSettings::isTelegramChatId($chatId)) {
            return ChannelDeliveryResult::failed(__('The Telegram bot token or chat ID is not valid.', 'bit-smtp'));
        }

        try {
            $response = wp_safe_remote_post('https://api.telegram.org/bot' . $token . '/sendMessage', [
                'body' => [
                    'chat_id'                  => $chatId,
                    'text'                     => $notification->chatText(),
                    'disable_web_page_preview' => 'true',
                ],
                'timeout'     => 5,
                'redirection' => 0,
            ]);
        } catch (Throwable) {
            return ChannelFailureReason::requestFailed();
        }
        if (is_wp_error($response)) {
            return ChannelFailureReason::transport($response, [$token, $chatId]);
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status >= 200 && $status < 300 && \is_array($payload) && ($payload['ok'] ?? false) === true) {
            return ChannelDeliveryResult::delivered();
        }

        return self::rejected($status, \is_array($payload) ? $payload : [], [$token, $chatId]);
    }

    /**
     * Telegram's rejection, naming its `description` (redacted, truncated, bot tokens removed) when present.
     *
     * @param array<string,mixed> $payload
     * @param string[]            $secrets the bot token and chat id, removed from the description
     */
    private static function rejected(int $status, array $payload, array $secrets): ChannelDeliveryResult
    {
        $description = \is_string($payload['description'] ?? null)
            ? ChannelFailureReason::redact($payload['description'], self::MAX_ERROR_LENGTH, $secrets)
            : '';

        if ($description === '') {
            // translators: %d: HTTP status code
            return ChannelDeliveryResult::failed(\sprintf(__('Telegram rejected the request (HTTP %d).', 'bit-smtp'), $status));
        }

        // translators: %s: Telegram's error description such as Unauthorized
        return ChannelDeliveryResult::failed(\sprintf(__('Telegram rejected the request: %s.', 'bit-smtp'), rtrim($description, '.')));
    }
}
