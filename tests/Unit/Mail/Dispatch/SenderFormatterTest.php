<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\Mail\Dispatch\SenderFormatter;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class SenderFormatterTest extends BaseUnitTestCase
{
    public function testFormatsAsNameThenBracketedEmailWhenBothArePresent(): void
    {
        $this->assertSame('Store <a@x.test>', SenderFormatter::format('a@x.test', 'Store'));
    }

    public function testFormatsAsABareEmailWhenNameIsEmpty(): void
    {
        $this->assertSame('a@x.test', SenderFormatter::format('a@x.test', ''));
    }

    public function testFormatsAsAnEmptyStringWhenEmailIsEmptyEvenIfNameIsPresent(): void
    {
        $this->assertSame('', SenderFormatter::format('', 'Store'));
    }

    public function testFormatsAsAnEmptyStringWhenBothAreNull(): void
    {
        $this->assertSame('', SenderFormatter::format(null, null));
    }

    public function testTrimsSurroundingWhitespaceFromBothEmailAndName(): void
    {
        $this->assertSame('Store <a@x.test>', SenderFormatter::format('  a@x.test  ', '  Store  '));
    }

    public function testSanitizeStripsCrLfWhilePreservingTheBracketedEmail(): void
    {
        $this->assertSame('AB <a@x.test>', SenderFormatter::sanitize("A\r\nB <a@x.test>"));
    }

    public function testSanitizeRemovesOtherControlCharactersButKeepsTheAngleBrackets(): void
    {
        $this->assertSame('Store <a@x.test>', SenderFormatter::sanitize("Store\t\x07 <a@x.test>"));
    }

    public function testSanitizeTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('Store <a@x.test>', SenderFormatter::sanitize('  Store <a@x.test>  '));
    }

    public function testSanitizeReturnsAnEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', SenderFormatter::sanitize(''));
    }
}
