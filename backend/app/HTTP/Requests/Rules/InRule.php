<?php

namespace BitApps\SMTP\HTTP\Requests\Rules;

use BitApps\SMTP\Deps\BitApps\WPValidator\Rule;

/**
 * wp-validator rule accepting values strictly matching fixed choices (enum fields).
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
    public function message(): string
    {
        return \sprintf('The :attribute must be one of: %s', implode(', ', $this->choices));
    }
}
