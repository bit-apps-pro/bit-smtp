<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Tracking;

use BitApps\SMTP\Mail\Tracking\BotDetector;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * Exercises each automated-fire heuristic (Gmail proxy UA, Apple proxy IP block, scanner UA
 * substrings, prefetch window) plus the human path. Pure classifier — no WordPress runtime.
 *
 * @internal
 *
 * @coversNothing
 */
final class BotDetectorTest extends BaseUnitTestCase
{
    private const HUMAN_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    private const FAR_PAST = 3600;

    public function testHumanOpenIsNotFlagged(): void
    {
        self::assertFalse(BotDetector::classify(self::HUMAN_UA, '203.0.113.7', self::FAR_PAST));
    }

    public function testGmailImageProxyUaIsFlagged(): void
    {
        self::assertTrue(BotDetector::classify('Mozilla/5.0 (Windows NT 5.1; rv:11.0) via ggpht.com GoogleImageProxy', '66.249.93.1', self::FAR_PAST));
    }

    public function testAppleProxyIpRangeIsFlagged(): void
    {
        self::assertTrue(BotDetector::classify(self::HUMAN_UA, '17.58.63.10', self::FAR_PAST));
    }

    public function testNonAppleIpIsNotFlaggedByIpAlone(): void
    {
        self::assertFalse(BotDetector::classify(self::HUMAN_UA, '171.0.0.1', self::FAR_PAST));
    }

    public function testScannerUaSubstringIsFlagged(): void
    {
        self::assertTrue(BotDetector::classify('Proofpoint URL Defense Scanner', '198.51.100.4', self::FAR_PAST));
        self::assertTrue(BotDetector::classify('SomeBot/1.0', '198.51.100.4', self::FAR_PAST));
    }

    public function testPrefetchWindowFireIsFlagged(): void
    {
        self::assertTrue(BotDetector::classify(self::HUMAN_UA, '203.0.113.7', 3));
    }

    public function testOpenAtTheWindowBoundaryIsNotFlagged(): void
    {
        // The window is exclusive: a fire exactly at the boundary is treated as human.
        self::assertFalse(BotDetector::classify(self::HUMAN_UA, '203.0.113.7', 10));
    }

    public function testNegativeAgeFromClockSkewIsNotTreatedAsPrefetch(): void
    {
        self::assertFalse(BotDetector::classify(self::HUMAN_UA, '203.0.113.7', -5));
    }

    public function testMissingIpDoesNotCrashHumanPath(): void
    {
        self::assertFalse(BotDetector::classify(self::HUMAN_UA, null, self::FAR_PAST));
    }
}
