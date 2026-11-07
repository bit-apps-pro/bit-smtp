<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Message;

use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 *
 * @coversNothing
 */
class MailMessageTest extends BaseUnitTestCase
{
    public function testFullArrayRoundTrip(): void
    {
        $data = [
            'to'          => ['user@example.com'],
            'cc'          => ['cc@example.com'],
            'bcc'         => ['bcc@example.com'],
            'subject'     => 'Test Subject',
            'body'        => 'Test Body',
            'contentType' => 'text/html',
            'from'        => 'sender@example.com',
            'fromName'    => 'Sender Name',
            'replyTo'     => 'reply@example.com',
            'headers'     => ['X-Custom' => 'value'],
            'attachments' => [
                ['path' => '/tmp/file.txt', 'name' => 'file.txt'],
            ],
            'templateId'    => 'template-123',
            'templateData'  => ['var1' => 'value1'],
            'tags'          => ['tag1', 'tag2'],
            'metadata'      => ['key' => 'value'],
            'openTracking'  => true,
            'clickTracking' => false,
        ];

        $message = MailMessage::fromArray($data);
        $result  = $message->toArray();

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
            'to'          => ['user@example.com'],
            'subject'     => 'Test Subject',
            'body'        => 'Test Body',
            'contentType' => 'text/plain',
        ];

        $message = MailMessage::fromArray($data);
        $result  = $message->toArray();

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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/to/');

        MailMessage::fromArray([
            'to'      => [],
            'subject' => 'Test',
            'body'    => 'Body',
        ]);
    }

    public function testFromArrayThrowsWhenToIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/to/');

        MailMessage::fromArray([
            'subject' => 'Test',
            'body'    => 'Body',
        ]);
    }

    public function testFromArrayThrowsWhenSubjectMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/subject/');

        MailMessage::fromArray([
            'to'   => ['user@example.com'],
            'body' => 'Body',
        ]);
    }

    public function testFromArrayThrowsWhenBodyMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/body/');

        MailMessage::fromArray([
            'to'      => ['user@example.com'],
            'subject' => 'Test',
        ]);
    }

    public function testCharsetRoundTripsAndDefaultsToUtf8(): void
    {
        $minimal = ['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B'];

        $this->assertSame('UTF-8', MailMessage::fromArray($minimal)->getCharset());

        $withCharset = MailMessage::fromArray($minimal + ['charset' => 'ISO-8859-2']);
        $this->assertSame('ISO-8859-2', $withCharset->getCharset());
        $this->assertSame('ISO-8859-2', $withCharset->toArray()['charset']);
        $this->assertSame('ISO-8859-2', MailMessage::fromArray($withCharset->toArray())->getCharset());
    }

    public function testRetryRowStoredWithoutCharsetRebuildsAsHtmlUtf8(): void
    {
        $row = json_decode('{"to":["a@example.com"],"subject":"S","body":"<p>B</p>","contentType":"text\/html; charset=UTF-8","from":"f@example.com","fromName":"F","replyTo":null,"headers":[],"attachments":[]}', true);

        $message = MailMessage::fromArray($row);

        $this->assertSame('text/html', $message->getContentType());
        $this->assertTrue($message->isHtml());
        $this->assertSame('UTF-8', $message->getCharset());
    }

    public function testRowWithoutCharsetFieldKeepsTheCharsetOfItsContentType(): void
    {
        $message = MailMessage::fromArray([
            'to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B', 'contentType' => 'text/html; charset=windows-1250',
        ]);

        $this->assertSame('windows-1250', $message->getCharset());
    }

    public function testExplicitCharsetBeatsTheContentTypeCharset(): void
    {
        $message = MailMessage::fromArray([
            'to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B', 'contentType' => 'text/html; charset=windows-1250', 'charset' => 'UTF-8',
        ]);

        $this->assertSame('UTF-8', $message->getCharset());
    }

    public function testUnsafeStoredCharsetCannotReachTheMailer(): void
    {
        $message = MailMessage::fromArray([
            'to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B', 'charset' => "UTF-8\r\nBcc: evil@evil.test",
        ]);

        $this->assertSame('UTF-8', $message->getCharset());
    }

    public function testStoredBareMultipartWithHtmlBodyIsHtml(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => '<p>Order</p>', 'contentType' => 'multipart/alternative']);

        $this->assertSame('text/html', $message->getContentType());
        $this->assertTrue($message->isHtml());
        $this->assertFalse($message->isMultipart());
        $this->assertSame('UTF-8', $message->getCharset());
    }

    public function testStoredBareMultipartWithPlainBodyIsPlain(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'if a<b and c>d', 'contentType' => 'multipart/alternative']);

        $this->assertSame('text/plain', $message->getContentType());
    }

    public function testBareMultipartKeepsItsDeclaredCharset(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => '<p>x</p>', 'contentType' => 'multipart/alternative; charset=ISO-8859-2']);

        $this->assertTrue($message->isHtml());
        $this->assertSame('ISO-8859-2', $message->getCharset());
    }

    public function testRealMultipartBodyWithTagsStaysMultipart(): void
    {
        $message = MailMessage::fromArray(['to' => ['a@example.com'], 'subject' => 'S', 'body' => '<p>x</p>', 'contentType' => 'multipart/alternative; boundary="b"']);

        $this->assertTrue($message->isMultipart());
    }

    public function testConstructorNormalizesContentType(): void
    {
        $base = ['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B'];

        $this->assertSame('text/html', MailMessage::fromArray($base + ['contentType' => 'TEXT/HTML; charset=utf-8'])->getContentType());
        $this->assertSame('text/plain', MailMessage::fromArray($base + ['contentType' => 'text/plain; format=flowed'])->getContentType());
        $this->assertSame('text/calendar; method=REQUEST', MailMessage::fromArray($base + ['contentType' => 'text/calendar; method=REQUEST'])->getContentType());
        $this->assertSame('multipart/alternative; boundary="x"', MailMessage::fromArray($base + ['contentType' => 'multipart/alternative; boundary=x'])->getContentType());
        $this->assertSame('text/plain', MailMessage::fromArray($base + ['contentType' => "text/html\r\nBcc: evil@evil.test"])->getContentType());
    }

    public function testTypePredicatesAndAccessors(): void
    {
        $base = ['to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B'];

        $html      = MailMessage::fromArray($base + ['contentType' => 'text/html']);
        $multipart = MailMessage::fromArray($base + ['contentType' => 'multipart/mixed; boundary="x"']);
        $calendar  = MailMessage::fromArray($base + ['contentType' => 'text/calendar; method=REQUEST']);

        $this->assertTrue($html->isHtml());
        $this->assertFalse($html->isMultipart());
        $this->assertFalse($multipart->isHtml());
        $this->assertTrue($multipart->isMultipart());
        $this->assertSame('x', $multipart->getBoundary());
        $this->assertSame('text/calendar', $calendar->getMediaType());
        $this->assertFalse($calendar->isHtml());
        $this->assertFalse($calendar->isMultipart());
    }

    public function testArrayMergeRebuildKeepsCharsetAndContentType(): void
    {
        $message = MailMessage::fromArray([
            'to' => ['a@example.com'], 'subject' => 'S', 'body' => 'B', 'contentType' => 'multipart/mixed; boundary="x"', 'charset' => 'ISO-8859-2',
        ]);

        $rebuilt = MailMessage::fromArray(array_merge($message->toArray(), ['from' => 'f@example.com']));

        $this->assertSame('multipart/mixed; boundary="x"', $rebuilt->getContentType());
        $this->assertSame('ISO-8859-2', $rebuilt->getCharset());
    }
}
