<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Support;

use BitApps\SMTP\Mail\Support\ApiErrorFormatter;
use BitApps\SMTP\Tests\BaseUnitTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class ApiErrorFormatterTest extends BaseUnitTestCase
{
    private ApiErrorFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ApiErrorFormatter();
    }

    public function testNestedDotPathHitReturnsResolvedMessage(): void
    {
        $body = ['Messages' => [['Errors' => [['ErrorMessage' => 'bad from']]]]];

        $result = $this->formatter->extract($body, ['Messages.0.Errors.0.ErrorMessage'], 400);

        $this->assertSame('bad from', $result);
    }

    public function testFirstPathMissSecondPathHitReturnsSecond(): void
    {
        $body = ['message' => 'flat error'];

        $result = $this->formatter->extract($body, ['errors.0.message', 'message'], 400);

        $this->assertSame('flat error', $result);
    }

    public function testFirstOfTwoMatchingPathsWins(): void
    {
        $body = ['errors' => [['message' => 'first hit']], 'message' => 'second hit'];

        $result = $this->formatter->extract($body, ['errors.0.message', 'message'], 400);

        $this->assertSame('first hit', $result);
    }

    public function testStringBodyIsReturnedVerbatim(): void
    {
        $result = $this->formatter->extract('connection refused', ['errors.0.message'], 0);

        $this->assertSame('connection refused', $result);
    }

    public function testNonEmptyStringBodyIsReturnedUntrimmed(): void
    {
        $result = $this->formatter->extract('  connection refused  ', ['errors.0.message'], 0);

        $this->assertSame('  connection refused  ', $result);
    }

    public function testEmptyStringBodyReturnsGenericNetworkErrorWithStatus(): void
    {
        $result = $this->formatter->extract('', ['errors.0.message'], 0);

        $this->assertSame('Network error (HTTP 0)', $result);
    }

    public function testBlankStringBodyAfterTrimReturnsGenericNetworkError(): void
    {
        $result = $this->formatter->extract('   ', ['errors.0.message'], 0);

        $this->assertSame('Network error (HTTP 0)', $result);
    }

    public function testArrayBodyNoPathMatchesReturnsDefaultLabelFallback(): void
    {
        $result = $this->formatter->extract(['foo' => 'bar'], ['errors.0.message'], 500);

        $this->assertSame('API error HTTP 500', $result);
    }

    public function testArrayBodyNoPathMatchesReturnsFallbackWithGivenLabel(): void
    {
        $result = $this->formatter->extract(['foo' => 'bar'], ['errors.0.message'], 500, 'SendGrid');

        $this->assertSame('SendGrid error HTTP 500', $result);
    }

    public function testPathResolvingToEmptyStringIsAMissAndFallsThroughToFallback(): void
    {
        $body = ['errors' => [['message' => '']]];

        $result = $this->formatter->extract($body, ['errors.0.message'], 500);

        $this->assertSame('API error HTTP 500', $result);
    }

    public function testPathResolvingToArrayIsAMissAndFallsThroughToNextPath(): void
    {
        $body = ['errors' => [['message' => ['nested' => 'oops']]], 'fallbackMessage' => 'usable'];

        $result = $this->formatter->extract($body, ['errors.0.message', 'fallbackMessage'], 500);

        $this->assertSame('usable', $result);
    }

    public function testPathResolvingToNullIsAMiss(): void
    {
        $body = ['errors' => [['message' => null]], 'fallbackMessage' => 'usable'];

        $result = $this->formatter->extract($body, ['errors.0.message', 'fallbackMessage'], 500);

        $this->assertSame('usable', $result);
    }

    public function testNonStringNonArrayBodyReturnsFallback(): void
    {
        $result = $this->formatter->extract(null, ['errors.0.message'], 503, 'Mailjet');

        $this->assertSame('Mailjet error HTTP 503', $result);
    }

    public function testNumericMessageIsCastToString(): void
    {
        $body = ['errors' => [['message' => 42]]];

        $result = $this->formatter->extract($body, ['errors.0.message'], 400);

        $this->assertSame('42', $result);
    }

    public function testHasErrorIsTrueWhenAnyPathResolvesToANonEmptyScalar(): void
    {
        // Mailjet-style: the first path misses, the second (flat) path hits.
        $body = ['message' => 'flat error'];

        $this->assertTrue($this->formatter->hasError($body, ['errors.0.message', 'message']));
    }

    public function testHasErrorIsFalseWhenNoPathMatches(): void
    {
        $body = ['foo' => 'bar'];

        $this->assertFalse($this->formatter->hasError($body, ['errors.0.message']));
    }

    public function testHasErrorIsFalseWhenTheMatchedPathIsAnEmptyString(): void
    {
        $body = ['errors' => [['message' => '']]];

        $this->assertFalse($this->formatter->hasError($body, ['errors.0.message']));
    }

    public function testHasErrorIsFalseForANonArrayBody(): void
    {
        $this->assertFalse($this->formatter->hasError('connection refused', ['errors.0.message']));
        $this->assertFalse($this->formatter->hasError(null, ['errors.0.message']));
    }
}
