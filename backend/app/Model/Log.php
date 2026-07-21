<?php

namespace BitApps\SMTP\Model;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;

/**
 * Model for log
 *
 * @property int    $status
 * @property string $subject
 * @property array  $to_addr
 * @property array  $details
 * @property array  $debug_info
 * @property int    $retry_count
 * @property string $connection
 * @property string $created_at
 * @property string $updated_at
 */
class Log extends Model
{
    public const SUCCESS = 1;

    public const ERROR   = 0;

    public $casts = [
        'status'          => 'int',
        'subject'         => 'string',
        'to_addr'         => 'array',
        'details'         => 'array',
        'debug_info'      => 'array',
        'retry_count'     => 'int',
        'connection'      => 'string',
        'created_at'      => 'string',
        'updated_at'      => 'string',
    ];

    protected $fillable = [
        'status',
        'message',
        'details',
        'retry_count',
        'connection',
    ];
}
