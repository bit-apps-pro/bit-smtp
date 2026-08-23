<?php

namespace BitApps\SMTP\HTTP\Requests\Rules;

use BitApps\SMTP\Deps\BitApps\WPValidator\Rule;

/**
 * wp-validator rule that accepts a value only if it strictly matches one of a fixed set of choices;
 * used for schema-driven enum fields, since wp-validator ships no built-in "in" rule.
 */
final class InRule extends Rule
{
    /**
     * @param array<int,mixed> $choices
     */
    public function __construct(private array $choices)
    {
    }

    /**
     * @param mixed $value
     */
    public function validate($value): bool
    {
        return \in_array($value, $this->choices, true);
    }

    /**
     * Failure message; ":attribute" is filled in by the validator's ErrorBag.
     */
    public function message()
    {
        return \sprintf('The :attribute must be one of: %s', implode(', ', $this->choices));
    }
}
