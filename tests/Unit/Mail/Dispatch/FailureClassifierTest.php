<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Dispatch;

use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class FailureClassifierTest extends BaseUnitTestCase
{
    private FailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new FailureClassifier();
    }

    #[DataProvider('sendResultProvider')]
    public function testClassifyMapsSendResultToExpectedCategory(SendResult $result, string $expected): void
    {
        self::assertSame($expected, $this->classifier->classify($result));
    }

    /**
     * @return array<string,array{0:SendResult,1:string}>
     */
    public static function sendResultProvider(): array
    {
        return [
            'success is OK' => [
                SendResult::success(),
                FailureCategory::OK,
            ],
            'accepted-with-error is PERMANENT (must not retry/failover)' => [
                SendResult::acceptedWithError('bounced one recipient', '200'),
                FailureCategory::PERMANENT,
            ],
            'HTTP 429 is RATE_LIMITED' => [
                SendResult::failure('Too Many Requests', '429'),
                FailureCategory::RATE_LIMITED,
            ],
            'HTTP 401 is AUTH' => [
                SendResult::failure('Unauthorized', '401'),
                FailureCategory::AUTH,
            ],
            'HTTP 403 is AUTH' => [
                SendResult::failure('Forbidden', '403'),
                FailureCategory::AUTH,
            ],
            'HTTP 500 is TRANSIENT' => [
                SendResult::failure('Internal Server Error', '500'),
                FailureCategory::TRANSIENT,
            ],
            'HTTP 503 is TRANSIENT' => [
                SendResult::failure('Service Unavailable', '503'),
                FailureCategory::TRANSIENT,
            ],
            'HTTP 408 is TRANSIENT' => [
                SendResult::failure('Request Timeout', '408'),
                FailureCategory::TRANSIENT,
            ],
            'HTTP 400 with recipient-rejected message is INVALID_RECIPIENT' => [
                SendResult::failure('recipient rejected', '400'),
                FailureCategory::INVALID_RECIPIENT,
            ],
            'HTTP 400 with a generic message is PERMANENT' => [
                SendResult::failure('Bad Request', '400'),
                FailureCategory::PERMANENT,
            ],
            'HTTP 404 is PERMANENT' => [
                SendResult::failure('Not Found', '404'),
                FailureCategory::PERMANENT,
            ],
            'SMTP "Could not authenticate (535)" is AUTH' => [
                SendResult::failure('SMTP Error: Could not authenticate (535)', '0'),
                FailureCategory::AUTH,
            ],
            'SMTP "SMTP connect() failed" is TRANSIENT' => [
                SendResult::failure('SMTP connect() failed', '0'),
                FailureCategory::TRANSIENT,
            ],
            '"550 No such user" is INVALID_RECIPIENT (SMTP: small PHPMailer code, signal is in the message)' => [
                SendResult::failure('550 No such user here', '0'),
                FailureCategory::INVALID_RECIPIENT,
            ],
            '"554 blocked as spam" is PERMANENT (SMTP: small PHPMailer code, signal is in the message)' => [
                SendResult::failure('554 blocked as spam', '0'),
                FailureCategory::PERMANENT,
            ],
            'greylist 450 reply is TRANSIENT despite "recipient ... rejected" wording (TRANSIENT is tested before INVALID_RECIPIENT)' => [
                SendResult::failure('SMTP Error: The following recipients failed: a@b.com : 450 4.2.0 <a@b.com>: Recipient address rejected: Greylisted, try again later', '1'),
                FailureCategory::TRANSIENT,
            ],
            '"550 5.1.1 No such user here" is still INVALID_RECIPIENT (a genuine permanent-recipient rejection, not greylisted)' => [
                SendResult::failure('550 5.1.1 No such user here', '0'),
                FailureCategory::INVALID_RECIPIENT,
            ],
            'code "0" with an unrecognized message defaults to TRANSIENT (not misread as HTTP)' => [
                SendResult::failure('unrecognized failure', '0'),
                FailureCategory::TRANSIENT,
            ],
            'network exception (null code) is TRANSIENT' => [
                SendResult::failure('cURL error 28: timeout', null),
                FailureCategory::TRANSIENT,
            ],
        ];
    }

    public function testIsRetryableIsTrueOnlyForTransientAndRateLimited(): void
    {
        self::assertTrue(FailureCategory::isRetryable(FailureCategory::TRANSIENT));
        self::assertTrue(FailureCategory::isRetryable(FailureCategory::RATE_LIMITED));

        self::assertFalse(FailureCategory::isRetryable(FailureCategory::OK));
        self::assertFalse(FailureCategory::isRetryable(FailureCategory::AUTH));
        self::assertFalse(FailureCategory::isRetryable(FailureCategory::INVALID_RECIPIENT));
        self::assertFalse(FailureCategory::isRetryable(FailureCategory::PERMANENT));
    }

    public function testStopsFailoverIsTrueOnlyForInvalidRecipient(): void
    {
        self::assertTrue(FailureCategory::stopsFailover(FailureCategory::INVALID_RECIPIENT));

        // PERMANENT and AUTH are connection-scoped (reputation/policy/credentials), so a different
        // connection may still deliver — failover must keep trying them.
        self::assertFalse(FailureCategory::stopsFailover(FailureCategory::PERMANENT));
        self::assertFalse(FailureCategory::stopsFailover(FailureCategory::AUTH));
        self::assertFalse(FailureCategory::stopsFailover(FailureCategory::OK));
        self::assertFalse(FailureCategory::stopsFailover(FailureCategory::TRANSIENT));
        self::assertFalse(FailureCategory::stopsFailover(FailureCategory::RATE_LIMITED));
    }
}
