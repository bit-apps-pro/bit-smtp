<?php

namespace BitApps\SMTP\Privacy;

use BitApps\SMTP\Model\LogEngagementEvent;

\defined('ABSPATH') || exit();

/**
 * WordPress personal-data eraser for engagement events (fail-closed, log rows left intact).
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
     * Erase one page of engagement data, fail-closed: DB errors report as retained, never claimed as erased.
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
