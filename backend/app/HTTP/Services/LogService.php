<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\QueryBuilder;
use BitApps\SMTP\Deps\BitApps\WPKit\Helpers\Arr;
use BitApps\SMTP\Mail\Analytics\SubjectPatternNormalizer;
use BitApps\SMTP\Mail\Dispatch\SenderFormatter;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Tracking\EngagementRecorder;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use BitApps\SMTP\Model\LogEngagementEvent;
use BitApps\SMTP\Settings\PluginSettings;
use DateTime;
use RuntimeException;
use Throwable;
use WP_Error;

\defined('ABSPATH') || exit();

class LogService
{
    /**
     * Hard cap on export rows; truncated flag prevents silent clamps.
     */
    public const MAX_EXPORT_ROWS = 5000;

    /**
     * Hard cap on resend-history children to prevent payload bloat.
     */
    public const MAX_RESEND_CHILDREN = 50;

    /**
     * Export-safe columns in CSV order; body/credentials/debug never selected.
     */
    public const EXPORT_SAFE_COLUMNS = [
        'id',
        'created_at',
        'status',
        'to_addr',
        'cc',
        'bcc',
        'subject',
        'connection',
        'sender',
        'failure_class',
        'delivery_status',
        'message_id',
        'retry_count',
    ];

    /**
     * Maps an engagement event type onto the per-log flag it raises in engagementFlagsFor().
     */
    private const ENGAGEMENT_TYPE_FLAGS = [
        EngagementRecorder::TYPE_OPEN  => 'opened',
        EngagementRecorder::TYPE_CLICK => 'clicked',
    ];

    /**
     * Child tables keyed by `log_id` that carry recipient PII and must be purged with their parent log.
     */
    private const PII_CHILD_MODELS = [
        LogDeliveryEvent::class,
        LogEngagementEvent::class,
    ];

    public function __construct()
    {
        self::initializeLoggingContinuity();
    }

    /**
     * Paginated logs matching filters; count runs on the unfiltered query to avoid pagination starving rows.
     *
     * @param mixed $skip
     * @param mixed $take
     * @param mixed $filters
     */
    public function all($skip = 0, $take = 20, $filters = [])
    {
        $logs  = [];
        $count = 0;
        if ($take < 1) {
            $take = 1;
        }

        try {
            $logs = Log::filtered($filters)->skip($skip)->take($take)->desc()->get()->all();

            $count = Log::filtered($filters)->count();
        } catch (Throwable $th) {
            throw $th;
        }

        $pages   = \intval($count / $take);
        $current = ($skip / $take) + 1;

        return compact('count', 'logs', 'pages', 'current');
    }

