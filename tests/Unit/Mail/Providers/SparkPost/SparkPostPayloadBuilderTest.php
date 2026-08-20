<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Providers\SparkPost;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Providers\SparkPost\SparkPostPayloadBuilder;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * Direct unit coverage of SparkPostPayloadBuilder::build() (spec §5/§11 payloadBuilder escape
 * hatch): SparkPost's transmissions API has no native cc/bcc, so every recipient is flattened into
 * `recipients[]` sharing one visible `header_to`, while only cc (never bcc) is echoed into
 * `content.headers` — that asymmetry is the entire point of the bespoke builder.
 *
 * @internal
 *
 * @coversNothing
 */
class SparkPostPayloadBuilderTest extends BaseUnitTestCase
{
    /**
     * @var string[]
     */
    private $tempFiles = [];

    private SparkPostPayloadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SparkPostPayloadBuilder();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testRecipientsListToThenCcThenBccInOrder(): void
    {
        $body = $this->build(['to' => ['a@example.com'], 'cc' => ['b@example.com'], 'bcc' => ['c@example.com']]);

        $emails = array_column(array_column($body['recipients'], 'address'), 'email');
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $emails);
    }

    public function testEveryRecipientSharesTheSameHeaderToBuiltFromTheToAddressesOnly(): void
    {
        $body = $this->build([
            'to'  => ['a1@example.com', 'Second <a2@example.com>'],
            'cc'  => ['b@example.com'],
            'bcc' => ['c@example.com'],
        ]);

        $expectedHeaderTo = 'a1@example.com, Second <a2@example.com>';
        foreach ($body['recipients'] as $recipient) {
            $this->assertSame($expectedHeaderTo, $recipient['address']['header_to']);
        }
    }

    public function testBareEmailInRecipientsNeverIncludesADisplayName(): void
    {
        $body = $this->build(['to' => ['To Person <a@example.com>']]);

        $this->assertSame('a@example.com', $body['recipients'][0]['address']['email']);
    }

    public function testCcHeaderIsSetWhenCcIsPresentAndContainsTheCcAddress(): void
    {
        $body = $this->build(['to' => ['a@example.com'], 'cc' => ['CC Person <b@example.com>']]);

        $this->assertSame('CC Person <b@example.com>', $body['content']['headers']['CC']);
    }

    public function testContentHeadersKeyIsOmittedEntirelyWhenCcIsEmpty(): void
    {
        $body = $this->build(['to' => ['a@example.com'], 'bcc' => ['c@example.com']]);

        $this->assertArrayNotHasKey('headers', $body['content']);
    }

    public function testABccAddressNeverAppearsInAnyContentHeadersValueEvenWhenCcIsAlsoPresent(): void
    {
        $body = $this->build([
            'to'  => ['a@example.com'],
            'cc'  => ['b@example.com'],
            'bcc' => ['bcc-secret@example.com'],
        ]);

        $headerValues = implode(' | ', array_map('strval', $body['content']['headers']));
        $this->assertStringNotContainsString('bcc-secret@example.com', $headerValues);

        // The bcc address must still be a recipient (so it's actually delivered) - just invisible in headers.
        $emails = array_column(array_column($body['recipients'], 'address'), 'email');
        $this->assertContains('bcc-secret@example.com', $emails);
    }

    public function testFromRendersAsARfc822StringFallingBackToTheConnectionWhenTheMessageHasNone(): void
    {
        $connection = $this->connection(['fromName' => 'From Name']);
        $body       = $this->build([], $connection);

        $this->assertSame('From Name <from@example.com>', $body['content']['from']);
    }

    public function testReplyToIsOmittedWhenAbsent(): void
    {
        $body = $this->build([]);

        $this->assertArrayNotHasKey('reply_to', $body['content']);
    }

    public function testReplyToIsSetWhenPresent(): void
    {
        $body = $this->build(['replyTo' => 'reply@example.com']);

        $this->assertSame('reply@example.com', $body['content']['reply_to']);
    }

    public function testHtmlContentTypeRoutesBodyToTheHtmlKeyAndOmitsText(): void
    {
        $body = $this->build(['contentType' => 'text/html', 'body' => '<p>Hi</p>']);

        $this->assertSame('<p>Hi</p>', $body['content']['html']);
        $this->assertArrayNotHasKey('text', $body['content']);
    }

    public function testPlainContentTypeRoutesBodyToTheTextKeyAndOmitsHtml(): void
    {
        $body = $this->build(['body' => 'Plain body']);

        $this->assertSame('Plain body', $body['content']['text']);
        $this->assertArrayNotHasKey('html', $body['content']);
    }

    public function testAttachmentsKeyIsOmittedWhenTheMessageHasNone(): void
    {
        $body = $this->build([]);

        $this->assertArrayNotHasKey('attachments', $body['content']);
    }

    public function testAttachmentsRenderInTheSparkPostShapeWhenPresent(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
        $path = $this->createTempFile('known attachment bytes');

        $body = $this->build(['attachments' => ['report.txt' => $path]]);

        $entry = $body['content']['attachments'][0];
        $this->assertEqualsCanonicalizing(['name', 'type', 'data'], array_keys($entry));
        $this->assertSame('report.txt', $entry['name']);
        $this->assertSame('text/plain', $entry['type']);
        $this->assertSame(base64_encode('known attachment bytes'), $entry['data']);
    }

    private function build(array $overrides, ?Connection $connection = null): array
    {
        $message = MailMessage::fromArray(array_merge([
            'to' => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
        ], $overrides));

        return $this->builder->build($message, $connection ?? $this->connection());
    }

    private function connection(array $overrides = []): Connection
    {
        return Connection::fromArray(array_merge([
            'id'        => 'conn_1', 'provider' => 'sparkpost', 'kind' => 'api',
            'fromEmail' => 'from@example.com',
        ], $overrides));
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-sparkpost-builder-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
