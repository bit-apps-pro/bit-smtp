<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
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
     * Hard cap on the rows a single CSV export may return, so a filtered export of a large log table
     * stays bounded in memory and response size. The controller surfaces a `truncated` flag when a
     * result is clamped to this cap, so the truncation is never silent.
     */
    public const MAX_EXPORT_ROWS = 5000;

    /**
     * Hard cap on the resend-history children returned for one log's detail view, so a log resent
     * many times cannot bloat the detail payload.
     */
    public const MAX_RESEND_CHILDREN = 50;

    /**
     * The only columns an export may read, in CSV order: safe send/delivery metadata (including the
     * recipient To/Cc/Bcc). Message body, credentials, and debug detail are deliberately excluded and
     * never selected into memory.
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

    public function __construct()
    {
        self::initializeLoggingContinuity();
    }

    public function all($skip = 0, $take = 20, $filters = [])
    {
        $logs  = [];
        $count = 0;
        if ($take < 1) {
            $take = 1;
        }

        try {
            $logs = Log::filtered($filters)->skip($skip)->take($take)->desc()->get()->all();

            // count() must run against its own unfiltered-of-pagination query: reusing the paged
            // builder would carry its skip/take into the aggregate and starve rows off any page
            // past the first.
            $count = Log::filtered($filters)->count();
        } catch (Throwable $th) {
            throw $th;
        }

        $pages   = \intval($count / $take);
        $current = ($skip / $take) + 1;

        return compact('count', 'logs', 'pages', 'current');
    }

    /**
     * Fetch up to $limit newest-first log rows matching the same whitelist filters as all(), projected
     * to export-safe metadata columns only (EXPORT_SAFE_COLUMNS) — the message body, credentials, and
     * debug detail are never selected into memory. Reuses Log::filtered() so the export stays in lockstep
     * with the list view. $limit is clamped to [1, MAX_EXPORT_ROWS + 1] — the extra row lets the caller
     * detect (and honestly flag) truncation by asking for one past the cap.
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
     * Filter-scoped export rows plus whether the set was clamped to the cap. Probes one past the cap so
     * an exactly-at-cap result is not falsely flagged truncated, then trims to the cap. The single place
     * REST and CLI exports derive truncation, so the two can never drift.
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
     * The manual-resend children of a log, newest-first and bounded, projected to the summary the
     * detail view renders as the resend chain.
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

        // A resend gets a fresh provider message-id + tracking token; overwrite so delivery webhooks
        // correlate to this resend, not the original send.
        $log->message_id  = $messageId;
        $log->tracking_id = $trackingId;

        // ...and its delivery outcome is superseded: clear the prior send's rollup + child events so
        // stale webhook status can't linger against this row.
        $this->resetDelivery($log);

        // A resend may record its non-terminal transport hand-off, never a terminal delivery
        // inference. Terminal statuses are exclusively folded by updateDeliveryRollup() after an
        // authenticated webhook event has correlated to this new send.
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

        // subject/to_addr are content-stable across a resend, but details must be refreshed so the
        // attempt trail reflects this resend's outcome rather than the original send's stale trail.
        if (\is_array($details)) {
            // Only overwrite sender when this call's details actually carry a 'from': an absent key
            // must not blank out a sender captured by an earlier save/update.
            if (Arr::has($details, 'from')) {
                $log->sender = $this->sanitizeSender((string) Arr::get($details, 'from', ''));
            }
            unset($details['subject'], $details['to'], $details['from'], $details['phpmailer_exception_code']);
            $log->details = LogBodyRedactor::apply($details, $this->bodyStorageMode());
        }

        $log->save();
    }

    /**
     * Locate the log a delivery event belongs to, scoped to the sending connection's stable id (not
     * its mutable label). An empty key never matches: a NULL-keyed row must not be correlated by an
     * absent identifier.
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
     * Append a provider delivery event as a child row. The event_hash UNIQUE key makes this
     * idempotent; INSERT IGNORE drops a replayed event without erroring.
     *
     * @return bool true when a new row was written, false when the event was a duplicate
     */
    public function recordDeliveryEvent(int $logId, DeliveryEvent $event, string $hash): bool
    {
        // A null here must land as SQL NULL, not '' — a DATETIME column rejects the empty string.
        // insertOrIgnore() compiles nulls to a NULL literal rather than binding them.
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
     * A log's delivery-event child rows, ordered oldest-first (occurred_at then id). Shaped for both
     * DeliveryRollup::compute() (needs created_at for its recency fallback) and the delivery-status UI.
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
     * Fold an open/click engagement fire onto its (log_id, type, target) row. The event_key UNIQUE
     * key makes re-fires idempotent: ON DUPLICATE KEY UPDATE increments the totals instead of
     * inserting a duplicate row. A machine-fired hit (Apple MPP / image proxy / prefetch bot) is
     * counted separately into automated_hits so human engagement stays honest.
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
                // upsertRaw never auto-bumps updated_at, so first_at keeps the original fire time
                // while last_at and updated_at are advanced explicitly.
                'hits'           => 'hits + 1',
                'automated_hits' => ['automated_hits + %d', [$automatedSeed]],
                'last_at'        => ['%s', [$now]],
                'updated_at'     => ['%s', [$now]],
            ]
        );
    }

    /**
     * A log's engagement rows (opens + clicks), oldest-first, shaped for the detail view.
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
     * Open/click engagement flags for the given logs, keyed by log id. Every requested id is present,
     * defaulted to both false, so callers can index without a fallback. One grouped query per page.
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
     * Resolve the log for a tracking token via idx_tracking_id, returning the whole model so one fetch
     * can supply both its id and its send time. The UUID is globally unique so no connection scoping is
     * needed; an empty token never matches a NULL-keyed row.
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
     * Resolve only the log id for a tracking token — a thin projection over findByTrackingId() for
     * callers that need the identifier alone.
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
     * Clear a log's delivery outcome and drop its child events, so a superseding resend cannot
     * inherit stale webhook status from the previous send.
     */
    public function resetDelivery(Log $log): void
    {
        $this->updateDeliveryRollup($log, null, null);

        LogDeliveryEvent::where('log_id', (int) $log->id)->delete();
    }

    public function delete(array $ids)
    {
        $ids = $this->normalizeLogIds($ids);
        if ($ids === []) {
            return true;
        }

        // Delivery and engagement children carry recipient-linked PII (provider detail, click
        // targets); their ON DELETE CASCADE foreign keys remove them with the parent rows.
        $deleted = Log::whereIn('id', $ids)->delete();

        return $deleted !== false;
    }

    public function maybeDeleteOlder()
    {
        // The bit_smtp_retention_gc cron job (CoreServiceProvider) owns retention cleanup; this
        // opportunistic throttle-based path is only a bounded fallback for sites where WP-Cron
        // isn't scheduled to run it (e.g. WP_CRON disabled/broken).
        if (wp_next_scheduled(Config::RETENTION_GC_HOOK)) {
            return;
        }

        $currentTime  = time();
        $logDeletedAt = Config::getOption('log_deleted_at', ($currentTime - (DAY_IN_SECONDS * 30)));
        if ((abs($logDeletedAt - $currentTime) / DAY_IN_SECONDS) > 30) {
            $this->deleteOlder();
        }
    }

    public function deleteOlder()
    {
        $logRetention = (int) PluginSettings::getWithLegacyFallback('log_retention_days', 'log_retention', 30);
        if ($logRetention > 200) {
            $logRetention = 200;
        }

        $currentDate = new DateTime();

        $dateToDelete = date_sub($currentDate, date_interval_create_from_date_string($logRetention . ' days'));
        $dateToDelete = date_format($dateToDelete, QueryBuilder::TIME_FORMAT);

        // Set-based by design: materializing every expired id would build an unbounded PHP
        // collection. The children cascade with their parents.
        $deleted = Log::where('created_at', '<', $dateToDelete)->delete();
        if ($deleted === false) {
            return false;
        }

        Config::updateOption('log_deleted_at', time());

        return $deleted;
    }

    public function updateRetention($days)
    {
        if ($days < 1) {
            $days = 1;
        } elseif ($days > 200) {
            $days = 200;
        }

        // Write-through: the prefs blob is the source of truth the retention reader consults; keep
        // the legacy option coherent for any external legacy reader.
        PluginSettings::make()->set('log_retention_days', $days)->save();

        return (bool) Config::updateOption('log_retention', $days);
    }

    /**
     * Check if logging is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) PluginSettings::getWithLegacyFallback('logging_enabled', 'logging_enabled', true);
    }

    /**
     * Enable or disable logging
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

            // Write-through: the prefs blob is the source of truth isEnabled() consults, so the
            // toggle must land there too, not only in the legacy option.
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
     * Start (or retain) the proven-continuous UTC interval for precise log analytics. Existing
     * installs without the marker deliberately begin at first migration/service boot instead of
     * inferring continuity from legacy local display timestamps.
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
     * Bulk insert multiple mail logs
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

        // See maybeDeleteOlder(): no-op when the retention cron is scheduled, bounded fallback
        // otherwise.
        $this->maybeDeleteOlder();

        // Resolve the body-storage preference once for the whole batch rather than per row.
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
     * The active `log_store_body` privacy preference (full|redacted|metadata), defaulting to full.
     * Read on every write path so a redacted/metadata choice is honored the moment it is saved.
     */
    private function bodyStorageMode(): string
    {
        return (string) PluginSettings::make()->get('log_store_body', LogBodyRedactor::MODE_FULL);
    }

    /**
     * Defense-in-depth for a persisted From display name (attacker-influenceable free text): drop
     * invalid UTF-8 via WordPress, then strip control chars (incl. CR/LF) while preserving the
     * literal "Name <email>" bracket that sanitize_text_field() would wrongly strip as an HTML tag.
     */
    private function sanitizeSender(string $sender): string
    {
        return SenderFormatter::sanitize(wp_check_invalid_utf8($sender));
    }

    /**
     * Deterministic dedup key for an engagement row. Delimited so distinct (type, target) pairs can't
     * collide via bare concatenation.
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
