<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Mail\Notifications\Contracts\FailureNotificationChannelInterface;
use InvalidArgumentException;

final class FailureNotificationChannelRegistry
{
    /**
     * @var array<string,FailureNotificationChannelInterface>
     */
    private array $channels = [];

    /**
     * @param FailureNotificationChannelInterface[] $channels
     */
    public function __construct(array $channels)
    {
        foreach ($channels as $channel) {
            $key = $channel->key();
            if (isset($this->channels[$key])) {
                throw new InvalidArgumentException('Duplicate failure notification channel key.');
            }

            $this->channels[$key] = $channel;
        }
    }

    public function get(string $key): ?FailureNotificationChannelInterface
    {
        return $this->channels[$key] ?? null;
    }

    /**
     * @return array<string,FailureNotificationChannelInterface>
     */
    public function all(): array
    {
        return $this->channels;
    }
}
