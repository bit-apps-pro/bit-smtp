<?php

namespace BitApps\SMTP\HTTP\Requests\Rules;

use BitApps\SMTP\Deps\BitApps\WPValidator\Rule;

/**
 * wp-validator rule accepting only addresses WordPress itself can send to, which is stricter than filter_var.
 */
final class RecipientEmailRule extends Rule
{
    /**
     * @param mixed $value
     */
    public function validate($value): bool
    {
        return \is_string($value) && is_email($value) !== false;
    }

    /**
     * Failure message shown on the recipient field.
     */
    public function message(): string
    {
        return __('Recipient must be a valid email address', 'bit-smtp');
    }
}
