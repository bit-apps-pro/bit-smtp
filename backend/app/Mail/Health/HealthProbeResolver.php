<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\Contracts\ConnectionProbeInterface;
use BitApps\SMTP\Mail\Health\Probes\SmtpConnectionProbe;

/**
 * Picks the active probe for a connection kind. Only `smtp` gets a real probe; `local` and `api` are
 * covered passively from real send outcomes (probing a local sendmail is meaningless, and an API
 * probe is a per-provider concern). This is the seam where a future API probe would register.
 */
class HealthProbeResolver
{
    private SmtpConnectionProbe $smtpProbe;

    public function __construct(SmtpConnectionProbe $smtpProbe)
    {
        $this->smtpProbe = $smtpProbe;
    }

    /**
     * The active probe for a connection, or null when its kind is covered passively only.
     */
    public function probeFor(Connection $connection): ?ConnectionProbeInterface
    {
        return $connection->getKind() === 'smtp' ? $this->smtpProbe : null;
    }
}
