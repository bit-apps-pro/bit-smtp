<?php

namespace BitApps\SMTP\Model;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;

/**
 * A row of the WordPress core `options` table, for writes that core's option API cannot make atomic.
 *
 * @property int    $option_id
 * @property string $option_name
 * @property string $option_value
 * @property string $autoload
 */
final class WpOption extends Model
{
    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    protected $table = 'options';

    /**
     * @var string
     */
    protected $primaryKey = 'option_id';

    /**
     * @var list<string>
     */
    protected $fillable = ['option_name', 'option_value', 'autoload'];

    /**
     * The site's bare WordPress table prefix, since `options` is a core table rather than a plugin one.
     */
    public function getTablePrefix()
    {
        return Connection::wpPrefix();
    }
}
