<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\AddressFormatter;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 *
 * @coversNothing
 */
class AddressFormatterTest extends BaseUnitTestCase
{
    private AddressFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new AddressFormatter();
    }

    public function testCsvShapeJoinsBareEmailsWithCommaSpaceAndIgnoresNames(): void
    {
        $result = $this->formatter->format(['a@x.com', 'Alice <b@y.com>'], 'csv');

        $this->assertSame('a@x.com, b@y.com', $result);
    }

    public function testCsvShapeWithEmptyListReturnsEmptyString(): void
    {
        $result = $this->formatter->format([], 'csv');

        $this->assertSame('', $result);
    }

    public function testObjectShapeReturnsAssocArraysWithNameWhenPresent(): void
    {
        $result = $this->formatter->format(['Alice <a@x.com>'], 'object');

        $this->assertSame([['email' => 'a@x.com', 'name' => 'Alice']], $result);
    }

    public function testObjectShapeOmitsNameForBareEmail(): void
    {
        $result = $this->formatter->format(['a@x.com'], 'object');

        $this->assertSame([['email' => 'a@x.com']], $result);
    }

    public function testObjectUcShapeReturnsAssocArraysWithCapitalizedKeysAndNameWhenPresent(): void
    {
        $result = $this->formatter->format(['Alice <a@x.com>'], 'object_uc');

        $this->assertSame([['Email' => 'a@x.com', 'Name' => 'Alice']], $result);
    }

    public function testObjectUcShapeOmitsNameForBareEmail(): void
    {
        $result = $this->formatter->format(['a@x.com'], 'object_uc');

        $this->assertSame([['Email' => 'a@x.com']], $result);
    }

    public function testObjectUcShapeWithTwoAddressesReturnsTwoEntries(): void
    {
        $result = $this->formatter->format(['a@x.com', 'Bob <b@y.com>'], 'object_uc');

        $this->assertSame([['Email' => 'a@x.com'], ['Email' => 'b@y.com', 'Name' => 'Bob']], $result);
    }

    public function testNestedShapeReturnsZeptoMailStyleEnvelopeWithName(): void
    {
        $result = $this->formatter->format(['Alice <a@x.com>'], 'nested');

        $this->assertSame(
            [['email_address' => ['address' => 'a@x.com', 'name' => 'Alice']]],
            $result
        );
    }

    public function testNestedShapeOmitsNameForBareEmail(): void
    {
        $result = $this->formatter->format(['a@x.com'], 'nested');

        $this->assertSame(
            [['email_address' => ['address' => 'a@x.com']]],
            $result
        );
    }

    public function testRfc822ShapeReturnsSingleStringWithNameForOneAddress(): void
    {
        $result = $this->formatter->format(['Alice <a@x.com>'], 'rfc822');

        $this->assertSame('Alice <a@x.com>', $result);
    }

    public function testRfc822ShapeReturnsBareStringForOneAddressWithoutName(): void
    {
        $result = $this->formatter->format(['a@x.com'], 'rfc822');

        $this->assertSame('a@x.com', $result);
    }

    public function testRfc822ShapeReturnsArrayForMultipleAddresses(): void
    {
        $result = $this->formatter->format(['Alice <a@x.com>', 'b@y.com'], 'rfc822');

        $this->assertSame(['Alice <a@x.com>', 'b@y.com'], $result);
    }

    public function testTrimsWhitespaceAroundNameAndAddress(): void
    {
        $result = $this->formatter->format(['  Alice   <  a@x.com  >  '], 'object');

        $this->assertSame([['email' => 'a@x.com', 'name' => 'Alice']], $result);
    }

    public function testObjectShapeWithEmptyListReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->formatter->format([], 'object'));
    }

    public function testNestedShapeWithEmptyListReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->formatter->format([], 'nested'));
    }

    public function testRfc822ShapeWithEmptyListReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->formatter->format([], 'rfc822'));
    }

    public function testMalformedBracketInputFallsThroughToBareAddress(): void
    {
        // No closing '>': non-validating formatter keeps the whole string as the address.
        $result = $this->formatter->format(['Name <a@x.com'], 'object');

        $this->assertSame([['email' => 'Name <a@x.com']], $result);
    }

    public function testUnknownShapeThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->formatter->format(['a@x.com'], 'xml');
    }
}
