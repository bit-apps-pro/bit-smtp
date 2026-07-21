<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SendResultTest extends BaseUnitTestCase
{
    public function testSuccessWithDebug(): void
    {
        $debugInfo = ['msg_id' => '123'];
        $result    = SendResult::success($debugInfo);

        $this->assertTrue($result->isOk());
        $this->assertNull($result->getCode());
        $this->assertNull($result->getError());
        $this->assertSame($debugInfo, $result->getDebug());
    }

    public function testSuccessWithoutDebug(): void
    {
        $result = SendResult::success();

        $this->assertTrue($result->isOk());
        $this->assertSame([], $result->getDebug());
    }

    public function testSuccessIsAccepted(): void
    {
        $this->assertTrue(SendResult::success()->isAccepted());
    }

    public function testFailureWithCode(): void
    {
        $debugInfo = ['host' => 'smtp.example.com'];
        $result    = SendResult::failure('Connection refused', 'CONN_ERR', $debugInfo);

        $this->assertFalse($result->isOk());
        $this->assertSame('Connection refused', $result->getError());
        $this->assertSame('CONN_ERR', $result->getCode());
        $this->assertSame($debugInfo, $result->getDebug());
    }

    public function testFailureWithoutCode(): void
    {
        $result = SendResult::failure('Bad credentials');

        $this->assertFalse($result->isOk());
        $this->assertSame('Bad credentials', $result->getError());
        $this->assertNull($result->getCode());
        $this->assertSame([], $result->getDebug());
    }

    public function testFailureIsNotAccepted(): void
    {
        // Not handed off by the provider: fallback to the next connection must be allowed.
        $this->assertFalse(SendResult::failure('Connection refused')->isAccepted());
    }

    public function testAcceptedWithErrorIsAcceptedButNotOk(): void
    {
        // Handed off (e.g. HTTP 2xx) but the provider reported a per-message error in the body:
        // no fallback (would duplicate-send), yet reported as a failed attempt.
        $debugInfo = ['status' => 200, 'body' => ['errors' => ['Recipient rejected']]];
        $result    = SendResult::acceptedWithError('Recipient rejected', '200', $debugInfo);

        $this->assertTrue($result->isAccepted());
        $this->assertFalse($result->isOk());
        $this->assertSame('Recipient rejected', $result->getError());
        $this->assertSame('200', $result->getCode());
        $this->assertSame($debugInfo, $result->getDebug());
    }

    public function testAcceptedWithErrorWithoutCode(): void
    {
        $result = SendResult::acceptedWithError('Partial failure');

        $this->assertTrue($result->isAccepted());
        $this->assertFalse($result->isOk());
        $this->assertNull($result->getCode());
        $this->assertSame([], $result->getDebug());
    }

    public function testMessageIdDefaultsToNull(): void
    {
        $this->assertNull(SendResult::success()->getMessageId());
    }

    public function testWithMessageIdCarriesTheMessageId(): void
    {
        $this->assertSame('m1', SendResult::success()->withMessageId('m1')->getMessageId());
    }

    public function testWithMessageIdIsImmutableAndLeavesTheOriginalUntouched(): void
    {
        $original = SendResult::success();
        $stamped  = $original->withMessageId('m1');

        $this->assertNotSame($original, $stamped);
        $this->assertNull($original->getMessageId());
    }

    public function testWithMessageIdPreservesEveryFailureDimension(): void
    {
        $debugInfo = ['d' => 1];
        $stamped   = SendResult::failure('e', '500', $debugInfo)->withMessageId('m1');

        $this->assertSame('m1', $stamped->getMessageId());
        $this->assertFalse($stamped->isAccepted());
        $this->assertFalse($stamped->isOk());
        $this->assertSame('500', $stamped->getCode());
        $this->assertSame('e', $stamped->getError());
        $this->assertSame($debugInfo, $stamped->getDebug());
    }

    public function testWithMessageIdPreservesEverySuccessDimension(): void
    {
        $debugInfo = ['x' => 1];
        $stamped   = SendResult::success($debugInfo)->withMessageId('m1');

        $this->assertSame('m1', $stamped->getMessageId());
        $this->assertTrue($stamped->isAccepted());
        $this->assertTrue($stamped->isOk());
        $this->assertNull($stamped->getCode());
        $this->assertNull($stamped->getError());
        $this->assertSame($debugInfo, $stamped->getDebug());
    }
}
