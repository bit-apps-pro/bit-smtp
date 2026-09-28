<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Notifications\Channels;

use BitApps\SMTP\Mail\Notifications\Channels\ChannelFailureReason;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * @internal
 *
 * @coversNothing
 */
final class ChannelFailureReasonTest extends BaseUnitTestCase
{
    private const TOKEN = '123456789:AAExampleBotToken_abcdefghijklmnop';

    /**
     * Each crafted provider detail must come back without the secret-bearing fragment.
     *
     * @param string $leak substring that must not survive redaction
     */
    #[DataProvider('craftedLeakProvider')]
    public function testSecretShapedDetailIsRedactedWithoutKnowingTheSecret(string $detail, string $leak): void
    {
        $redacted = ChannelFailureReason::redact($detail, 500);

        $this->assertStringNotContainsString($leak, $redacted);
        $this->assertStringNotContainsString(rawurldecode($leak), $redacted);
    }

    /**
     * Crafted details and the secret fragment each one tries to leak.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function craftedLeakProvider(): array
    {
        return [
            'url-encoded bot token'       => ['failed for bot123456789%3AAAExampleBotToken_abcdefghijklmnop', 'AAExampleBotToken'],
            'url-encoded url'             => ['GET https%3A%2F%2Fhooks.slack.com%2Fservices%2FT0%2FB0%2Fxoxsecret failed', 'xoxsecret'],
            'scheme-less discord webhook' => ['Unknown Webhook discord.com/api/webhooks/123/tokenpart', 'tokenpart'],
            'scheme-less slack webhook'   => ['posting to hooks.slack.com/services/T0/B0/slacksecret', 'slacksecret'],
            'token with spaced colon'     => ['token 123456789 : AAExampleBotToken_abcdefghijklmnop bad', 'AAExampleBotToken'],
            'secret key=value'            => ['bad request secret=hunter2 given', 'hunter2'],
            'api_key colon value'         => ['api_key: sk_live_42 rejected', 'sk_live_42'],
            'signature query value'       => ['invalid signature=abc123&x=1', 'abc123'],
            'telegram chat id'            => ['Bad Request: chat -1001234567890 not found', '1001234567890'],
            'long opaque string'          => ['Invalid credential Zx9QpL0mN2bV7cX4aS6dF8gH1jK3', 'Zx9QpL0mN2bV7cX4aS6dF8gH1jK3'],
        ];
    }

    /**
     * Channel's own secret is removed raw and URL-encoded, even when short and matches no pattern.
     */
    public function testTheChannelsOwnSecretIsRemovedRawAndUrlEncoded(): void
    {
        $secret = 'p@ss w0rd!';

        $redacted = ChannelFailureReason::redact(
            'raw ' . $secret . ' raw-encoded ' . rawurlencode($secret) . ' form-encoded ' . urlencode($secret),
            500,
            [$secret]
        );

        $this->assertSame('raw [redacted] raw-encoded [redacted] form-encoded [redacted]', $redacted);
    }

    /**
     * A configured URL is removed before the token it contains, and nothing of either survives.
     */
    public function testATransportErrorNamingTheRequestUrlLeaksNeitherUrlNorToken(): void
    {
        $url   = 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage';
        $error = new WP_Error('http_request_failed', 'cURL error 6: Could not resolve ' . rawurlencode($url));

        $reason = (string) ChannelFailureReason::transport($error, [self::TOKEN, '-1001234567890'])->reason();

        $this->assertStringStartsWith('Could not connect: cURL error 6: Could not resolve', $reason);
        $this->assertStringNotContainsString('AAExampleBotToken', $reason);
        $this->assertStringNotContainsString('api.telegram.org', $reason);
    }

    /**
     * Ordinary diagnostic text survives unchanged.
     */
    public function testHarmlessDiagnosticsAreKept(): void
    {
        $this->assertSame(
            'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received',
            ChannelFailureReason::redact('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received', 500)
        );
    }
}
