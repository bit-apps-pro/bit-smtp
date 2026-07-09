<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class SendResultTest extends BaseUnitTestCase
{
    public function testSuccessWithDebug(): void
    {
        $debugInfo = ['msg_id' => '123'];
        $result = SendResult::success($debugInfo);

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

    public function testFailureWithCode(): void
    {
        $debugInfo = ['host' => 'smtp.example.com'];
        $result = SendResult::failure('Connection refused', 'CONN_ERR', $debugInfo);

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
}
