<?php

namespace BitApps\SMTP\Mail\Contracts;

interface ValidatorInterface
{
    /**
     * @return array<string,string> Map of field => error message; empty array means valid.
     */
    public function validate(array $settings, array $credentials): array;
}
