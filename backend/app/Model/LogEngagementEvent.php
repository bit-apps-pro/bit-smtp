<?php

namespace BitApps\SMTP\Model;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;

/**
 * Model for a single open/click engagement event folded against a log entry. One row per
 * (log_id, type, target); re-fires increment hits/automated_hits rather than inserting duplicates.
 *
 * @property int    $log_id
 * @property string $type
 * @property string $target
 * @property int    $hits
 * @property int    $automated_hits
 * @property string $first_at
 * @property string $last_at
 * @property string $event_key
 * @property string $created_at
 */
class LogEngagementEvent extends Model
{
    /**
     * @var array<string, string>
     */
    public $casts = [
        'log_id'         => 'int',
        'type'           => 'string',
        'target'         => 'string',
        'hits'           => 'int',
        'automated_hits' => 'int',
        'first_at'       => 'string',
        'last_at'        => 'string',
        'event_key'      => 'string',
        'created_at'     => 'string',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'log_id',
        'type',
        'target',
        'hits',
        'automated_hits',
        'first_at',
        'last_at',
        'event_key',
    ];
}
