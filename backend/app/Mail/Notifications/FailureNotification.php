<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Mail\Notifications\Contracts\NotificationMessage;
use InvalidArgumentException;
use LogicException;

/**
 * Send-failure incident alert across three lifecycle states; never carries credentials.
 */
final class FailureNotification implements NotificationMessage
{
    public const EVENT_OPENED = 'send_failure.opened';

    public const EVENT_REMINDER = 'send_failure.reminder';

    public const EVENT_RESOLVED = 'send_failure.resolved';

    private const SECONDS_PER_MINUTE = 60;

    private const SECONDS_PER_HOUR = 3600;

    private const SECONDS_PER_DAY = 86400;

    /**
     * Built only through the named constructors.
     */
    private function __construct(
        private string $event,
        private string $siteName,
        private string $siteUrl,
        private FailureIncident $incident,
        private bool $test
    ) {
    }

    /**
     * True for one of the three incident alert events.
     */
    public static function isEvent(string $event): bool
    {
        return \in_array($event, [self::EVENT_OPENED, self::EVENT_REMINDER, self::EVENT_RESOLVED], true);
    }

    /**
     * The alert for an incident in the given lifecycle state.
     *
     * @throws InvalidArgumentException for an unknown event
     */
    public static function fromIncident(string $event, FailureIncident $incident): self
    {
        if (!self::isEvent($event)) {
            throw new InvalidArgumentException('Unknown send-failure alert event.');
        }

        return new self($event, (string) get_bloginfo('name'), home_url('/'), $incident, false);
    }

    /**
     * A fixed sample "opened" alert for the admin channel test.
     */
    public static function forTest(): self
    {
        $incident = FailureIncident::fromArray([
            'id'           => 'test',
            'started_at'   => 946684800,
            'failed_count' => 1,
            'error'        => ['code' => 'test_notification', 'message' => 'This is a test failure notification.'],
            'mail'         => ['subject' => 'Bit SMTP notification test', 'recipients' => ['ops@example.test']],
        ]) ?? throw new LogicException('Invalid sample incident.');

        return new self(self::EVENT_OPENED, 'Example Site', 'https://example.test/', $incident, true);
    }

    /**
     * True for the admin channel test.
     */
    public function isTest(): bool
    {
        return $this->test;
    }

    /**
     * The one-line summary of this lifecycle state.
     */
    public function headline(): string
    {
        switch ($this->event) {
            case self::EVENT_REMINDER:
                return \sprintf('Still failing — %s since %s', $this->failedPhrase(), (string) wp_date('H:i T', $this->incident->startedAt()));

            case self::EVENT_RESOLVED:
                return \sprintf('Resolved — %s over %s', $this->failedPhrase(), self::duration($this->durationSeconds()));

            default:
                return 'Sending is failing';
        }
    }

    /**
     * The email subject naming the site and the lifecycle state.
     */
    public function emailSubject(): string
    {
        return \sprintf('[Bit SMTP] %s: %s', $this->siteName, $this->headline());
    }

    /**
     * The bounded plain-text body shared by email and chat channels.
     */
    public function emailBody(): string
    {
        return FailureNotificationMessage::plainText($this);
    }

    /**
     * The bounded plain-text chat message (identical to the email body).
     */
    public function chatText(): string
    {
        return FailureNotificationMessage::plainText($this);
    }

    /**
     * The machine event name, e.g. `send_failure.opened`.
     */
    public function eventType(): string
    {
        return $this->event;
    }

    /**
     * The webhook payload: the latest failure's fields plus an `incident` block with counts/times.
     */
    public function toArray(): array
    {
        $resolvedAt = $this->incident->resolvedAt();

        return [
            'event'     => $this->event,
            'failed_at' => self::atom($this->incident->lastFailedAt()),
            'site'      => [
                'name' => $this->siteName,
                'url'  => $this->siteUrl,
            ],
            'error'      => $this->incident->error(),
            'mail'       => $this->incident->mail(),
            'connection' => $this->incident->connection(),
            'attempts'   => $this->incident->attempts(),
            'incident'   => [
                'id'               => $this->incident->id(),
                'started_at'       => self::atom($this->incident->startedAt()),
                'last_failed_at'   => self::atom($this->incident->lastFailedAt()),
                'resolved_at'      => $resolvedAt === null ? null : self::atom($resolvedAt),
                'failed_count'     => $this->incident->failedCount(),
                'duration_seconds' => $this->durationSeconds(),
            ],
        ];
    }

    /**
     * "1 email failed" / "N emails failed" for the incident's running count.
     */
    private function failedPhrase(): string
    {
        $count = $this->incident->failedCount();

        return \sprintf('%d %s failed', $count, $count === 1 ? 'email' : 'emails');
    }

    /**
     * Seconds from the first failure to resolution, or to the latest failure while still open.
     */
    private function durationSeconds(): int
    {
        return max(0, ($this->incident->resolvedAt() ?? $this->incident->lastFailedAt()) - $this->incident->startedAt());
    }

    /**
     * A compact human duration using the two largest units, e.g. "1h 13m", "2d 3h", "45s".
     */
    private static function duration(int $seconds): string
    {
        if ($seconds < self::SECONDS_PER_MINUTE) {
            return $seconds . 's';
        }

        $parts = array_filter([
            'd' => intdiv($seconds, self::SECONDS_PER_DAY),
            'h' => intdiv($seconds % self::SECONDS_PER_DAY, self::SECONDS_PER_HOUR),
            'm' => intdiv($seconds % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE),
        ]);
        $labels = [];
        foreach (\array_slice($parts, 0, 2, true) as $unit => $value) {
            $labels[] = $value . $unit;
        }

        return implode(' ', $labels);
    }

    /**
     * An ISO-8601 UTC timestamp.
     */
    private static function atom(int $timestamp): string
    {
        return gmdate(DATE_ATOM, $timestamp);
    }
}
