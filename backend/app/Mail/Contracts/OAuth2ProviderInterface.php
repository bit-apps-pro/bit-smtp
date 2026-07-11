<?php

namespace BitApps\SMTP\Mail\Contracts;

interface OAuth2ProviderInterface
{
    public function authUrl(): string;

    public function tokenUrl(): string;

    /**
     * @return string[]
     */
    public function scopes(): array;

    public function sendEndpoint(): string;
}
