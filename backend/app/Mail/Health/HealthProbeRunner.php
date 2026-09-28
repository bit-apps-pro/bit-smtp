<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;

/**
 * Cron entry point: probe live connections, fold results, evaluate OAuth expiry, alert on transitions.
 */
class HealthProbeRunner
{
    /**
     * Standalone option (autoload off): unix time of last scheduled probe run (on-demand "Check now" doesn't shift schedule).
     */
    public const LAST_RUN_OPTION = 'bit_smtp_health_last_probe_run';

    private MailConfigService $config;

    private HealthProbeResolver $resolver;

    private ConnectionHealthService $health;

    private ?HealthNotifier $notifier;

    public function __construct(
        MailConfigService $config,
        HealthProbeResolver $resolver,
        ConnectionHealthService $health,
        ?HealthNotifier $notifier = null
    ) {
        $this->config   = $config;
        $this->resolver = $resolver;
        $this->health   = $health;
        $this->notifier = $notifier;
    }

    /**
     * Probe live connections, record results, evaluate OAuth expiry, collect transitions (doesn't stamp LAST_RUN_OPTION).
     *
     * @return HealthTransition[]
     */
    public function run(): array
    {
        $transitions = [];
        foreach ($this->config->load()->getConnections()->all() as $connection) {
            $probe = $this->resolver->probeFor($connection);
            if ($probe !== null) {
                $transition = $this->health->recordProbe($connection, $probe->probe($connection));
                if ($transition !== null) {
                    $transitions[] = $transition;
                }
            }

            if ($this->notifier !== null) {
                $this->notifier->notifyOauthExpiry($connection);
            }
        }

        if ($this->notifier !== null) {
            $this->notifier->notifyTransitions($transitions);
        }

        return $transitions;
    }
}