    /**
     * Fetch export rows (newest-first, export-safe columns, body/credentials never selected).
     *
     * @param array<string,mixed> $filters
     *
     * @return array<int,Log>
     */
    public function exportRows(array $filters, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_EXPORT_ROWS + 1));

        $query = Log::filtered($filters)->take((string) $limit)->desc();

        return $query->get(self::EXPORT_SAFE_COLUMNS)->all();
    }

    /**
     * Export rows with truncation flag (probe past cap to detect exact-at-cap vs over).
     *
     * @param array<string,mixed> $filters
     *
     * @return array{rows: array<int,Log>, truncated: bool}
     */
    public function exportRowsWithTruncation(array $filters): array
    {
        $rows      = $this->exportRows($filters, self::MAX_EXPORT_ROWS + 1);
        $truncated = \count($rows) > self::MAX_EXPORT_ROWS;
        if ($truncated) {
            $rows = \array_slice($rows, 0, self::MAX_EXPORT_ROWS);
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    public function success(array $mailData, ?string $connection = null)
    {
        $this->save(Log::SUCCESS, $mailData, null, $connection);
    }

    public function error(WP_Error $error, ?string $connection = null)
    {
        $this->save(Log::ERROR, $error->get_error_data(), $error->get_error_messages(), $connection);
    }

    public function get(int $id): ?Log
    {
        $log = Log::where('id', $id)->first();

        return $log instanceof Log ? $log : null;
    }

    /**
     * @return array<int,Log>
     */
    public function getBulk(array $ids): array
    {
        return Log::where('id', $ids)->get()->all();
    }

    /**
     * Fetch manual-resend children of a log, newest-first and bounded, formatted for the detail view.
     *
     * @return array<int,array{id:int,status:string,created_at:string}>
     */
    public function resendChildren(int $parentId): array
    {
        if ($parentId < 1) {
            return [];
        }

        $children = Log::where('resend_parent_id', $parentId)
            ->take((string) self::MAX_RESEND_CHILDREN)
            ->desc()
            ->get(['id', 'status', 'created_at'])
            ->all();

        return array_map(static function (Log $child): array {
            return [
                'id'         => (int) $child->id,
                'status'     => (int) $child->status === Log::SUCCESS ? 'sent' : 'failed',
                'created_at' => (string) $child->created_at,
            ];
        }, $children);
    }

    public function save($status, $details, $message = null, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $sourcePlugin = null, ?string $routingType = null, ?int $routingRuleIndex = null, ?string $failureClass = null)
    {
        $log             = new Log();

        $log->status      = $status;
        if (isset($message)) {
            $log->debug_info    = \is_scalar($message) ? [$message] : $message;
        }

        $recipients              = Arr::get($details, 'to', []);
        $log->subject            = Arr::get($details, 'subject', '');
        $log->to_addr            = Arr::get($details, 'to', '[]');
        $log->cc                 = Arr::get($details, 'cc', []);
        $log->bcc                = Arr::get($details, 'bcc', []);
        $log->subject_pattern    = $this->subjectPattern((string) $log->subject);
        $log->recipient_count    = $this->recipientCount($recipients);
        $log->connection         = $connection;
        $log->connection_id      = $connectionId;
        $log->message_id         = $messageId;
        $log->tracking_id        = $trackingId;
        $log->source_plugin      = $sourcePlugin;
        $log->routing_type       = $routingType;
        $log->routing_rule_index = $routingRuleIndex;
        $log->created_at_utc     = gmdate('Y-m-d H:i:s');
        $log->sender             = $this->sanitizeSender((string) Arr::get($details, 'from', ''));
        if ($failureClass !== null) {
            $log->failure_class = $failureClass;
        }

        unset($details['subject'], $details['to'], $details['cc'], $details['bcc'], $details['from'], $details['phpmailer_exception_code']);
        $log->details    = LogBodyRedactor::apply($details, $this->bodyStorageMode());

        return $log->save();
    }

    /**
     * Update log for resend (fresh message_id/tracking_id, clear delivery/events, update sender if present).
     *
     * @param mixed      $id
     * @param mixed      $status
     * @param mixed      $details
     * @param null|mixed $message
     */
    public function update($id, $status, $details, $message = null, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $deliveryStatus = null, ?string $sourcePlugin = null, ?string $routingType = null, ?int $routingRuleIndex = null, ?string $failureClass = null)
    {
        $log = $this->get($id);
        if (!$log) {
            return false;
        }

        $log->retry_count   = $log->retry_count + 1;
        $log->status        = $status;
        $log->connection    = $connection;
        $log->connection_id = $connectionId;

        if ($sourcePlugin !== null || $routingType !== null) {
            $log->source_plugin      = $sourcePlugin;
            $log->routing_type       = $routingType;
            $log->routing_rule_index = $routingRuleIndex;
        }

        $log->message_id  = $messageId;
        $log->tracking_id = $trackingId;

        $this->resetDelivery($log);

        if ($deliveryStatus === DeliveryStatus::ACCEPTED) {
            $log->delivery_status     = DeliveryStatus::ACCEPTED;
            $log->delivery_updated_at = gmdate('Y-m-d H:i:s');
        }

        if ($failureClass !== null) {
            $log->failure_class = $failureClass;
        }

        if (isset($message)) {
            $log->debug_info    = \is_scalar($message) ? [$message] : $message;
        }

        if (\is_array($details)) {
            if (Arr::has($details, 'from')) {
                $log->sender = $this->sanitizeSender((string) Arr::get($details, 'from', ''));
            }
            unset($details['subject'], $details['to'], $details['from'], $details['phpmailer_exception_code']);
            $log->details = LogBodyRedactor::apply($details, $this->bodyStorageMode());
        }

        $log->save();
    }

    /**
     * Locate log by connection_id and messageId/trackingId (empty key never matches).
     */
    public function findForCorrelation(string $connectionId, ?string $messageId, ?string $trackingId): ?Log
    {
        if ($connectionId === '') {
            return null;
        }

        if ($messageId !== null && $messageId !== '') {
            $byMessageId = Log::where('connection_id', $connectionId)->where('message_id', $messageId)->first();
            if ($byMessageId instanceof Log) {
                return $byMessageId;
            }
        }

        if ($trackingId !== null && $trackingId !== '') {
            $byTrackingId = Log::where('connection_id', $connectionId)->where('tracking_id', $trackingId)->first();
            if ($byTrackingId instanceof Log) {
                return $byTrackingId;
            }
        }

        return null;
    }

    /**
     * Append delivery event (idempotent via event_hash); return true if new row, false if duplicate.
     *
     * @return bool true when a new row was written, false when the event was a duplicate
     */
    public function recordDeliveryEvent(int $logId, DeliveryEvent $event, string $hash): bool
    {
        $written = LogDeliveryEvent::query()->insertOrIgnore([
            'log_id'      => $logId,
            'recipient'   => $event->recipient(),
            'status'      => $event->status(),
            'terminal'    => $event->isTerminal() ? 1 : 0,
            'detail'      => $event->detail(),
            'occurred_at' => $event->occurredAt(),
            'event_hash'  => $hash,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $written > 0;
    }

    /**
     * Delivery-event child rows (oldest-first); shaped for rollup compute and status UI.
     *
     * @return array<int,array{recipient:string,status:string,terminal:int,occurred_at:string,created_at:string}>
     */
    public function deliveryEvents(int $logId): array
    {
        $events = LogDeliveryEvent::where('log_id', $logId)->orderBy('occurred_at')->orderBy('id')->get()->all();

        return array_map(static function (LogDeliveryEvent $event) {
            return [
                'recipient'   => $event->recipient,
                'status'      => $event->status,
                'terminal'    => (int) $event->terminal,
                'occurred_at' => $event->occurred_at,
                'created_at'  => $event->created_at,
            ];
        }, $events);
    }

    /**
     * Record engagement (idempotent via event_key); separate automated hits for honest human counts.
     */
    public function recordEngagement(int $logId, string $type, string $target, bool $automated): void
    {
        $now           = gmdate('Y-m-d H:i:s');
        $automatedSeed = $automated ? 1 : 0;

        LogEngagementEvent::query()->upsertRaw(
            [
                'log_id'         => $logId,
                'type'           => $type,
                'target'         => $target,
                'hits'           => 1,
                'automated_hits' => $automatedSeed,
                'first_at'       => $now,
                'last_at'        => $now,
                'event_key'      => $this->engagementKey($logId, $type, $target),
            ],
            [
                'hits'           => 'hits + 1',
                'automated_hits' => ['automated_hits + %d', [$automatedSeed]],
                'last_at'        => ['%s', [$now]],
                'updated_at'     => ['%s', [$now]],
            ]
        );
    }

    /**
     * Engagement rows (opens + clicks, oldest-first) shaped for detail view.
     *
     * @return array<int,array{type:string,target:string,hits:int,automated_hits:int,first_at:string,last_at:string}>
     */
    public function engagementFor(int $logId): array
    {
        $events = LogEngagementEvent::where('log_id', $logId)->orderBy('first_at')->orderBy('id')->get()->all();

        return array_map(static function (LogEngagementEvent $event): array {
            return [
                'type'           => $event->type,
                'target'         => $event->target,
                'hits'           => (int) $event->hits,
                'automated_hits' => (int) $event->automated_hits,
                'first_at'       => $event->first_at,
                'last_at'        => $event->last_at,
            ];
        }, $events);
    }

    /**
     * Engagement flags per log (defaulted false, one query per page).
     *
     * @param int[] $logIds
     *
     * @return array<int,array{opened: bool, clicked: bool}>
     */
    public function engagementFlagsFor(array $logIds): array
    {
        $ids = $this->normalizeLogIds($logIds);
        if ($ids === []) {
            return [];
        }

        $events = LogEngagementEvent::query()
            ->select(['log_id', 'type'])
            ->selectRaw('SUM(hits) - SUM(automated_hits) AS human_hits')
            ->whereIn('log_id', $ids)
            ->groupBy(['log_id', 'type'])
            ->get();

        $flags = array_fill_keys($ids, ['opened' => false, 'clicked' => false]);
        foreach ($events as $event) {
            $flag = self::ENGAGEMENT_TYPE_FLAGS[$event->type] ?? null;
            if ($flag !== null && (int) $event->getAttribute('human_hits') > 0) {
                $flags[(int) $event->log_id][$flag] = true;
            }
        }

        return $flags;
    }

    /**
     * Resolve log by tracking token (globally unique, empty never matches NULL-keyed row).
     */
    public function findByTrackingId(string $trackingId): ?Log
    {
        if ($trackingId === '') {
            return null;
        }

        $log = Log::where('tracking_id', $trackingId)->first();

        return $log instanceof Log ? $log : null;
    }

    /**
     * Resolve only the log id for a tracking token (projection over findByTrackingId).
     */
    public function findLogIdByTrackingId(string $trackingId): ?int
    {
        $log = $this->findByTrackingId($trackingId);

        return $log === null ? null : (int) $log->id;
    }

    public function updateDeliveryRollup(Log $log, ?string $status, ?string $updatedAt): void
    {
        $log->delivery_status     = $status;
        $log->delivery_updated_at = $updatedAt;
        $log->save();
    }

    /**
     * Clear delivery outcome and drop child events (prevent resend stale webhook inherit).
     */
    public function resetDelivery(Log $log): void
    {
        $this->updateDeliveryRollup($log, null, null);

        LogDeliveryEvent::where('log_id', (int) $log->id)->delete();
    }

    /**
     * Delete logs child-first so PII rows go even on upgraded schemas lacking the FK cascade; keep parents if that fails.
     */
    public function delete(array $ids)
    {
        $ids = $this->normalizeLogIds($ids);
        if ($ids === []) {
            return true;
        }

        if (!$this->deleteChildrenOfLogs($ids)) {
            return false;
        }

        $deleted = Log::whereIn('id', $ids)->delete();

        return $deleted !== false;
    }

    /**
     * Fallback retention cleanup (throttled) when cron job not scheduled.
     */
    public function maybeDeleteOlder()
    {
        if (wp_next_scheduled(Config::RETENTION_GC_HOOK)) {
            return;
        }

        $currentTime  = time();
        $logDeletedAt = Config::getOption('log_deleted_at', ($currentTime - (DAY_IN_SECONDS * 30)));
        if ((abs($logDeletedAt - $currentTime) / DAY_IN_SECONDS) > 30) {
            $this->deleteOlder();
        }
    }

    /**
     * Delete expired logs set-based and child-first (no hydration, no id list); keep parents if child cleanup fails.
     */
    public function deleteOlder()
    {
        $logRetention = (int) PluginSettings::getWithLegacyFallback('log_retention_days', 'log_retention', 30);
        if ($logRetention > 200) {
            $logRetention = 200;
        }

        $currentDate = new DateTime();

        $dateToDelete = date_sub($currentDate, date_interval_create_from_date_string($logRetention . ' days'));
        $dateToDelete = date_format($dateToDelete, QueryBuilder::TIME_FORMAT);

        if (!$this->deleteChildrenOfLogsOlderThan($dateToDelete)) {
            return false;
        }

        $deleted = Log::where('created_at', '<', $dateToDelete)->delete();
        if ($deleted === false) {
            return false;
        }

        Config::updateOption('log_deleted_at', time());

        return $deleted;
    }

    /**
     * Update retention window in prefs blob and legacy option for backward compatibility.
     *
     * @param mixed $days
     */
    public function updateRetention($days)
    {
        if ($days < 1) {
            $days = 1;
        } elseif ($days > 200) {
            $days = 200;
        }

        PluginSettings::make()->set('log_retention_days', $days)->save();

        return (bool) Config::updateOption('log_retention', $days);
    }

    /**
     * Check if logging is enabled.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) PluginSettings::getWithLegacyFallback('logging_enabled', 'logging_enabled', true);
    }

    /**
     * Enable/disable logging (writes legacy option + prefs blob, source of truth).
     *
     * @param bool $enable
     *
     * @return bool
     */
    public function setEnabled(bool $enable)
    {
        $wasEnabled = $this->isEnabled();
        if ($wasEnabled !== $enable) {
            if (!Config::updateOption('logging_enabled', $enable ? 1 : 0, true)) {
                return false;
            }

            PluginSettings::make()->set('logging_enabled', $enable)->save();
        }

        if (!$enable) {
            Config::deleteOption(Config::LOGGING_CONTINUITY_FROM_OPTION);

            return Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, false) === false;
        }

        self::initializeLoggingContinuity();

        return true;
    }

    /**
     * Start/retain proven-continuous UTC interval for analytics (ignore legacy local timestamps).
     */
    public static function initializeLoggingContinuity(): ?string
    {
        if (!(bool) PluginSettings::getWithLegacyFallback('logging_enabled', 'logging_enabled', true)) {
            return null;
        }

        $existing = Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, false);
        if (\is_string($existing) && preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $existing) === 1) {
            return $existing;
        }

        $continuityFrom = gmdate('Y-m-d H:i:s');
        Config::updateOption(Config::LOGGING_CONTINUITY_FROM_OPTION, $continuityFrom, true);
        if (Config::getOption(Config::LOGGING_CONTINUITY_FROM_OPTION, false) !== $continuityFrom) {
            throw new RuntimeException('Unable to persist the logging continuity timestamp.');
        }

        return $continuityFrom;
    }

    /**
     * Bulk insert logs (runs retention cleanup if cron unavailable; resolve body mode once).
     *
     * @param array<int,array{status: string, data: array|WP_Error}> $logs Array of log entries
     *
     * @return bool True on success, false on failure
     */
    public function bulkInsert(array $logs)
    {
        if (empty($logs)) {
            return false;
        }

        $this->maybeDeleteOlder();

        $bodyMode = $this->bodyStorageMode();

        $records = [];
        foreach ($logs as $log) {
            if (!isset($log['status']) || !isset($log['data'])) {
                continue;
            }

            $record = [
                'status'              => $log['status'],
                'retry_count'         => 0,
                'connection'          => $log['connection']          ?? null,
                'connection_id'       => $log['connection_id']       ?? null,
                'message_id'          => $log['message_id']          ?? null,
                'tracking_id'         => $log['tracking_id']         ?? null,
                'delivery_status'     => ($log['delivery_status'] ?? null) === DeliveryStatus::ACCEPTED
                    ? DeliveryStatus::ACCEPTED
                    : null,
                'delivery_updated_at' => ($log['delivery_status'] ?? null) === DeliveryStatus::ACCEPTED
                    ? ($log['delivery_updated_at'] ?? gmdate('Y-m-d H:i:s'))
                    : null,
                'failure_class'       => $log['failure_class']        ?? null,
                'created_at_utc'      => gmdate('Y-m-d H:i:s'),
            ];

            foreach (['source_plugin', 'routing_type', 'routing_rule_index', 'resend_parent_id'] as $field) {
                if (\array_key_exists($field, $log)) {
                    $record[$field] = $log[$field];
                }
            }

            if ($log['status'] === Log::ERROR && $log['data'] instanceof WP_Error) {
                $record['debug_info'] = wp_json_encode($log['data']->get_error_messages());
                $details              = $log['data']->get_error_data();
            } else {
                $details = $log['data'];
            }

            $record['subject']         = Arr::get($details, 'subject', '');
            $record['to_addr']         = wp_json_encode(Arr::get($details, 'to', []));
            $record['cc']              = wp_json_encode(Arr::get($details, 'cc', []));
            $record['bcc']             = wp_json_encode(Arr::get($details, 'bcc', []));
            $record['subject_pattern'] = $this->subjectPattern((string) $record['subject']);
            $record['recipient_count'] = $this->recipientCount(Arr::get($details, 'to', []));
            $record['sender']          = $this->sanitizeSender((string) Arr::get($details, 'from', ''));

            unset(
                $details['subject'],
                $details['to'],
                $details['cc'],
                $details['bcc'],
                $details['from'],
                $details['phpmailer_exception_code']
            );

            $record['details'] = wp_json_encode(LogBodyRedactor::apply($details, $bodyMode));
            $records[]         = $record;
        }

        if (empty($records)) {
            return false;
        }

        try {
            return (bool) Log::insert($records);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param mixed $recipients
     */
    private function recipientCount($recipients): int
    {
        if (\is_array($recipients)) {
            return \count(array_filter($recipients, static function ($recipient): bool {
                return \is_scalar($recipient) && trim((string) $recipient) !== '';
            }));
        }

        if (\is_string($recipients) && trim($recipients) !== '') {
            return \count(array_filter(str_getcsv($recipients), static function (string $recipient): bool {
                return trim($recipient) !== '';
            }));
        }

        return 0;
    }

    private function subjectPattern(string $subject): string
    {
        return (new SubjectPatternNormalizer())->normalize($subject);
    }

    /**
     * Active log_store_body privacy preference (full|redacted|metadata); read on every write.
     */
    private function bodyStorageMode(): string
    {
        return (string) PluginSettings::make()->get('log_store_body', LogBodyRedactor::MODE_FULL);
    }

    /**
     * Sanitize sender display name: validate UTF-8, strip control chars, preserve "Name <email>" format.
     */
    private function sanitizeSender(string $sender): string
    {
        return SenderFormatter::sanitize(wp_check_invalid_utf8($sender));
    }

    /**
     * Delete PII child rows for the given normalized log ids via prepared WHERE IN; false on any DB error.
     *
     * @param array<int,int> $ids
     */
    private function deleteChildrenOfLogs(array $ids): bool
    {
        foreach (self::PII_CHILD_MODELS as $childModel) {
            if ($childModel::whereIn('log_id', $ids)->delete() === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete PII child rows of logs created before the cutoff with one prepared JOIN delete per table (model-derived identifiers).
     */
    private function deleteChildrenOfLogsOlderThan(string $cutoff): bool
    {
        $logsTable = (new Log())->getTable();
        foreach (self::PII_CHILD_MODELS as $childModel) {
            $childTable = (new $childModel())->getTable();
            $sql        = Connection::__callStatic('prepare', [
                'DELETE `' . $childTable . '` FROM `' . $childTable . '` '
                . 'INNER JOIN `' . $logsTable . '` ON `' . $childTable . '`.`log_id` = `' . $logsTable . '`.`id` '
                . 'WHERE `' . $logsTable . '`.`created_at` < %s',
                [$cutoff],
            ]);

            if (Connection::__callStatic('query', [$sql]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deterministic dedup key (delimited to prevent collisions).
     */
    private function engagementKey(int $logId, string $type, string $target): string
    {
        return hash('sha256', $logId . '|' . $type . '|' . $target);
    }

    /**
     * @param array<int,mixed> $ids
     *
     * @return array<int,int>
     */
    private function normalizeLogIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $validated = filter_var($id, FILTER_VALIDATE_INT);
            if ($validated === false || $validated < 1) {
                continue;
            }

            $normalized[(int) $validated] = (int) $validated;
        }

        return array_values($normalized);
    }
}
