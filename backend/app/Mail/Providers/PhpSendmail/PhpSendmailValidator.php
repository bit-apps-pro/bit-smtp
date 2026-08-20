<?php

namespace BitApps\SMTP\Mail\Providers\PhpSendmail;

use BitApps\SMTP\Mail\Contracts\ValidatorInterface;

class PhpSendmailValidator implements ValidatorInterface
{
    public function validate(array $settings, array $credentials): array
    {
        return [];
    }
}
