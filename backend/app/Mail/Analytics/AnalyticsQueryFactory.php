<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use BitApps\SMTP\Settings\PluginSettings;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

final class AnalyticsQueryFactory
{
    private const DEFAULT_RANGE_DAYS = 30;

    private const MAX_RANGE_DAYS = 200;

    /**
     * @var array<int,string>
     */
    private const BUCKETS = ['hour', 'day', 'week'];

    private DateTimeImmutable $now;

    private DateTimeZone $timezone;

    private int $retentionDays;

    public function __construct(?DateTimeImmutable $now = null, ?DateTimeZone $timezone = null, ?int $retentionDays = null)
    {
        $this->timezone      = $timezone ?? $this->siteTimezone();
        $this->now           = ($now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
        $this->retentionDays = $this->boundedRetention($retentionDays);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return AnalyticsQuery|WP_Error
     */
    public function fromInput(array $input)
    {
        $end = \array_key_exists('end', $input)
            ? $this->parseIso($input['end'])
            : $this->now;
        if ($end === null) {
            return $this->rangeError('The end timestamp must be a complete ISO-8601 timestamp.');
        }

        $end   = $end->setTimezone($this->timezone);
        $start = \array_key_exists('start', $input)
            ? $this->parseIso($input['start'])
            : $end->sub(new DateInterval('P' . min(self::DEFAULT_RANGE_DAYS, $this->retentionDays) . 'D'));
        if ($start === null) {
            return $this->rangeError('The start timestamp must be a complete ISO-8601 timestamp.');
        }

        $start = $start->setTimezone($this->timezone);
        if ($start >= $end) {
            return $this->rangeError('The start timestamp must be before the end timestamp.');
        }

        $earliestAllowed = $end->sub(new DateInterval('P' . $this->retentionDays . 'D'));
        if ($start < $earliestAllowed) {
            return $this->rangeError('The requested range exceeds the configured retained-log window.');
        }

        $bucket = isset($input['bucket']) ? (string) $input['bucket'] : $this->defaultBucket($start, $end);
        if (!\in_array($bucket, self::BUCKETS, true)) {
            return new WP_Error('bit_smtp_invalid_analytics_input', 'The requested analytics bucket is unsupported.');
        }

        $plugin = $this->filter($input, 'plugin', '/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9][a-z0-9._-]*)?$/');
        if ($plugin instanceof WP_Error) {
            return $plugin;
        }
        $plugin = $plugin === '' ? null : $plugin;

        $connectionId = $this->filter($input, 'connection_id', '/^[A-Za-z0-9][A-Za-z0-9_-]{0,190}$/');
        if ($connectionId instanceof WP_Error) {
            return $connectionId;
        }
        $connectionId = $connectionId === '' ? null : $connectionId;

        return new AnalyticsQuery(
            $start,
            $end,
            $this->timezone,
            $bucket,
            $plugin,
            $connectionId,
            $this->now->sub(new DateInterval('P' . $this->retentionDays . 'D'))
        );
    }

    private function siteTimezone(): DateTimeZone
    {
        if (\function_exists('wp_timezone')) {
            return wp_timezone();
        }

        return new DateTimeZone('UTC');
    }

    private function boundedRetention(?int $configured): int
    {
        $days = $configured;
        if ($days === null && \function_exists('get_option')) {
            $stored = PluginSettings::getWithLegacyFallback('log_retention_days', 'log_retention', self::DEFAULT_RANGE_DAYS);
            $days   = is_numeric($stored) ? (int) $stored : self::DEFAULT_RANGE_DAYS;
        }

        return max(1, min(self::MAX_RANGE_DAYS, $days ?? self::DEFAULT_RANGE_DAYS));
    }

    /**
     * @param mixed $value
     */
    private function parseIso($value): ?DateTimeImmutable
    {
        if (!\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:sP',
            str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || (\is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }

    private function defaultBucket(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $days = (int) $start->diff($end)->format('%a');
        if ($days <= 2) {
            return 'hour';
        }

        return $days <= 31 ? 'day' : 'week';
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return string|WP_Error
     */
    private function filter(array $input, string $name, string $pattern)
    {
        if (!\array_key_exists($name, $input) || $input[$name] === null || $input[$name] === '') {
            return '';
        }

        $value = (string) $input[$name];
        if (!preg_match($pattern, $value)) {
            return new WP_Error('bit_smtp_invalid_analytics_input', "The {$name} filter is invalid.");
        }

        return $value;
    }

    private function rangeError(string $message): WP_Error
    {
        return new WP_Error('bit_smtp_invalid_analytics_range', $message);
    }
}
