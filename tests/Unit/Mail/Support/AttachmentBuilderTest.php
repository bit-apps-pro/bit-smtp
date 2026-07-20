<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\AttachmentBuilder;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class AttachmentBuilderTest extends BaseUnitTestCase
{
    private const CONTENT = 'known attachment bytes';

    private AttachmentBuilder $builder;

    /**
     * @var string[]
     */
    private $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new AttachmentBuilder();
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'txt', 'type' => 'text/plain']);
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

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: null|string}>
     */
    public static function shapeKeysProvider(): iterable
    {
        yield 'sendgrid' => [AttachmentBuilder::SHAPE_SENDGRID, 'content', 'filename', 'type'];

        yield 'postmark' => [AttachmentBuilder::SHAPE_POSTMARK, 'Content', 'Name', 'ContentType'];

        yield 'brevo' => [AttachmentBuilder::SHAPE_BREVO, 'content', 'name', null];

        yield 'mailjet' => [AttachmentBuilder::SHAPE_MAILJET, 'Base64Content', 'Filename', 'ContentType'];

        yield 'zepto' => [AttachmentBuilder::SHAPE_ZEPTO, 'content', 'name', 'mime_type'];

        yield 'resend' => [AttachmentBuilder::SHAPE_RESEND, 'content', 'filename', null];

        yield 'sparkpost' => [AttachmentBuilder::SHAPE_SPARKPOST, 'data', 'name', 'type'];
    }

    #[DataProvider('shapeKeysProvider')]
    public function testEachShapeEmitsExactKeysWithBase64ContentAndDetectedMime(
        string $shape,
        string $contentKey,
        string $nameKey,
        ?string $mimeKey
    ): void {
        $path = $this->createTempFile(self::CONTENT);

        $result = $this->builder->build(['known.txt' => $path], $shape);

        $this->assertCount(1, $result);
        $row = $result[0];

        $expectedKeys = [$contentKey, $nameKey];
        if ($mimeKey !== null) {
            $expectedKeys[] = $mimeKey;
            $this->assertSame('text/plain', $row[$mimeKey]);
        }

        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($row));
        $this->assertSame(base64_encode(self::CONTENT), $row[$contentKey]);
        $this->assertSame('known.txt', $row[$nameKey]);
    }

    public function testMimeFallsBackToOctetStreamWhenWpCheckFiletypeReturnsEmptyType(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => '', 'type' => '']);
        $path = $this->createTempFile(self::CONTENT);

        $result = $this->builder->build(['known.txt' => $path], 'sendgrid');

        $this->assertSame('application/octet-stream', $result[0]['type']);
    }

    public function testMimeFallsBackToOctetStreamWhenWpCheckFiletypeReturnsFalseType(): void
    {
        Functions\when('wp_check_filetype')->justReturn(['ext' => false, 'type' => false]);
        $path = $this->createTempFile(self::CONTENT);

        $result = $this->builder->build(['known.txt' => $path], 'sendgrid');

        $this->assertSame('application/octet-stream', $result[0]['type']);
    }

    public function testIntegerKeyedAttachmentDerivesFilenameFromPathBasename(): void
    {
        $path = $this->createTempFile(self::CONTENT);

        $result = $this->builder->build([$path], 'resend');

        $this->assertSame(basename($path), $result[0]['filename']);
    }

    public function testStringKeyedAttachmentUsesKeyAsFilename(): void
    {
        $path = $this->createTempFile(self::CONTENT);

        $result = $this->builder->build(['custom-name.txt' => $path], 'resend');

        $this->assertSame('custom-name.txt', $result[0]['filename']);
    }

    public function testMultipleAttachmentsProduceOneRowEachInInputOrder(): void
    {
        $first  = $this->createTempFile('first bytes');
        $second = $this->createTempFile('second bytes');

        $result = $this->builder->build(['a.txt' => $first, 'b.txt' => $second], 'brevo');

        $this->assertCount(2, $result);
        $this->assertSame('a.txt', $result[0]['name']);
        $this->assertSame(base64_encode('first bytes'), $result[0]['content']);
        $this->assertSame('b.txt', $result[1]['name']);
        $this->assertSame(base64_encode('second bytes'), $result[1]['content']);
    }

    public function testEmptyAttachmentsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->builder->build([], 'sendgrid'));
    }

    public function testUnreadablePathThrowsRuntimeException(): void
    {
        $missing = sys_get_temp_dir() . '/bit-smtp-missing-' . uniqid() . '.txt';

        $this->expectException(RuntimeException::class);

        $this->builder->build(['ghost.txt' => $missing], 'sendgrid');
    }

    public function testUnknownShapeThrowsInvalidArgumentException(): void
    {
        $path = $this->createTempFile(self::CONTENT);

        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(['known.txt' => $path], 'mailchimp');
    }

    public function testUnknownShapeThrowsEvenWhenAttachmentsAreEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build([], 'bogus-shape');
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-attach-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
