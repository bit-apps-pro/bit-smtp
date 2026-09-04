<?php

namespace BitApps\SMTP\Privacy;

use BitApps\SMTP\Model\LogEngagementEvent;

\defined('ABSPATH') || exit();

/**
 * WordPress personal-data exporter for open/click engagement events. Returns the opens and link
 * clicks recorded against logs the requested address was a recipient of, paginated per WP's
 * exporter contract ({data: groups[], done: bool}).
 */
final class EngagementDataExporter
{
    /**
     * Stable export group id/slug; also namespaces each item id.
     */
    public const GROUP_ID = 'bit-smtp-engagement';

    private RecipientLogLocator $locator;

    /**
     * @param null|RecipientLogLocator $locator recipient-to-log resolver; defaults to the real one
     */
    public function __construct(?RecipientLogLocator $locator = null)
    {
        $this->locator = $locator ?? new RecipientLogLocator();
    }

    /**
     * Export one page of engagement data for $email, matching WP's exporter callback signature.
     *
     * @return array{data: array<int,array<string,mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $located = $this->locator->locate($email, $page);

        return [
            'data' => $this->itemsForLogs($located['ids']),
            'done' => $located['done'],
        ];
    }

    /**
     * Every engagement row for the page's logs, folded to WP export items in one bounded WHERE IN.
     *
     * @param array<int,int> $logIds
     *
     * @return array<int,array<string,mixed>>
     */
    private function itemsForLogs(array $logIds): array
    {
        if ($logIds === []) {
            return [];
        }

        $rows = LogEngagementEvent::where('log_id', $logIds)
            ->orderBy('log_id')
            ->orderBy('id')
            ->get(['log_id', 'type', 'target', 'hits', 'automated_hits', 'first_at', 'last_at']);

        $items = [];
        foreach ($rows as $event) {
            $items[] = $this->toExportItem($event);
        }

        return $items;
    }

    /**
     * Shape a single engagement row as a WP export item; the click destination is only included for
     * clicks, and automated (bot/proxy) fires are surfaced separately from the honest total.
     *
     * @return array<string,mixed>
     */
    private function toExportItem(LogEngagementEvent $event): array
    {
        $logId  = (int) $event->log_id;
        $type   = (string) $event->type;
        $target = (string) $event->target;

        $data = [
            ['name' => __('Log entry ID', 'bit-smtp'), 'value' => $logId],
            ['name' => __('Engagement type', 'bit-smtp'), 'value' => $type],
        ];

        if ($type === 'click' && $target !== '') {
            $data[] = ['name' => __('Link clicked', 'bit-smtp'), 'value' => $target];
        }

        $data[] = ['name' => __('Total recorded fires', 'bit-smtp'), 'value' => (int) $event->hits];
        $data[] = ['name' => __('Automated fires (bots / mail proxies)', 'bit-smtp'), 'value' => (int) $event->automated_hits];
        $data[] = ['name' => __('First recorded', 'bit-smtp'), 'value' => (string) $event->first_at];
        $data[] = ['name' => __('Last recorded', 'bit-smtp'), 'value' => (string) $event->last_at];

        return [
            'group_id'          => self::GROUP_ID,
            'group_label'       => __('Email open & click tracking', 'bit-smtp'),
            'group_description' => __('Opens and link clicks this site recorded for emails sent to you while open/click tracking was enabled.', 'bit-smtp'),
            // (log_id, type, target) is the engagement row's UNIQUE key, so it yields a stable, unique
            // export item id without depending on the surrogate primary key.
            'item_id'           => self::GROUP_ID . '-' . $logId . '-' . sha1($type . '|' . $target),
            'data'              => $data,
        ];
    }
}
