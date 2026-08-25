<?php

namespace BitApps\SMTP\Privacy;

use BitApps\SMTP\Model\LogEngagementEvent;

\defined('ABSPATH') || exit();

/**
 * WordPress personal-data eraser for open/click engagement events. Deletes the engagement rows of
 * logs the requested address was a recipient of, paginated and fail-closed (mirrors
 * LogService::delete): a delete failure is reported as retained, never silently swallowed. The log
 * rows themselves are intentionally left intact — only engagement events are in scope here.
 */
final class EngagementDataEraser
{
    private RecipientLogLocator $locator;

    /**
     * @param null|RecipientLogLocator $locator recipient-to-log resolver; defaults to the real one
     */
    public function __construct(?RecipientLogLocator $locator = null)
    {
        $this->locator = $locator ?? new RecipientLogLocator();
    }

    /**
     * Erase one page of engagement data for $email, matching WP's eraser callback signature.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: array<int,string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $located  = $this->locator->locate($email, $page);
        $removed  = false;
        $retained = false;
        $messages = [];

        if ($located['ids'] !== []) {
            // delete() returns the affected-row count, or false on a DB error. Fail closed: on error
            // report the data as retained (never claim erased) so the request is not falsely marked
            // complete for this subject; otherwise report whether any rows were actually removed.
            $deleted = LogEngagementEvent::where('log_id', $located['ids'])->delete();
            if ($deleted === false) {
                $retained   = true;
                $messages[] = __('Some open/click tracking data could not be deleted. Please retry the request.', 'bit-smtp');
            } else {
                $removed = $deleted > 0;
            }
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => $located['done'],
        ];
    }
}
