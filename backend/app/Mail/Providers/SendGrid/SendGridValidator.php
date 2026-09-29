<?php

namespace BitApps\SMTP\Mail\Providers\SendGrid;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class SendGridValidator implements ValidatorInterface
{
    public function validate(array $settings, array $credentials): array
    {
        $errors = [];

        if (trim((string) ($credentials['api_key'] ?? '')) === '') {
            $errors['api_key'] = __('API Key is required.', 'bit-smtp');
        }

        return $errors;
    }
}
