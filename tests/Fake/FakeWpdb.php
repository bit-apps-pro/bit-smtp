<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Fake;

/**
 * Minimal stand-in for $wpdb, modeled on bitapps/wp-database's own
 * tests/bootstrap.php FakeWpdb. BitApps\WPDatabase\Connection forwards every
 * call to $GLOBALS['wpdb'] via __call/__callStatic/prop(), so installing this
 * as $GLOBALS['wpdb'] lets QueryBuilder compile and "execute" SQL with no
 * database — query() and prepare() just record what they were given, so a
 * test can assert on the generated SQL instead of a query result.
 */
class FakeWpdb
{
    public $prefix = 'wp_';

    public $last_query = '';

    public $last_error = '';

    public $last_result = [];

    public $rows_affected = 0;

    public $insert_id = 0;

    public $suppress_errors = false;

    public $prepareCalls = 0;

    /**
     * @var array<int,string> every query string handed to query(), in call order
     */
    public $queries = [];

    /**
     * @var array<int,string> the raw prepare() template (pre-substitution) for each prepare()
     *                        call, in call order — lets a test prove a bound value never reached
     *                        the SQL template itself, only the post-substitution string
     */
    public $preparedTemplates = [];

    /**
     * Records the SQL passed to query() and returns a fake affected-row count; this fake never
     * talks to a database, so nothing is actually executed.
     *
     * @param mixed $sql
     */
    public function query($sql)
    {
        $this->last_query = $sql;
        $this->queries[]  = $sql;

        return $this->rows_affected;
    }

    /**
     * Substitutes %d/%s/%f/%F placeholders with their bound values. Mirrors wpdb::prepare()'s
     * support for either a variadic arg list or a single array argument (Connection::prepare()
     * always calls through with the bindings as one array argument). Values are quoted/unquoted
     * by type, not real-escaped: this fake favors the bound value being legible in the captured
     * SQL over exact wpdb escaping fidelity.
     *
     * @param mixed $query
     */
    public function prepare($query, ...$args)
    {
        $this->prepareCalls++;
        $this->preparedTemplates[] = $query;

        if (\count($args) === 1 && \is_array($args[0])) {
            $args = $args[0];
        }

        $index    = 0;
        $prepared = preg_replace_callback('/%[dsfF]/', static function ($match) use (&$index, $args) {
            $value = $args[$index] ?? '';
            $index++;

            return is_numeric($value) ? (string) $value : "'" . $value . "'";
        }, $query);

        return str_replace('%%', '%', $prepared);
    }

    /**
     * Escapes LIKE wildcard characters exactly as wpdb::esc_like() does, so a bound LIKE value's
     * shape round-trips through this fake unchanged.
     *
     * @param mixed $text
     */
    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    /**
     * Unused by LogService::all()'s query path (which reads last_result directly after query()),
     * stubbed for any other read path the query builder might exercise.
     *
     * @param mixed $query
     */
    public function get_results($query)
    {
        return $this->last_result;
    }

    /**
     * Stubbed for any scalar-aggregate read path the query builder might exercise.
     *
     * @param mixed $query
     */
    public function get_var($query)
    {

    }

    /**
     * Stubbed for any single-row read path the query builder might exercise.
     *
     * @param mixed $query
     */
    public function get_row($query)
    {

    }
}
