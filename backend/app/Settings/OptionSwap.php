<?php

namespace BitApps\SMTP\Settings;

use BitApps\SMTP\Deps\BitApps\WPDatabase\QueryBuilder;
use BitApps\SMTP\Model\WpOption;

/**
 * Compare-and-swap writes for option row (BINARY byte-for-byte comparison blocks case-insensitive collation).
 */
final class OptionSwap
{
    public const AUTOLOAD = 'yes';

    public const NO_AUTOLOAD = 'no';

    /**
     * The option's raw (serialized) value read straight from the table, or null when the row is absent.
     */
    public static function read(string $optionName): ?string
    {
        $row = WpOption::query()->where('option_name', $optionName)->first();

        return $row instanceof WpOption && \is_string($row->option_value) ? $row->option_value : null;
    }

    /**
     * Create row holding $raw (false if exists; INSERT IGNORE lets unique option_name elect one creator).
     */
    public static function create(string $optionName, string $raw, string $autoload = self::AUTOLOAD): bool
    {
        $inserted = WpOption::query()->insertOrIgnore([
            'option_name'  => $optionName,
            'option_value' => $raw,
            'autoload'     => $autoload,
        ]);

        return self::settle($optionName, $inserted);
    }

    /**
     * Overwrite the row with $nextRaw only while it still holds exactly $expectedRaw.
     */
    public static function replace(string $optionName, string $expectedRaw, string $nextRaw): bool
    {
        return self::settle($optionName, self::holding($optionName, $expectedRaw)->update(['option_value' => $nextRaw]));
    }

    /**
     * Remove the row only while it still holds exactly $expectedRaw.
     */
    public static function delete(string $optionName, string $expectedRaw): bool
    {
        return self::settle($optionName, self::holding($optionName, $expectedRaw)->delete());
    }

    /**
     * A query scoped to the option row, matching only while its value is byte-for-byte $expectedRaw.
     */
    private static function holding(string $optionName, string $expectedRaw): QueryBuilder
    {
        return WpOption::query()
            ->where('option_name', $optionName)
            ->whereRaw('option_value = BINARY %s', [$expectedRaw]);
    }

    /**
     * Invalidate all cached copies of this option (alloptions, notoptions) and report if exactly one row changed.
     *
     * @param mixed $affectedRows
     */
    private static function settle(string $optionName, $affectedRows): bool
    {
        wp_cache_delete($optionName, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        return $affectedRows === 1;
    }
}
