<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureCategoryTest extends BaseUnitTestCase
{
    public function testRetryableClassesAreTransientAndRateLimitedOnly(): void
    {
        $this->assertSame(
            [FailureCategory::TRANSIENT, FailureCategory::RATE_LIMITED],
            FailureCategory::retryableClasses()
        );
    }

    public function testIsRetryableWithinAnEmptyFilterAllowsEveryRetryableClass(): void
    {
        // Empty filter = today's behaviour: every retryable class retries.
        $this->assertTrue(FailureCategory::isRetryableWithin(FailureCategory::TRANSIENT, []));
        $this->assertTrue(FailureCategory::isRetryableWithin(FailureCategory::RATE_LIMITED, []));
    }

    public function testIsRetryableWithinNeverAllowsANonRetryableClass(): void
    {
        // A non-retryable class stays non-retryable even when explicitly listed.
        $this->assertFalse(FailureCategory::isRetryableWithin(FailureCategory::AUTH, []));
        $this->assertFalse(FailureCategory::isRetryableWithin(FailureCategory::PERMANENT, [FailureCategory::PERMANENT]));
        $this->assertFalse(FailureCategory::isRetryableWithin(FailureCategory::INVALID_RECIPIENT, []));
    }

    public function testIsRetryableWithinANonEmptyFilterRequiresMembership(): void
    {
        $filter = [FailureCategory::RATE_LIMITED];

        $this->assertTrue(FailureCategory::isRetryableWithin(FailureCategory::RATE_LIMITED, $filter));
        $this->assertFalse(FailureCategory::isRetryableWithin(FailureCategory::TRANSIENT, $filter));
    }
}
