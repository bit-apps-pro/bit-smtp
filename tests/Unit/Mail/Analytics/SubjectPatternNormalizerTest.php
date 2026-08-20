<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Unit\Mail\Analytics;

use BitApps\SMTP\Mail\Analytics\SubjectPatternNormalizer;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SubjectPatternNormalizerTest extends BaseUnitTestCase
{
    public function testRedactsAddressesUrlsUuidsAndLongNumericIdentifiers(): void
    {
        $normalizer = new SubjectPatternNormalizer();

        $actual = $normalizer->normalize(
            ' Order 987654 for jane@example.test at https://example.test/orders/987654 '
            . 'reference 550e8400-e29b-41d4-a716-446655440000 '
        );

        self::assertSame('Order <number> for <email> at <url> reference <uuid>', $actual);
    }

    public function testCollapsesRepeatedWhitespaceAndBoundsTheReturnedPattern(): void
    {
        $normalizer = new SubjectPatternNormalizer();

        $actual = $normalizer->normalize("  Receipt\n\t for    order 123456  " . str_repeat('a', 240));

        self::assertStringStartsWith('Receipt for order <number>', $actual);
        self::assertLessThanOrEqual(160, \strlen($actual));
    }

    public function testRedactsAllCanonicalUuidVersionsIncludingNilVersionSixSevenAndEight(): void
    {
        $normalizer = new SubjectPatternNormalizer();

        $actual = $normalizer->normalize(
            '00000000-0000-0000-0000-000000000000 '
            . '1f0e8400-e29b-61d4-a716-446655440000 '
            . '018f0f6d-75b4-7cc7-9c49-4d8e5d6f7a8b '
            . '550e8400-e29b-81d4-a716-446655440000'
        );

        self::assertSame('<uuid> <uuid> <uuid> <uuid>', $actual);
    }

    public function testRedactsUntrustedFreeTextSoPersistedPatternsCannotContainNames(): void
    {
        $actual = (new SubjectPatternNormalizer())->normalize('Order 12345 for Jane Example: custom gift note');

        self::assertSame('Order <number> for <text> <text>: <text> <text> <text>', $actual);
        self::assertStringNotContainsString('Jane', $actual);
        self::assertStringNotContainsString('Example', $actual);
    }
}
