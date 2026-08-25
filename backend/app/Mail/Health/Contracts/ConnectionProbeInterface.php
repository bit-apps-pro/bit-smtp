<?php

namespace BitApps\SMTP\Mail\Health\Contracts;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\ProbeResult;

interface ConnectionProbeInterface
{
    /**
     * Actively check a connection's liveness without sending a message; must never throw.
     */
    public function probe(Connection $connection): ProbeResult;
}
