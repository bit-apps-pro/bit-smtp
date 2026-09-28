<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

/**
 * Failure-alert channel type contract: settings keys, validation, delivery requirements.
 */
final class AlertChannelSettings
{
    public const SETTING_KEYS = [
        'email'    => ['recipients'],
        'webhook'  => ['url', 'signing_secret'],
        'slack'    => ['webhook_url'],
        'telegram' => ['bot_token', 'chat_id'],
        'discord'  => ['webhook_url'],
    ];

    public const MAX_RECIPIENTS = 20;

    private const MAX_WEBHOOK_URL_LENGTH = 2048;

    private const DISCORD_HOSTS = ['discord.com', 'discordapp.com'];

    private const TELEGRAM_BOT_TOKEN_PATTERN = '/^\d{6,20}:[A-Za-z0-9_-]{20,}$/';

    private const TELEGRAM_CHAT_ID_PATTERN = '/^-?\d{1,20}$/';

    /**
     * True when the given value names a known channel type.
     *
     * @param mixed $type
     */
    public static function isType($type): bool
    {
        return \is_string($type) && isset(self::SETTING_KEYS[$type]);
    }

    /**
     * The translated display label of a channel type, used as an instance's fallback name.
     */
    public static function typeLabel(string $type): string
    {
        switch ($type) {
            case 'email':
                return __('Email', 'bit-smtp');
            case 'webhook':
                return __('Webhook', 'bit-smtp');
            case 'slack':
                return __('Slack', 'bit-smtp');
            case 'telegram':
                return __('Telegram', 'bit-smtp');
            case 'discord':
                return __('Discord', 'bit-smtp');
        }

        return $type;
    }

    /**
     * Validate a known type's settings, keeping only its own keys and blanking any invalid value.
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    public static function sanitize(string $type, array $settings): array
    {
        switch ($type) {
            case 'email':
                return ['recipients' => self::recipients($settings['recipients'] ?? [])];
            case 'webhook':
                return [
                    'url'            => self::httpUrl(self::scalarString($settings, 'url')),
                    'signing_secret' => self::matching(
                        self::scalarString($settings, 'signing_secret'),
                        '/^whsec_[A-Za-z0-9_-]{32,128}$/'
                    ),
                ];
            case 'slack':
                $url = self::scalarString($settings, 'webhook_url');

                return ['webhook_url' => self::isSlackIncomingWebhookUrl($url) ? $url : ''];
            case 'telegram':
                return [
                    'bot_token' => self::matching(self::scalarString($settings, 'bot_token'), self::TELEGRAM_BOT_TOKEN_PATTERN),
                    'chat_id'   => self::matching(self::scalarString($settings, 'chat_id'), self::TELEGRAM_CHAT_ID_PATTERN),
                ];
            case 'discord':
                $url = self::scalarString($settings, 'webhook_url');

                return ['webhook_url' => self::isDiscordWebhookUrl($url) ? $url : ''];
        }

        return [];
    }

    /**
     * Describe the first required field a sanitized instance still lacks, or null when it can deliver.
     *
     * @param array<string,mixed> $settings sanitized settings for $type
     */
    public static function missingRequirement(string $type, array $settings): ?string
    {
        $key = self::firstMissingKey($type, $settings);

        return $key === null ? null : self::requirementMessage("{$type}.{$key}");
    }

    /**
     * Name the channel and the first required field it still lacks, or null when it can deliver.
     *
     * @param array<string,mixed> $settings sanitized settings for $type
     */
    public static function incompleteChannelMessage(string $type, string $name, array $settings): ?string
    {
        $key = self::firstMissingKey($type, $settings);

        return $key === null ? null : self::namedRequirementMessage("{$type}.{$key}", $name);
    }

    /**
     * Read a scalar setting as a trimmed string, defaulting to '' for missing or non-scalar values.
     *
     * @param array<string,mixed> $settings
     */
    public static function scalarString(array $settings, string $key): string
    {
        return isset($settings[$key]) && \is_scalar($settings[$key]) ? trim((string) $settings[$key]) : '';
    }

    /**
     * True for a Telegram Bot API token shape (`<bot id>:<secret>`).
     */
    public static function isTelegramBotToken(string $token): bool
    {
        return preg_match(self::TELEGRAM_BOT_TOKEN_PATTERN, $token) === 1;
    }

    /**
     * True for a numeric (optionally negative) Telegram chat id.
     */
    public static function isTelegramChatId(string $chatId): bool
    {
        return preg_match(self::TELEGRAM_CHAT_ID_PATTERN, $chatId) === 1;
    }

