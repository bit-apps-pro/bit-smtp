<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\FormEncoder;
use BitApps\SMTP\Mail\Support\JsonEncoder;
use BitApps\SMTP\Mail\Support\MimeRawEncoder;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class EncoderTest extends BaseUnitTestCase
{
    public function testJsonEncoderProducesJsonBodyAndContentType(): void
    {
        $encoded = (new JsonEncoder())->encode(['subject' => 'Hi', 'n' => 1]);

        $this->assertSame('{"subject":"Hi","n":1}', $encoded['body']);
        $this->assertSame('application/json', $encoded['contentType']);
    }

    public function testFormEncoderProducesUrlEncodedBodyAndContentType(): void
    {
        $encoded = (new FormEncoder())->encode(['grant_type' => 'refresh_token', 'scope' => 'a b']);

        $this->assertSame('grant_type=refresh_token&scope=a+b', $encoded['body']);
        $this->assertSame('application/x-www-form-urlencoded', $encoded['contentType']);
    }

    public function testMimeRawEncoderReturnsRawBodyAsIsWithTextPlain(): void
    {
        $raw     = "From: a@b\r\nSubject: Hi\r\n\r\nHello";
        $encoded = (new MimeRawEncoder())->encode(['raw' => $raw]);

        $this->assertSame($raw, $encoded['body']);
        $this->assertSame('text/plain', $encoded['contentType']);
    }
}
