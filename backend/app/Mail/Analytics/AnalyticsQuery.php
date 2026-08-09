<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Analytics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Immutable, validated analytics filter. Timestamps are always held in UTC while the display
 * timezone is retained for bucket creation and response metadata.
 */
final class AnalyticsQuery
{
    private DateTimeImmutable $start;

    private DateTimeImmutable $end;

    private DateTimeZone $timezone;

    private string $bucket;

    private ?string $plugin;

    private ?string $connectionId;

    public function __construct(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeZone $timezone,
        string $bucket,
        ?string $plugin = null,
        ?string $connectionId = null
    ) {
        $utc                = new DateTimeZone('UTC');
        $this->start        = $start->setTimezone($utc);
        $this->end          = $end->setTimezone($utc);
        $this->timezone     = $timezone;
        $this->bucket       = $bucket;
        $this->plugin       = $plugin;
        $this->connectionId = $connectionId;
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function plugin(): ?string
    {
        return $this->plugin;
    }

    public function connectionId(): ?string
    {
        return $this->connectionId;
    }

    public function startSql(): string
    {
        return $this->start->format('Y-m-d H:i:s');
    }

    public function endSql(): string
    {
        return $this->end->format('Y-m-d H:i:s');
    }

    public function priorPeriod(): self
    {
        $seconds  = $this->end->getTimestamp() - $this->start->getTimestamp();
        $interval = new DateInterval('PT' . $seconds . 'S');

        return new self(
            $this->start->sub($interval),
            $this->start,
            $this->timezone,
            $this->bucket,
            $this->plugin,
            $this->connectionId
        );
    }
}
