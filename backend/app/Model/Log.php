<?php

namespace BitApps\SMTP\Model;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;
use BitApps\SMTP\Deps\BitApps\WPDatabase\QueryBuilder;
use BitApps\SMTP\Mail\Status\DeliveryStatus;

/**
 * Model for log
 *
 * @property int                    $id
 * @property int                    $status
 * @property string                 $subject
 * @property array                  $to_addr
 * @property null|array<int,string> $cc
 * @property null|array<int,string> $bcc
 * @property null|string            $sender
 * @property array                  $details
 * @property array                  $debug_info
 * @property int                    $retry_count
 * @property string                 $connection
 * @property string                 $connection_id
 * @property string                 $message_id
 * @property string                 $tracking_id
 * @property string                 $delivery_status
 * @property string                 $delivery_updated_at
 * @property null|string            $source_plugin
 * @property null|string            $routing_type
 * @property null|int               $routing_rule_index
 * @property null|string            $failure_class
 * @property null|int               $resend_parent_id
 * @property null|string            $subject_pattern
 * @property null|int               $recipient_count
 * @property string                 $created_at
 * @property null|string            $created_at_utc
 * @property string                 $updated_at
 */
class Log extends Model
{
    public const SUCCESS = 1;

    public const ERROR   = 0;

    /**
     * Maps a `status` filter value onto the send-outcome flag stored on the row.
     */
    private const STATUS_FILTER_FLAGS = [
        'sent'   => self::SUCCESS,
        'failed' => self::ERROR,
    ];

    /**
     * Valid `delivery_status` filter values, every one a DeliveryStatus state-machine constant.
     */
    private const FILTERABLE_DELIVERY_STATUSES = [
        DeliveryStatus::DELIVERED,
        DeliveryStatus::ACCEPTED,
        DeliveryStatus::DEFERRED,
        DeliveryStatus::BLOCKED,
        DeliveryStatus::BOUNCED,
        DeliveryStatus::SPAM,
        DeliveryStatus::PENDING,
    ];

    public $casts = [
        'status'              => 'int',
        'subject'             => 'string',
        'to_addr'             => 'array',
        'cc'                  => 'array',
        'bcc'                 => 'array',
        'sender'              => 'string',
        'details'             => 'array',
        'debug_info'          => 'array',
        'retry_count'         => 'int',
        'connection'          => 'string',
        'connection_id'       => 'string',
        'message_id'          => 'string',
        'tracking_id'         => 'string',
        'delivery_status'     => 'string',
        'delivery_updated_at' => 'string',
        'source_plugin'       => 'string',
        'routing_type'        => 'string',
        'routing_rule_index'  => 'int',
        'failure_class'       => 'string',
        'resend_parent_id'    => 'int',
        'subject_pattern'     => 'string',
        'recipient_count'     => 'int',
        'created_at'          => 'string',
        'created_at_utc'      => 'string',
        'updated_at'          => 'string',
    ];

    protected $fillable = [
        'status',
        'message',
        'sender',
        'details',
        'retry_count',
        'connection',
        'connection_id',
        'message_id',
        'tracking_id',
        'delivery_status',
        'delivery_updated_at',
        'source_plugin',
        'routing_type',
        'routing_rule_index',
        'failure_class',
        'resend_parent_id',
        'subject_pattern',
        'recipient_count',
        'created_at_utc',
    ];

    /**
     * A query narrowed by the logs-list filter whitelist, so the paged read, the count and the export
     * all derive their WHERE clause from one place. Every value is bound through the builder's
     * parameterized where()/whereBetween(), never string-interpolated; an unrecognized or malformed
     * filter is ignored rather than applied.
     *
     * @param array<string,mixed> $filters
     */
    public static function filtered(array $filters): QueryBuilder
    {
        $query = self::query();

        if (!empty($filters['to_addr'])) {
            // esc_like proxies to $wpdb through Connection's dynamic dispatch, so LIKE wildcards in
            // the filter value (e.g. an underscore) match literally instead of as wildcards.
            $pattern = (string) Connection::__callStatic('esc_like', [$filters['to_addr']]);
            $query->where('to_addr', 'LIKE', '%' . $pattern . '%');
        }

        if (!empty($filters['status']) && isset(self::STATUS_FILTER_FLAGS[$filters['status']])) {
            $query->where('status', self::STATUS_FILTER_FLAGS[$filters['status']]);
        }

        if (!empty($filters['delivery_status']) && \in_array($filters['delivery_status'], self::FILTERABLE_DELIVERY_STATUSES, true)) {
            $query->where('delivery_status', $filters['delivery_status']);
        }

        if (!empty($filters['connection_id'])) {
            // Match the analytics COALESCE(connection_id, connection) grouping: a top-connection ranked
            // by a legacy label (empty connection_id) must still resolve to its rows here, or the
            // "View in logs" deep-link would land on an empty list that contradicts the clicked count.
            $connectionId = $filters['connection_id'];
            $query->where(static function ($group) use ($connectionId): void {
                $group->where('connection_id', $connectionId)->orWhere('connection', $connectionId);
            });
        }

        if (!empty($filters['source_plugin'])) {
            $query->where('source_plugin', $filters['source_plugin']);
        }

        return self::applyDateRange($query, $filters);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(static function (self $log): void {
            if ($log->created_at_utc !== null && $log->created_at_utc !== '') {
                return;
            }

            if (!$log->exists()) {
                $log->created_at_utc = gmdate('Y-m-d H:i:s');

                return;
            }
        });
    }

    /**
     * Narrows $query to the `date_from`/`date_to` filter window, each side applied only when it parses
     * as a strict 'YYYY-MM-DD' date.
     *
     * @param array<string,mixed> $filters
     */
    private static function applyDateRange(QueryBuilder $query, array $filters): QueryBuilder
    {
        $from = self::validFilterDate($filters['date_from'] ?? null);
        $to   = self::validFilterDate($filters['date_to'] ?? null);

        if ($from !== null && $to !== null) {
            $query->whereBetween('created_at', $from . ' 00:00:00', $to . ' 23:59:59');
        } elseif ($from !== null) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        } elseif ($to !== null) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        return $query;
    }

    /**
     * The value as a strict 'YYYY-MM-DD' date string, or null when it is anything else.
     *
     * @param mixed $value
     */
    private static function validFilterDate($value): ?string
    {
        if (!\is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
