<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\MultipartEncoder;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class MultipartEncoderTest extends BaseUnitTestCase
{
    private const CONTENT = 'known attachment bytes';

    private MultipartEncoder $encoder;

    /**
     * @var string[]
     */
    private $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->encoder = new MultipartEncoder();
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

    public function testContentTypeBoundaryMatchesDelimitersInBody(): void
    {
        $encoded = $this->encoder->encode(['from' => 'a@b.test']);

        $this->assertMatchesRegularExpression(
            '/^multipart\/form-data; boundary=(.+)$/',
            $encoded['contentType']
        );
        $boundary = $this->extractBoundary($encoded['contentType']);

        $this->assertStringContainsString("--{$boundary}\r\n", $encoded['body']);
    }

    public function testScalarFieldRendersContentDispositionPart(): void
    {
        $encoded  = $this->encoder->encode(['from' => 'sender@example.com']);
        $boundary = $this->extractBoundary($encoded['contentType']);

        $expectedPart = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"from\"\r\n"
            . "\r\n"
            . "sender@example.com\r\n";

        $this->assertStringContainsString($expectedPart, $encoded['body']);
    }

    public function testFileGroupRendersFilePartWithFilenameMimeAndRawBytes(): void
    {
        $path = $this->createTempFile(self::CONTENT);

        $encoded  = $this->encoder->encode(['attachment' => ['known.txt' => $path]]);
        $boundary = $this->extractBoundary($encoded['contentType']);

        $this->assertStringContainsString(
            "--{$boundary}\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"known.txt\"\r\n",
            $encoded['body']
        );
        $this->assertStringContainsString('Content-Type: ', $encoded['body']);
        $this->assertStringContainsString("\r\n\r\n" . self::CONTENT . "\r\n", $encoded['body']);
    }

    public function testTwoFilesUnderOneKeyRenderTwoPartsBothNamedAttachment(): void
    {
        $first  = $this->createTempFile('first bytes');
        $second = $this->createTempFile('second bytes');

        $encoded = $this->encoder->encode([
            'attachment' => ['a.txt' => $first, 'b.txt' => $second],
        ]);

        $count = substr_count($encoded['body'], 'Content-Disposition: form-data; name="attachment";');
        $this->assertSame(2, $count);
        $this->assertStringContainsString('filename="a.txt"', $encoded['body']);
        $this->assertStringContainsString('filename="b.txt"', $encoded['body']);
    }

    public function testUnreadablePathThrowsRuntimeException(): void
    {
        $missing = sys_get_temp_dir() . '/bit-smtp-missing-' . uniqid() . '.txt';

        $this->expectException(RuntimeException::class);

        $this->encoder->encode(['attachment' => ['ghost.txt' => $missing]]);
    }

    public function testFilenameContainingQuoteAndCrlfIsSanitized(): void
    {
        $path    = $this->createTempFile(self::CONTENT);
        $hostile = "evil\r\nX-Injected: yes\".txt";

        $encoded = $this->encoder->encode(['attachment' => [$hostile => $path]]);

        $this->assertStringNotContainsString("evil\r\nX-Injected: yes\"", $encoded['body']);
        $this->assertStringNotContainsString("\r\nX-Injected:", $encoded['body']);
        $this->assertStringNotContainsString('yes".txt', $encoded['body']);
    }

    public function testBodyEndsWithClosingDelimiter(): void
    {
        $encoded  = $this->encoder->encode(['from' => 'a@b.test']);
        $boundary = $this->extractBoundary($encoded['contentType']);

        $this->assertStringEndsWith("--{$boundary}--\r\n", $encoded['body']);
    }

    private function extractBoundary(string $contentType): string
    {
        $this->assertSame(1, preg_match('/boundary=(.+)$/', $contentType, $matches));

        return $matches[1];
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-multipart-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
