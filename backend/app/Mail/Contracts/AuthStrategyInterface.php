<?php

namespace BitApps\SMTP\Mail\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Support\ApiRequest;

interface AuthStrategyInterface
{
    public function apply(ApiRequest $request, Connection $connection): void;

    public function type(): string;
}
