<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Aws\Sns;

use BitApps\SMTP\Mail\Aws\Sns\SnsMessage;
use BitApps\SMTP\Mail\Aws\Sns\SnsSubscriptionConfirmer;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;

/**
 * @internal
 *
 * @coversNothing
 */
class SnsSubscriptionConfirmerTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_parse_url')->alias(static fn ($url, $component = -1) => $component === -1 ? parse_url($url) : parse_url($url, $component));
    }

    public function testConfirmFetchesTrustedSubscribeUrlAndReturnsFetchResult(): void
    {
        $url       = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc';
        $confirmer = $this->confirmer(true);

        $this->assertTrue($confirmer->confirm($this->message($url)));
        $this->assertTrue($confirmer->fetchCalled);
        $this->assertSame($url, $confirmer->fetchedUrl);
    }

    public function testConfirmReturnsFetchFailureFromTrustedSubscribeUrl(): void
    {
        $confirmer = $this->confirmer(false);

        $this->assertFalse($confirmer->confirm($this->message('https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription')));
        $this->assertTrue($confirmer->fetchCalled);
    }

    public function testConfirmRejectsUntrustedSubscribeUrlWithoutFetching(): void
    {
        $confirmer = $this->confirmer(true);

        $this->assertFalse($confirmer->confirm($this->message('https://evil.test/confirm')));
        $this->assertFalse($confirmer->fetchCalled);
        $this->assertNull($confirmer->fetchedUrl);
    }

    private function message(string $subscribeUrl): SnsMessage
    {
        return SnsMessage::fromArray([
            'Type'         => 'SubscriptionConfirmation',
            'SubscribeURL' => $subscribeUrl,
        ]);
    }

    /**
     * A confirmer whose network fetch is replaced by an in-memory recorder, so the trusted/untrusted
     * branch is exercised without any outbound request.
     */
    private function confirmer(bool $fetchResult): SnsSubscriptionConfirmer
    {
        return new class($fetchResult) extends SnsSubscriptionConfirmer {
            public bool $fetchCalled = false;

            public ?string $fetchedUrl = null;

            private bool $fetchResult;

            public function __construct(bool $fetchResult)
            {
                $this->fetchResult = $fetchResult;
            }

            protected function fetch(string $url): bool
            {
                $this->fetchCalled = true;
                $this->fetchedUrl  = $url;

                return $this->fetchResult;
            }
        };
    }
}
