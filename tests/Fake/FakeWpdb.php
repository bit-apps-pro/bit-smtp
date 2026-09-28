<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Fake;

/**
 * Stand-in for $wpdb that records SQL calls without executing anything.
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
     * @var array<int,string> Raw prepare() templates before substitution, in call order.
     */
    public $preparedTemplates = [];

    /**
     * Records SQL calls without executing anything.
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
     * Substitutes %d/%s/%f/%F placeholders with bound values, prioritizing legibility over escaping fidelity.
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
     * Escapes LIKE wildcard characters so bound values round-trip unchanged.
     *
     * @param mixed $text
     */
    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    /**
     * Stubbed for query-builder read paths; typically unused (LogService reads last_result directly).
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
