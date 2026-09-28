<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\Mail\Dispatch\RetryQueue;

\defined('ABSPATH') || exit();

/**
 * Discard all queued retries (destructive; mirrors REST flush action).
 */
final class RetryFlushCommand
{
    private RetryQueue $queue;

    public function __construct(RetryQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Clear retry queue and report rows removed (error on DB failure, distinct from zero-row clear).
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
