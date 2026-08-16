<?php

namespace BitApps\SMTP\HTTP\Services;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Collection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\QueryBuilder;
use BitApps\SMTP\Deps\BitApps\WPKit\Helpers\Arr;
use BitApps\SMTP\Mail\Analytics\SubjectPatternNormalizer;
use BitApps\SMTP\Mail\Status\DeliveryStatus;
use BitApps\SMTP\Mail\Webhook\DeliveryEvent;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;
use DateTime;
use RuntimeException;
use Throwable;
use WP_Error;

\defined('ABSPATH') || exit();

class LogService
{
    public function __construct()
    {
        self::initializeLoggingContinuity();

        if (\defined('DOING_CRON') && DOING_CRON) {
            $this->maybeDeleteOlder();
        }
    }

    public function all($skip = 0, $take = 20, $filters = [])
    {
        $logs  = [];
        $count = 0;
        if ($take < 1) {
            $take = 1;
        }

        try {
            $logsQuery  = Log::skip($skip)
                ->take($take)
                ->desc();
            if (isset($filters['to_addr']) && !empty($filters['to_addr'])) {
                $logsQuery->where('to_addr', 'LIKE', '%' . Connection::esc_like($filters['to_addr']) . '%');
            }
            $logs  = $this->toRows($logsQuery->get());
            $count = Log::count();
        } catch (Throwable $th) {
            // throw $th;
        }

        $pages   = \intval($count / $take);
        $current = ($skip / $take) + 1;

        return compact('count', 'logs', 'pages', 'current');
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
        return $this->toRows(Log::where('id', $ids)->get());
    }

    public function save($status, $details, $message = null, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $sourcePlugin = null, ?string $routingType = null, ?int $routingRuleIndex = null)
    {
        $log             = new Log();

        $log->status      = $status;
        if (isset($message)) {
            $log->debug_info    = \is_scalar($message) ? [$message] : $message;
        }

        $recipients              = Arr::get($details, 'to', []);
        $log->subject            = Arr::get($details, 'subject', '');
        $log->to_addr            = Arr::get($details, 'to', '[]');
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
        $log->sender             = sanitize_text_field((string) Arr::get($details, 'from', ''));

        unset($details['subject'], $details['to'], $details['from'], $details['phpmailer_exception_code']);
        $log->details    = $details;

        return $log->save();
    }

    public function update($id, $status, $details, $message = null, ?string $connection = null, ?string $messageId = null, ?string $trackingId = null, ?string $connectionId = null, ?string $deliveryStatus = null, ?string $sourcePlugin = null, ?string $routingType = null, ?int $routingRuleIndex = null)
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

        if (isset($message)) {
            $log->debug_info    = \is_scalar($message) ? [$message] : $message;
        }

        // subject/to_addr are content-stable across a resend, but details must be refreshed so the
        // attempt trail reflects this resend's outcome rather than the original send's stale trail.
        if (\is_array($details)) {
            $log->sender = sanitize_text_field((string) Arr::get($details, 'from', ''));
            unset($details['subject'], $details['to'], $details['from'], $details['phpmailer_exception_code']);
            $log->details = $details;
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
        $table = (new LogDeliveryEvent())->getTable();

        // Nullable columns must land as SQL NULL, not '' — wpdb::prepare would coerce a null %s to an
        // empty string, which a DATETIME column rejects. So emit a NULL literal for absent values.
        $columns = [
            'log_id'      => ['%d', $logId],
            'recipient'   => ['%s', $event->recipient()],
            'status'      => ['%s', $event->status()],
            'terminal'    => ['%d', $event->isTerminal() ? 1 : 0],
            'detail'      => ['%s', $event->detail()],
            'occurred_at' => ['%s', $event->occurredAt()],
            'event_hash'  => ['%s', $hash],
            'created_at'  => ['%s', gmdate('Y-m-d H:i:s')],
        ];

        $names        = [];
        $placeholders = [];
        $args         = [];
        foreach ($columns as $name => $spec) {
            $names[] = '`' . $name . '`';
            if ($spec[1] === null) {
                $placeholders[] = 'NULL';

                continue;
            }

            $placeholders[] = $spec[0];
            $args[]         = $spec[1];
        }

        $sql = Connection::prepare(
            'INSERT IGNORE INTO `' . $table . '` (' . implode(', ', $names) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $args
        );

        return (int) Connection::query($sql) > 0;
    }

    /**
     * A log's delivery-event child rows, ordered oldest-first (occurred_at then id). Shaped for both
     * DeliveryRollup::compute() (needs created_at for its recency fallback) and the delivery-status UI.
     *
     * @return array<int,array{recipient:string,status:string,terminal:int,occurred_at:string,created_at:string}>
     */
    public function deliveryEvents(int $logId): array
    {
        $events = $this->toRows(LogDeliveryEvent::where('log_id', $logId)->orderBy('occurred_at')->orderBy('id')->get());

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

        $table = (new LogDeliveryEvent())->getTable();
        Connection::query(
            Connection::prepare('DELETE FROM `' . $table . '` WHERE `log_id` = %d', [(int) $log->id])
        );
    }

