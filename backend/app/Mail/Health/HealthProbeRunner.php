<?php

namespace BitApps\SMTP\Mail\Health;

use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Notifications\HealthNotifier;

/**
 * Cron entry point for active health monitoring: probes every live connection that has an active
 * probe, folds each result into the health engine, evaluates OAuth expiry, and alerts on the
 * resulting transitions. API kinds without a probe are left to passive send recording.
 */
class HealthProbeRunner
{
    /**
     * Standalone option (autoload off) holding the unix time of the last SCHEDULED probe run; the
     * cron callback reads it to gate the configured interval and stamps it after a scheduled run.
     * An on-demand "Check now" calls run() WITHOUT touching this, so it never shifts the schedule.
     * Deleted on uninstall purge.
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
     * Probe each live connection with an active probe, record every result, evaluate OAuth expiry per
     * connection, and collect the health transitions produced. Does NOT stamp LAST_RUN_OPTION — the
     * cron callback owns the interval marker so an on-demand "Check now" can't shift the schedule.
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

            // OAuth expiry is independent of the active probe (API OAuth connections have none), so it
            // is evaluated for every connection each run.
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
