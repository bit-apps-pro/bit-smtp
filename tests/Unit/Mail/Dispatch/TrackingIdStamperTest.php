<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\Mail\Dispatch\TrackingIdStamper;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
class TrackingIdStamperTest extends BaseUnitTestCase
{
    private TrackingIdStamper $stamper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stamper = new TrackingIdStamper();
    }

    public function testGenerateWrapsWpGenerateUuid4(): void
    {
        Functions\when('wp_generate_uuid4')->justReturn('u-1');

        $this->assertSame('u-1', $this->stamper->generate());
    }

    public function testMetadataChannelStampsTheTrackingIdUnderTheDeclaredKey(): void
    {
        $message  = $this->message();
        $tracking = ['channel' => 'metadata', 'key' => 'bit_tracking_id'];

        $stamped = $this->stamper->stamp($message, $tracking, 'u-1');

        $this->assertSame('u-1', $stamped->getMetadata()['bit_tracking_id']);
    }

    public function testMetadataChannelPreservesPreExistingMetadata(): void
    {
        $message  = $this->message(['metadata' => ['campaign' => 'welcome']]);
        $tracking = ['channel' => 'metadata', 'key' => 'bit_tracking_id'];

        $stamped = $this->stamper->stamp($message, $tracking, 'u-1');

        $this->assertSame(
            ['campaign' => 'welcome', 'bit_tracking_id' => 'u-1'],
            $stamped->getMetadata()
        );
    }

    public function testHeaderChannelStampsTheTrackingIdIntoHeaders(): void
    {
        $message  = $this->message();
        $tracking = ['channel' => 'header', 'key' => 'X-Mailin-custom'];

        $stamped = $this->stamper->stamp($message, $tracking, 'u-1');

        $this->assertSame('u-1', $stamped->getHeaders()['X-Mailin-custom']);
        $this->assertSame([], $stamped->getMetadata());
    }

    public function testEmptyTrackingReturnsTheMessageUnchanged(): void
    {
        $message = $this->message();

        $stamped = $this->stamper->stamp($message, [], 'u-1');

        $this->assertSame([], $stamped->getMetadata());
        $this->assertSame([], $stamped->getHeaders());
    }

    public function testUnknownChannelReturnsTheMessageUnchanged(): void
    {
        $message  = $this->message();
        $tracking = ['channel' => 'query', 'key' => 'bit_tracking_id'];

        $stamped = $this->stamper->stamp($message, $tracking, 'u-1');

        $this->assertSame([], $stamped->getMetadata());
        $this->assertSame([], $stamped->getHeaders());
    }

    private function message(array $overrides = []): MailMessage
    {
        return MailMessage::fromArray(array_merge([
            'to' => ['a@x.com'], 'subject' => 's', 'body' => 'b',
        ], $overrides));
    }
}
