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
}
