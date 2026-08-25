<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Mail\Health\ConnectionHealth;
use BitApps\SMTP\Mail\Health\ConnectionHealthService;
use BitApps\SMTP\Mail\Health\HealthProbeRunner;
use BitApps\SMTP\Plugin;

/**
 * Read/refresh surface for per-connection health, backing the Connections UI badges.
 */
final class ConnectionHealthController
{
    private ?ConnectionHealthService $service;

    private ?HealthProbeRunner $runner;

    public function __construct(?ConnectionHealthService $service = null, ?HealthProbeRunner $runner = null)
    {
        $this->service = $service;
        $this->runner  = $runner;
    }

    /**
     * Current health for every live connection as a public, secret-free map keyed by connection id.
     */
    public function index(Request $request): Response
    {
        return Response::success(['health' => $this->publicHealth()]);
    }

    /**
     * Actively probe every live connection on demand ("Check now"), then return the refreshed map.
     */
    public function check(Request $request): Response
    {
        $this->runner()->run();

        return Response::success(['health' => $this->publicHealth()]);
    }

    /**
     * The live health map reduced to its public shape, never exposing internal alert bookkeeping.
     *
     * @return array<string,array<string,mixed>>
     */
    private function publicHealth(): array
    {
        return array_map(
            static fn (ConnectionHealth $health): array => $health->toPublicArray(),
            $this->service()->list()
        );
    }

    /**
     * Lazily resolve the shared ConnectionHealthService singleton, honoring a constructor-injected fake.
     */
    private function service(): ConnectionHealthService
    {
        return $this->service ??= Plugin::instance()->app()->make(ConnectionHealthService::class);
    }

    /**
     * Lazily resolve the shared HealthProbeRunner singleton, honoring a constructor-injected fake.
     */
    private function runner(): HealthProbeRunner
    {
        return $this->runner ??= Plugin::instance()->app()->make(HealthProbeRunner::class);
    }
}
