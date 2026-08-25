<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Services;

use BitApps\SMTP\HTTP\Services\LogBodyRedactor;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * Covers the pure `log_store_body` policy: each mode's effect on the stored body, and the guarantee
 * that subject/recipients/headers are never touched regardless of mode.
 *
 * @internal
 *
 * @coversNothing
 */
final class LogBodyRedactorTest extends BaseUnitTestCase
{
    public function testFullModeStoresTheBodyUnchanged(): void
    {
        $result = LogBodyRedactor::apply($this->details(), LogBodyRedactor::MODE_FULL);

        $this->assertSame('<p>Secret body</p>', $result['message']);
        $this->assertSame('<p>Secret body</p>', $result['html']);
    }

    public function testRedactedModeReplacesTheBodyKeysWithAPlaceholder(): void
    {
        $result = LogBodyRedactor::apply($this->details(), LogBodyRedactor::MODE_REDACTED);

        $this->assertArrayHasKey('message', $result, 'the message key is kept so the detail UI still shows a body area');
        $this->assertSame('[redacted]', $result['message']);
        $this->assertSame('[redacted]', $result['html']);
    }

    public function testMetadataModeRemovesTheBodyKeysEntirely(): void
    {
        $result = LogBodyRedactor::apply($this->details(), LogBodyRedactor::MODE_METADATA);

        $this->assertArrayNotHasKey('message', $result);
        $this->assertArrayNotHasKey('html', $result);
    }

    public function testNonBodyMetadataIsNeverTouched(): void
    {
        foreach ([LogBodyRedactor::MODE_FULL, LogBodyRedactor::MODE_REDACTED, LogBodyRedactor::MODE_METADATA] as $mode) {
            $result = LogBodyRedactor::apply($this->details(), $mode);

            $this->assertSame('Order confirmation', $result['subject'], "subject must survive {$mode}");
            $this->assertSame(['buyer@example.test'], $result['to'], "to must survive {$mode}");
            $this->assertSame(['cc@example.test'], $result['cc'], "cc must survive {$mode}");
            $this->assertSame(['bcc@example.test'], $result['bcc'], "bcc must survive {$mode}");
            $this->assertSame(['X-Custom' => 'keep'], $result['headers'], "headers must survive {$mode}");
        }
    }

    public function testUnknownModeIsTreatedAsFull(): void
    {
        $result = LogBodyRedactor::apply($this->details(), 'nonsense');

        $this->assertSame('<p>Secret body</p>', $result['message']);
    }

    public function testAbsentBodyKeysAreLeftAlone(): void
    {
        $result = LogBodyRedactor::apply(['subject' => 'No body here'], LogBodyRedactor::MODE_METADATA);

        $this->assertSame(['subject' => 'No body here'], $result);
    }

    public function testIsBodyRetainedRejectsEmptyOrRedactedBodies(): void
    {
        $this->assertTrue(LogBodyRedactor::isBodyRetained('<p>Real body</p>'));
        $this->assertFalse(LogBodyRedactor::isBodyRetained(''), 'an empty/dropped body is not resendable');
        $this->assertFalse(LogBodyRedactor::isBodyRetained('   '), 'a whitespace-only body is not resendable');
        $this->assertFalse(
            LogBodyRedactor::isBodyRetained(LogBodyRedactor::REDACTED_PLACEHOLDER),
            'the redaction placeholder is not real content'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function details(): array
    {
        return [
            'message'     => '<p>Secret body</p>',
            'html'        => '<p>Secret body</p>',
            'subject'     => 'Order confirmation',
            'to'          => ['buyer@example.test'],
            'cc'          => ['cc@example.test'],
            'bcc'         => ['bcc@example.test'],
            'headers'     => ['X-Custom' => 'keep'],
            'attachments' => [],
        ];
    }
}
