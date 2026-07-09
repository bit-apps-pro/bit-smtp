<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\Mail\Dispatch\SendContext;
use BitApps\SMTP\Tests\BaseUnitTestCase;

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
        $this->assertSame([], $this->context->getDebugOutput());
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
        $this->context->setBatch(true);

        $this->context->resetForSend();

        $this->assertTrue($this->context->isDebug());
        $this->assertTrue($this->context->isRetrying());
        $this->assertSame(42, $this->context->getRetryLogId());
        $this->assertTrue($this->context->isBatch());
    }

    public function testMutatorsAreChainable(): void
    {
        $result = $this->context
            ->setDebug(true)
            ->setFailed(true)
            ->setRetrying(true)
            ->setRetryLogId(7)
            ->setBatch(true);

        $this->assertSame($this->context, $result);
    }
}
