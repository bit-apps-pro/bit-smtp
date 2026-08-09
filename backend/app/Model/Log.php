<?php

namespace BitApps\SMTP\Model;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;

/**
 * Model for log
 *
 * @property int         $status
 * @property string      $subject
 * @property array       $to_addr
 * @property array       $details
 * @property array       $debug_info
 * @property int         $retry_count
 * @property string      $connection
 * @property string      $connection_id
 * @property string      $message_id
 * @property string      $tracking_id
 * @property string      $delivery_status
 * @property string      $delivery_updated_at
 * @property null|string $source_plugin
 * @property null|string $routing_type
 * @property null|int    $routing_rule_index
 * @property null|string $subject_pattern
 * @property null|int    $recipient_count
 * @property string      $created_at
 * @property null|string $created_at_utc
 * @property string      $updated_at
 */
class Log extends Model
{
    public const SUCCESS = 1;

    public const ERROR   = 0;

    public $casts = [
        'status'              => 'int',
        'subject'             => 'string',
        'to_addr'             => 'array',
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
        'subject_pattern'     => 'string',
        'recipient_count'     => 'int',
        'created_at'          => 'string',
        'created_at_utc'      => 'string',
        'updated_at'          => 'string',
    ];

    protected $fillable = [
        'status',
        'message',
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
        'subject_pattern',
        'recipient_count',
        'created_at_utc',
    ];

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
}
