<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Aws\Sns;

use BitApps\SMTP\Mail\Aws\Sns\SnsEndpoint;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
class SnsEndpointTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_parse_url')->alias(static fn ($url, $component = -1) => $component === -1 ? parse_url($url) : parse_url($url, $component));
    }

    public function testAcceptsHttpsRegionalSnsHost(): void
    {
        $this->assertTrue(SnsEndpoint::isAwsSnsUrl('https://sns.us-east-1.amazonaws.com/x'));
    }

    public function testAcceptsHttpsRegionalSnsHostWithPemPath(): void
    {
        $this->assertTrue(SnsEndpoint::isAwsSnsUrl('https://sns.eu-west-2.amazonaws.com/y.pem'));
    }

    public function testAcceptsChinaPartitionRegionalSnsHost(): void
    {
        $this->assertTrue(SnsEndpoint::isAwsSnsUrl('https://sns.cn-north-1.amazonaws.com.cn/x'));
    }

    public function testRejectsNonHttpsScheme(): void
    {
        $this->assertFalse(SnsEndpoint::isAwsSnsUrl('http://sns.us-east-1.amazonaws.com/x'));
    }

    public function testRejectsSuffixAttackHost(): void
    {
        $this->assertFalse(SnsEndpoint::isAwsSnsUrl('https://sns.us-east-1.amazonaws.com.evil.test/x'));
    }

    public function testRejectsSubstringAmazonawsHost(): void
    {
        $this->assertFalse(SnsEndpoint::isAwsSnsUrl('https://evil-amazonaws.com/x'));
    }

    public function testRejectsHostWithoutSnsPrefix(): void
    {
        $this->assertFalse(SnsEndpoint::isAwsSnsUrl('https://amazonaws.com/x'));
    }

    public function testRejectsEmptyString(): void
    {
        $this->assertFalse(SnsEndpoint::isAwsSnsUrl(''));
    }

    public function testRejectsHostWithNoRegionLabelBetweenSnsAndAmazonaws(): void
    {
        // `sns.amazonaws.com`: after `sns.` the pattern needs `<region>.amazonaws.com`, but only a
        // single `amazonaws` label precedes `.com` here, so the regex cannot match.
        $this->assertFalse(SnsEndpoint::isAwsSnsUrl('https://sns.amazonaws.com/x'));
    }
}
