<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\Mail\Dispatch\RetryQueue;

\defined('ABSPATH') || exit();

/**
 * `wp bit-smtp retry flush`: discard every queued retry (destructive, abandons those deferred sends)
 * and report the count removed. Mirrors the REST flush action; admin-invoked, so no extra guard.
 */
final class RetryFlushCommand
{
    private RetryQueue $queue;

    public function __construct(RetryQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Clear the retry queue and report the number of rows removed, or a terminal error on DB failure
     * (distinguished from a legitimate zero-row clear).
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function run(array $args, array $assocArgs, CliReporter $reporter): void
    {
        $removed = $this->queue->clear();
        if ($removed === false) {
            $reporter->error('Failed to clear the retry queue.');

            return;
        }

        $reporter->success(\sprintf(
            '%d queued %s discarded.',
            $removed,
            $removed === 1 ? 'retry' : 'retries'
        ));
    }
}
