<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\FormMultipartEncoder;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * Proves the auto-upgrade rule (spec: Mailgun's descriptor uses this as its 'multipart' encoder):
 * a payload with no file-group values stays the light urlencoded form, a payload with any
 * file-group value (an assoc name=>path array) upgrades to a full multipart/form-data body.
 *
 * @internal
 *
 * @coversNothing
 */
final class FormMultipartEncoderTest extends BaseUnitTestCase
{
    private FormMultipartEncoder $encoder;

    /**
     * @var string[]
     */
    private $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->encoder = new FormMultipartEncoder();
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

    public function testAPayloadWithNoArrayValuesEncodesAsUrlencodedForm(): void
    {
        $encoded = $this->encoder->encode(['from' => 'a@b.test', 'to' => 'c@d.test']);

        $this->assertSame('application/x-www-form-urlencoded', $encoded['contentType']);
        $this->assertSame('from=a%40b.test&to=c%40d.test', $encoded['body']);
    }

    public function testAPayloadWithAFileGroupValueUpgradesToMultipart(): void
    {
        $path = $this->createTempFile('known attachment bytes');

        $encoded = $this->encoder->encode(['from' => 'a@b.test', 'attachment' => ['report.txt' => $path]]);

        $this->assertMatchesRegularExpression('/^multipart\/form-data; boundary=.+$/', $encoded['contentType']);
        $this->assertStringContainsString('name="attachment"; filename="report.txt"', $encoded['body']);
        $this->assertStringContainsString('known attachment bytes', $encoded['body']);
    }

    public function testScalarFieldsAlongsideAFileGroupAreRenderedAsFormFieldPartsNotUrlencoded(): void
    {
        $path = $this->createTempFile('bytes');

        $encoded = $this->encoder->encode(['from' => 'a@b.test', 'attachment' => ['f.txt' => $path]]);

        $this->assertStringContainsString('name="from"', $encoded['body']);
        $this->assertStringNotContainsString('from=a%40b.test', $encoded['body']);
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bit-smtp-formmultipart-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
