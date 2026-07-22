<?php

namespace BitApps\SMTP\Model;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;

/**
 * Model for a single provider delivery event against a log entry
 *
 * @property int    $log_id
 * @property string $recipient
 * @property string $status
 * @property int    $terminal
 * @property string $detail
 * @property string $occurred_at
 * @property string $event_hash
 * @property string $created_at
 */
class LogDeliveryEvent extends Model
{
    public $casts = [
        'log_id'      => 'int',
        'recipient'   => 'string',
        'status'      => 'string',
        'terminal'    => 'int',
        'detail'      => 'string',
        'occurred_at' => 'string',
        'event_hash'  => 'string',
        'created_at'  => 'string',
    ];

    protected $fillable = [
        'log_id',
        'recipient',
        'status',
        'terminal',
        'detail',
        'occurred_at',
        'event_hash',
    ];
}
