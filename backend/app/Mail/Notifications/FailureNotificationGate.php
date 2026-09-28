<?php

namespace BitApps\SMTP\Mail\Notifications;

use BitApps\SMTP\Config;
use BitApps\SMTP\Settings\OptionSwap;

/**
 * Atomic storage via option row with compare-and-swap; first writer wins.
 */
class FailureNotificationGate
{
    public const OPTION = 'failure_notification_active';

    /**
     * Guard the option row named by the given unprefixed key.
     */
    public function __construct(private string $optionKey = self::OPTION)
    {
    }

    /**
     * Create the row with the given state; true only for the one request whose insert won the election.
     */
    public function acquire(string $state): bool
    {
        return OptionSwap::create($this->optionName(), $state, OptionSwap::NO_AUTOLOAD);
    }

    /**
     * The row's stored state read straight from the table, or null when there is no (or an empty) row.
     */
    public function read(): ?string
    {
        $state = OptionSwap::read($this->optionName());

        return $state === '' ? null : $state;
    }

    /**
     * Swap the stored state only if it still equals $expected; false when another request won.
     */
    public function replace(string $expected, string $next): bool
    {
        return OptionSwap::replace($this->optionName(), $expected, $next);
    }

    /**
     * Remove the row, returning the state this call removed, or null if none was removed.
     */
    public function reset(): ?string
    {
        $state = $this->read();

        return $state !== null && $this->delete($state) ? $state : null;
    }

    /**
     * Delete the row only if its value still equals $expected; false when another request changed it.
     */
    public function delete(string $expected): bool
    {
        return OptionSwap::delete($this->optionName(), $expected);
    }

    /**
     * The prefixed option name holding the guarded row.
     */
    private function optionName(): string
    {
        return Config::withPrefix($this->optionKey);
    }
}
