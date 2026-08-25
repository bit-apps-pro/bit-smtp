<?php

namespace BitApps\SMTP\HTTP\Requests\Rules;

use BitApps\SMTP\Deps\BitApps\WPValidator\Rule;

/**
 * wp-validator rule that accepts a value only if it is an array whose every element strictly matches
 * one of a fixed set of choices; the array counterpart to InRule for schema-driven enum-set fields.
 */
final class EachInRule extends Rule
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
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\in_array($item, $this->choices, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Failure message; ":attribute" is filled in by the validator's ErrorBag.
     */
    public function message(): string
    {
        return \sprintf('The :attribute must contain only: %s', implode(', ', $this->choices));
    }
}
