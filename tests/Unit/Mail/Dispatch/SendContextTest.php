<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\Mail\Dispatch\SendContext;
use BitApps\SMTP\Mail\Routing\RoutingDecision;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SendContextTest extends BaseUnitTestCase
{
    private SendContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new SendContext();
    }

    public function testDefaultsAreCleanState(): void
    {
        $this->assertFalse($this->context->isDebug());
        $this->assertFalse($this->context->isFailed());
        $this->assertFalse($this->context->isRetrying());
        $this->assertFalse($this->context->isBatch());
        $this->assertSame(0, $this->context->getRetryLogId());
        $this->assertNull($this->context->getResendParentId());
        $this->assertSame([], $this->context->getDebugOutput());
        $this->assertNull($this->context->getRoutingDecision());
    }

    public function testResendParentIdIsSetReadAndCleared(): void
    {
        $this->context->setResendParentId(42);
        $this->assertSame(42, $this->context->getResendParentId());

        $this->context->setResendParentId(null);
        $this->assertNull($this->context->getResendParentId());
    }

    public function testAppendDebugAccumulatesLines(): void
    {
        $this->context->appendDebug("first\n");
        $this->context->appendDebug("second\n");

        $this->assertSame(["first\n", "second\n"], $this->context->getDebugOutput());
    }

    public function testResetForSendClearsOutputAccumulators(): void
    {
        $this->context->appendDebug("stale\n");
        $this->context->setFailed(true);

        $this->context->resetForSend();

        $this->assertSame([], $this->context->getDebugOutput());
        $this->assertFalse($this->context->isFailed());
    }

    public function testResetForSendPreservesCallerSetInputs(): void
    {
        $this->context->setDebug(true);
        $this->context->setRetrying(true);
        $this->context->setRetryLogId(42);
        $this->context->setResendParentId(7);
        $this->context->setBatch(true);

        $this->context->resetForSend();

        $this->assertTrue($this->context->isDebug());
        $this->assertTrue($this->context->isRetrying());
        $this->assertSame(42, $this->context->getRetryLogId());
        // resetForSend runs before dispatch reads the parent id, so it must survive the reset; the
        // logger clears it once the resend row is written.
        $this->assertSame(7, $this->context->getResendParentId());
        $this->assertTrue($this->context->isBatch());
    }

    public function testResetForSendClearsThePreviousRoutingDecision(): void
    {
        $decision = new RoutingDecision('woocommerce', 'conn_primary', 'rule', 2);
        $this->context->setRoutingDecision($decision);

        $this->assertSame($decision, $this->context->getRoutingDecision());

        $this->context->resetForSend();

        $this->assertNull($this->context->getRoutingDecision());
    }

    public function testMutatorsAreChainable(): void
    {
        $result = $this->context
            ->setDebug(true)
            ->setFailed(true)
            ->setRetrying(true)
            ->setRetryLogId(7)
            ->setResendParentId(3)
            ->setBatch(true);

        $this->assertSame($this->context, $result);
    }
}
