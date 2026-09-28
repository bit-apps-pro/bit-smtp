<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications;

use BitApps\SMTP\Mail\Notifications\FailureIncident;
use BitApps\SMTP\Mail\Notifications\FailureIncidentStore;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use BitApps\SMTP\Tests\Fake\InMemoryFailureNotificationGate;
use BitApps\SMTP\Tests\Fake\LosingFailureNotificationGate;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * An in-memory gate that lets another "request" write the row once, right after this one read it.
 */
final class InterleavingFailureNotificationGate extends InMemoryFailureNotificationGate
{
    /**
     * @var null|callable(self):void
     */
    public $interleave;

    /**
     * Read the state, then run the pending interleaved write exactly once.
     */
    public function read(): ?string
    {
        $state = parent::read();
        if ($this->interleave !== null) {
            $interleave       = $this->interleave;
            $this->interleave = null;
            $interleave($this);
        }

        return $state;
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureIncidentStoreTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function testConcurrentKeepsOfResolvedIncidentsAreBothRetained(): void
    {
        $resolvedGate             = new InterleavingFailureNotificationGate();
        $store                    = $this->store($resolvedGate);
        $resolvedGate->interleave = function () use ($store): void {
            $store->keepResolved($this->incident('incident-a'));
        };

        $store->keepResolved($this->incident('incident-b'));

        $this->assertNotNull($store->resolved('incident-a'));
        $this->assertNotNull($store->resolved('incident-b'));
    }

    public function testAForgetRacingAKeepLosesNeitherChange(): void
    {
        $resolvedGate = new InterleavingFailureNotificationGate();
        $store        = $this->store($resolvedGate);
        $store->keepResolved($this->incident('incident-a'));
        $resolvedGate->interleave = function () use ($store): void {
            $store->keepResolved($this->incident('incident-b'));
        };

        $store->forgetResolved('incident-a');

        $this->assertNull($store->resolved('incident-a'));
        $this->assertNotNull($store->resolved('incident-b'));
    }

    public function testKeepResolvedReportsFailureOnceEveryCompareAndSwapLost(): void
    {
        $store = $this->store(new LosingFailureNotificationGate());

        $this->assertFalse($store->keepResolved($this->incident('incident-a')));
        $this->assertNull($store->resolved('incident-a'));
    }

    public function testForgettingTheLastResolvedIncidentRemovesTheRow(): void
    {
        $resolvedGate = new InMemoryFailureNotificationGate();
        $store        = $this->store($resolvedGate);
        $store->keepResolved($this->incident('incident-a'));

        $store->forgetResolved('incident-a');

        $this->assertNull($resolvedGate->state);
    }

    public function testOnlyTheNewestResolvedIncidentsAreKept(): void
    {
        $store = $this->store(new InMemoryFailureNotificationGate());
        for ($i = 1; $i <= 11; ++$i) {
            $store->keepResolved($this->incident("incident-{$i}"));
        }

        $this->assertNull($store->resolved('incident-1'));
        $this->assertNotNull($store->resolved('incident-11'));
    }

    /**
     * A store whose resolved map lives in the given gate.
     */
    private function store(InMemoryFailureNotificationGate $resolvedGate): FailureIncidentStore
    {
        return new FailureIncidentStore(new InMemoryFailureNotificationGate(), $resolvedGate);
    }

    /**
     * A closed, resolved incident with the given id.
     */
    private function incident(string $id): FailureIncident
    {
        return FailureIncident::start($id, 1700000000, new WP_Error('wp_mail_failed', 'Connection refused'), null)
            ->withResolution(1700000060);
    }
}
