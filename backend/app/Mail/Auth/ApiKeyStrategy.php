<?php

namespace BitApps\SMTP\Mail\Auth;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\ApiRequest;

/**
 * Signs requests with a provider-specific header name and an interpolated value template.
 */
final class ApiKeyStrategy extends AbstractAuthStrategy
{
    public function apply(ApiRequest $request, Connection $connection): void
    {
        $request->setHeader(
            $this->config['headerName'],
            $this->interpolate($this->config['valueTemplate'], $connection)
        );
    }

    public function type(): string
    {
        return 'api_key';
    }
}
