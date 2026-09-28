<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Health\Contracts\ConnectionProbeInterface;
use BitApps\SMTP\Mail\Health\Probes\SmtpConnectionProbe;

/**
 * Pick active probe per connection kind; smtp gets real probe, local/api passive (future API seam).
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
