<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\Mail\Dispatch\RetryQueue;

\defined('ABSPATH') || exit();

/**
 * `wp bit-smtp retry status`: print the retry queue depth and a metadata-only table of the pending
 * rows (never the encrypted payload). Reuses RetryQueue::depth()/pending() as the REST status does.
 */
final class RetryStatusCommand
{
    /**
     * Pending-row columns surfaced to the operator; all are safe metadata from RetryQueue::pending().
     */
    private const FIELDS = [
        'id',
        'attempts',
        'max_attempts',
        'failure_class',
        'connection_chain',
        'next_attempt_at',
        'created_at',
        'locked',
    ];

    private RetryQueue $queue;

    public function __construct(RetryQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Report the queue depth, then render the pending rows as a table (or a notice when empty).
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function run(array $args, array $assocArgs, CliReporter $reporter): void
    {
        $reporter->line(\sprintf('Retry queue depth: %d', $this->queue->depth()));

        $pending = $this->queue->pending();
        if ($pending === []) {
            $reporter->line('No pending retries.');

            return;
        }

        $reporter->renderItems('table', $pending, self::FIELDS);
    }
}
