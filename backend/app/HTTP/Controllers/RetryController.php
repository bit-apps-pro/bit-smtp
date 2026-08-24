<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Mail\Dispatch\RetryQueue;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Settings\PluginSettings;

/**
 * Read/flush surface for the mail retry queue, backing the Reliability settings panel.
 */
final class RetryController
{
    private ?RetryQueue $queue;

    public function __construct(?RetryQueue $queue = null)
    {
        $this->queue = $queue;
    }

    /**
     * Queue snapshot for the admin panel: whether retry is enabled, total depth, and the pending rows
     * as metadata only (never the encrypted message payload).
     */
    public function status(Request $request): Response
    {
        return Response::success([
            'enabled' => (bool) PluginSettings::make()->get('retry_enabled', false),
            'depth'   => $this->queue()->depth(),
            'items'   => $this->queue()->pending(),
        ]);
    }

    /**
     * Discard every queued retry (destructive: abandons those deferred sends); returns the count removed.
     */
    public function flush(Request $request): Response
    {
        $removed = $this->queue()->clear();
        if ($removed === false) {
            return Response::error([])->message(__('Failed to clear the retry queue', 'bit-smtp'));
        }

        // translators: %d is the number of queued retries discarded.
        $message = \sprintf(_n('%d queued retry discarded', '%d queued retries discarded', $removed, 'bit-smtp'), $removed);

        return Response::success(['deleted' => $removed])->message($message);
    }

    /**
     * Lazily resolve the shared RetryQueue singleton, honoring a constructor-injected fake for tests.
     */
    private function queue(): RetryQueue
    {
        return $this->queue ??= Plugin::instance()->app()->make(RetryQueue::class);
    }
}
