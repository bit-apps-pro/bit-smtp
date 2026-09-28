<?php

namespace BitApps\SMTP\Model;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;

/**
 * Model for deferred send in retry queue; timestamps are explicit UTC (automatic timestamps disabled).
 *
 * @property int         $id
 * @property null|int    $log_id
 * @property string      $payload
 * @property string      $connection_chain
 * @property int         $attempts
 * @property int         $max_attempts
 * @property null|string $failure_class
 * @property string      $next_attempt_at
 * @property null|string $claim_token
 * @property null|string $locked_at
 * @property string      $created_at
 * @property string      $updated_at
 */
class RetryQueueEntry extends Model
{
    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    public $casts = [
        'log_id'           => 'int',
        'payload'          => 'string',
        'connection_chain' => 'string',
        'attempts'         => 'int',
        'max_attempts'     => 'int',
        'failure_class'    => 'string',
        'next_attempt_at'  => 'string',
        'claim_token'      => 'string',
        'locked_at'        => 'string',
        'created_at'       => 'string',
        'updated_at'       => 'string',
    ];

    /**
     * @var string
     */
    protected $table = 'mail_retry_queue';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'log_id',
        'payload',
        'connection_chain',
        'attempts',
        'max_attempts',
        'failure_class',
        'next_attempt_at',
        'claim_token',
        'locked_at',
        'created_at',
        'updated_at',
    ];
}