    /**
     * Accept only an exact Slack incoming-webhook URL on hooks.slack.com under `/services/`.
     */
    public static function isSlackIncomingWebhookUrl(string $url): bool
    {
        return self::isStrictHttpsUrl($url, ['hooks.slack.com'], '/services/');
    }

    /**
     * Accept only an exact Discord webhook URL on a discord(app).com host under `/api/webhooks/`.
     */
    public static function isDiscordWebhookUrl(string $url): bool
    {
        return self::isStrictHttpsUrl($url, self::DISCORD_HOSTS, '/api/webhooks/');
    }

    /**
     * Validate strict HTTPS URL on allowed host with path prefix; no port, creds, query, or fragment.
     *
     * @param string[] $hosts
     */
    public static function isStrictHttpsUrl(string $url, array $hosts, string $pathPrefix): bool
    {
        $parts = wp_parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && \in_array($parts['host'] ?? '', $hosts, true)
            && !isset($parts['port'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && isset($parts['path'])
            && str_starts_with($parts['path'], $pathPrefix)
            && \strlen($parts['path']) > \strlen($pathPrefix);
    }

    /**
     * The first required settings key of $type that is empty, in check order, or null when none is.
     *
     * @param array<string,mixed> $settings
     */
    private static function firstMissingKey(string $type, array $settings): ?string
    {
        foreach (self::SETTING_KEYS[$type] ?? [] as $key) {
            if (empty($settings[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The test-send prompt for a missing `type.key` requirement.
     */
    private static function requirementMessage(string $requirement): string
    {
        switch ($requirement) {
            case 'email.recipients':
                return __('Add at least one valid recipient email first.', 'bit-smtp');
            case 'webhook.url':
                return __('Add a valid webhook URL first.', 'bit-smtp');
            case 'webhook.signing_secret':
                return __('Add a webhook signing secret first.', 'bit-smtp');
            case 'slack.webhook_url':
                return __('Add a Slack webhook URL first.', 'bit-smtp');
            case 'telegram.bot_token':
                return __('Add a Telegram bot token first.', 'bit-smtp');
            case 'telegram.chat_id':
                return __('Add a Telegram chat ID first.', 'bit-smtp');
            default:
                return __('Add a Discord webhook URL first.', 'bit-smtp');
        }
    }

    /**
     * The save-time error naming the channel for a missing `type.key` requirement.
     */
    private static function namedRequirementMessage(string $requirement, string $name): string
    {
        switch ($requirement) {
            case 'email.recipients':
                // translators: %s: notification channel name
                $format = __('Email channel “%s” needs at least one valid recipient.', 'bit-smtp');

                break;
            case 'webhook.url':
                // translators: %s: notification channel name
                $format = __('Webhook channel “%s” needs a valid webhook URL.', 'bit-smtp');

                break;
            case 'webhook.signing_secret':
                // translators: %s: notification channel name
                $format = __('Webhook channel “%s” needs a signing secret.', 'bit-smtp');

                break;
            case 'slack.webhook_url':
                // translators: %s: notification channel name
                $format = __('Slack channel “%s” needs a webhook URL.', 'bit-smtp');

                break;
            case 'telegram.bot_token':
                // translators: %s: notification channel name
                $format = __('Telegram channel “%s” needs a bot token.', 'bit-smtp');

                break;
            case 'telegram.chat_id':
                // translators: %s: notification channel name
                $format = __('Telegram channel “%s” needs a chat ID.', 'bit-smtp');

                break;
            default:
                // translators: %s: notification channel name
                $format = __('Discord channel “%s” needs a webhook URL.', 'bit-smtp');
        }

        return \sprintf($format, $name);
    }

    /**
     * Keep unique, valid email addresses, capped at MAX_RECIPIENTS.
     *
     * @param mixed $raw
     *
     * @return string[]
     */
    private static function recipients($raw): array
    {
        $recipients = [];
        foreach ((array) $raw as $recipient) {
            if (!\is_scalar($recipient)) {
                continue;
            }

            $recipient = trim((string) $recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = $recipient;
            }
        }

        return \array_slice(array_values(array_unique($recipients)), 0, self::MAX_RECIPIENTS);
    }

    /**
     * Keep an absolute http(s) URL within the length cap; anything else becomes ''.
     */
    private static function httpUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        return \strlen($url) <= self::MAX_WEBHOOK_URL_LENGTH
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && \in_array($scheme, ['http', 'https'], true)
            ? $url
            : '';
    }

    /**
     * Keep the value only when it fully matches the pattern; anything else becomes ''.
     */
    private static function matching(string $value, string $pattern): string
    {
        return $value !== '' && preg_match($pattern, $value) === 1 ? $value : '';
    }
}