    public function delete(array $ids)
    {
        $ids = $this->normalizeLogIds($ids);
        if ($ids === []) {
            return true;
        }

        // Delivery events carry provider recipient and diagnostic detail. They intentionally have
        // no database FK for WordPress compatibility, so remove the children before their selected
        // parent logs and fail closed if that privacy cleanup cannot complete. This deliberately
        // is not a transaction: WordPress installs can use mixed or non-transactional engines, and
        // child-first cleanup leaves no retained provider PII if a later parent deletion fails.
        if (!$this->deleteDeliveryEventsForLogs($ids)) {
            return false;
        }

        $deleted = Log::where('id', $ids)->delete();

        return $deleted !== false;
    }

    public function maybeDeleteOlder()
    {
        $currentTime  = time();
        $logDeletedAt = Config::getOption('log_deleted_at', ($currentTime - (DAY_IN_SECONDS * 30)));
        if ((abs($logDeletedAt - $currentTime) / DAY_IN_SECONDS) > 30) {
            $this->deleteOlder();
        }
    }

    public function deleteOlder()
    {
        $logRetention = Config::getOption('log_retention', 30);
        if ($logRetention > 200) {
            $logRetention = 200;
        }

        $currentDate = new DateTime();

        $dateToDelete = date_sub($currentDate, date_interval_create_from_date_string($logRetention . ' days'));
        $dateToDelete = date_format($dateToDelete, QueryBuilder::TIME_FORMAT);

        // Keep retention set-based: materializing every expired id would create an unbounded PHP
        // collection and a correspondingly unbounded WHERE IN child delete. The child statement
        // shares the parent's cutoff and must complete before the parent delete is attempted.
        if (!$this->deleteDeliveryEventsOlderThan($dateToDelete)) {
            return false;
        }

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

        $status = Config::updateOption('log_retention', $days);

        return (bool) ($status);
    }

    /**
     * Check if logging is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) Config::getOption('logging_enabled', true);
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
        if ($wasEnabled !== $enable && !Config::updateOption('logging_enabled', $enable ? 1 : 0, true)) {
            return false;
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
        if (!(bool) Config::getOption('logging_enabled', true)) {
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

        /**
         * This is fallback to delete older log. as we only invoke deleteOlder in cron,
         * site may not have working cron.
         */
        $this->maybeDeleteOlder();

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
                'created_at_utc'      => gmdate('Y-m-d H:i:s'),
            ];

            foreach (['source_plugin', 'routing_type', 'routing_rule_index'] as $field) {
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
            $record['subject_pattern'] = $this->subjectPattern((string) $record['subject']);
            $record['recipient_count'] = $this->recipientCount(Arr::get($details, 'to', []));
            $record['sender']          = sanitize_text_field((string) Arr::get($details, 'from', ''));

            unset(
                $details['subject'],
                $details['to'],
                $details['from'],
                $details['phpmailer_exception_code']
            );

            $record['details'] = wp_json_encode($details);
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
     * Normalize a QueryBuilder get() result to a plain array: WPDatabase returns a Collection on
     * newer versions and a plain array on older ones.
     *
     * @param mixed $result
     *
     * @return array
     */
    private function toRows($result): array
    {
        if ($result instanceof Collection) {
            return $result->all();
        }

        return \is_array($result) ? $result : [];
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
     * Delete delivery-event children for a closed, normalized list of parent IDs.
     *
     * WPDatabase prepares the value-only WHERE IN clause; its table reference comes solely from the
     * internal model convention and is never derived from a request or provider payload.
     *
     * @param array<int,int> $ids
     */
    private function deleteDeliveryEventsForLogs(array $ids): bool
    {
        if ($ids === []) {
            return true;
        }

        $deleted = LogDeliveryEvent::where('log_id', $ids)->delete();

        return $deleted !== false;
    }

    /**
     * Remove event PII for every log covered by the retention cutoff without hydrating rows or
     * creating an unbounded ID list. Table identifiers are model-derived and closed; the cutoff is
     * passed as a prepared value.
     */
    private function deleteDeliveryEventsOlderThan(string $dateToDelete): bool
    {
        $logsTable   = (new Log())->getTable();
        $eventsTable = (new LogDeliveryEvent())->getTable();
        // Use Connection's dynamic wpdb proxy so this query follows the same prepared-query and
        // database-error behavior as the rest of the service without exposing a table identifier
        // to request data.
        $sql = Connection::__callStatic('prepare', [
            'DELETE `' . $eventsTable . '` FROM `' . $eventsTable . '` '
            . 'INNER JOIN `' . $logsTable . '` ON `' . $eventsTable . '`.`log_id` = `' . $logsTable . '`.`id` '
            . 'WHERE `' . $logsTable . '`.`created_at` < %s',
            [$dateToDelete],
        ]);

        return Connection::__callStatic('query', [$sql]) !== false;
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
