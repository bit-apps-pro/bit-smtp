<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Tests\BaseUnitTestCase;

class MailMessageTest extends BaseUnitTestCase
{
    public function testFullArrayRoundTrip(): void
    {
        $data = [
            'to' => ['user@example.com'],
            'cc' => ['cc@example.com'],
            'bcc' => ['bcc@example.com'],
            'subject' => 'Test Subject',
            'body' => 'Test Body',
            'contentType' => 'text/html',
            'from' => 'sender@example.com',
            'fromName' => 'Sender Name',
            'replyTo' => 'reply@example.com',
            'headers' => ['X-Custom' => 'value'],
            'attachments' => [
                ['path' => '/tmp/file.txt', 'name' => 'file.txt'],
            ],
            'templateId' => 'template-123',
            'templateData' => ['var1' => 'value1'],
            'tags' => ['tag1', 'tag2'],
            'metadata' => ['key' => 'value'],
            'openTracking' => true,
            'clickTracking' => false,
        ];

        $message = MailMessage::fromArray($data);
        $result = $message->toArray();

        $this->assertSame($data['to'], $result['to']);
        $this->assertSame($data['cc'], $result['cc']);
        $this->assertSame($data['bcc'], $result['bcc']);
        $this->assertSame($data['subject'], $result['subject']);
        $this->assertSame($data['body'], $result['body']);
        $this->assertSame($data['contentType'], $result['contentType']);
        $this->assertSame($data['from'], $result['from']);
        $this->assertSame($data['fromName'], $result['fromName']);
        $this->assertSame($data['replyTo'], $result['replyTo']);
        $this->assertSame($data['headers'], $result['headers']);
        $this->assertSame($data['attachments'], $result['attachments']);
        $this->assertSame($data['templateId'], $result['templateId']);
        $this->assertSame($data['templateData'], $result['templateData']);
        $this->assertSame($data['tags'], $result['tags']);
        $this->assertSame($data['metadata'], $result['metadata']);
        $this->assertSame($data['openTracking'], $result['openTracking']);
        $this->assertSame($data['clickTracking'], $result['clickTracking']);
    }

    public function testOptionalFieldsDefaultToEmptyOrNull(): void
    {
        $data = [
            'to' => ['user@example.com'],
            'subject' => 'Test Subject',
            'body' => 'Test Body',
            'contentType' => 'text/plain',
        ];

        $message = MailMessage::fromArray($data);
        $result = $message->toArray();

        $this->assertSame([], $result['cc']);
        $this->assertSame([], $result['bcc']);
        $this->assertNull($result['from']);
        $this->assertNull($result['fromName']);
        $this->assertNull($result['replyTo']);
        $this->assertSame([], $result['headers']);
        $this->assertSame([], $result['attachments']);
        $this->assertNull($result['templateId']);
        $this->assertSame([], $result['templateData']);
        $this->assertSame([], $result['tags']);
        $this->assertSame([], $result['metadata']);
        $this->assertNull($result['openTracking']);
        $this->assertNull($result['clickTracking']);
    }

    public function testFromArrayThrowsWhenToIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/to/');

        MailMessage::fromArray([
            'to'      => [],
            'subject' => 'Test',
            'body'    => 'Body',
        ]);
    }

    public function testFromArrayThrowsWhenToIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/to/');

        MailMessage::fromArray([
            'subject' => 'Test',
            'body'    => 'Body',
        ]);
    }

    public function testFromArrayThrowsWhenSubjectMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/subject/');

        MailMessage::fromArray([
            'to'   => ['user@example.com'],
            'body' => 'Body',
        ]);
    }

    public function testFromArrayThrowsWhenBodyMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/body/');

        MailMessage::fromArray([
            'to'      => ['user@example.com'],
            'subject' => 'Test',
        ]);
    }
}
