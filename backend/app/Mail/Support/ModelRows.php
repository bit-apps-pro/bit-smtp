<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Support;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Collection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Model;

/**
 * Normalizes a WPDatabase QueryBuilder get() result to a plain list of rows, model-agnostically.
 */
final class ModelRows
{
    /**
     * Flatten a get() result to a list: a Collection unwraps via all(), a lone Model (returned when
     * the limit collapses to one row) becomes a one-row list, a bare array passes through, and any
     * other shape yields an empty list.
     *
     * @return array<int,mixed>
     */
    public static function toArray(mixed $result): array
    {
        if ($result instanceof Collection) {
            return $result->all();
        }

        if ($result instanceof Model) {
            return [$result];
        }

        return \is_array($result) ? $result : [];
    }
}
