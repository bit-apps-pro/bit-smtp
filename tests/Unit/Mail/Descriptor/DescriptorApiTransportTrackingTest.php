<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Descriptor;

use BitApps\SMTP\Mail\Auth\AuthorizationResolver;
use BitApps\SMTP\Mail\Aws\SigV4Signer;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\Http\ApiResponse;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\OAuth\OAuth2TokenProvider;
use BitApps\SMTP\Mail\Providers\Brevo\BrevoProvider;
use BitApps\SMTP\Mail\Providers\Postmark\PostmarkProvider;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Mockery;

/**
 * Exercises the descriptor transport's tracking-channel and message-id wiring through the real
 * Postmark and Brevo provider descriptors.
 *
 * @internal
 *
 * @coversNothing
 */
class DescriptorApiTransportTrackingTest extends BaseUnitTestCase
{
    private $apiClient;

    private AuthorizationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiClient = Mockery::mock(ApiClient::class);
        // api_key auth never touches the token provider or signer, but the resolver requires both.
        $this->resolver = new AuthorizationResolver(
            Mockery::mock(OAuth2TokenProvider::class),
            new SigV4Signer()
        );
    }

    public function testPostmarkSendPostsMetadataAndReturnsProviderMessageId(): void
    {
        $posted = $this->captureBodyOn('https://api.postmarkapp.com/email', new ApiResponse(200, ['MessageID' => 'pm-9']));

        $result = (new PostmarkProvider($this->apiClient, $this->resolver))
            ->transport()
            ->send($this->message(['metadata' => ['bit_tracking_id' => 'u-1']]), $this->apiConnection('postmark'));

        $this->assertSame(['bit_tracking_id' => 'u-1'], $posted()['Metadata']);
        $this->assertSame('pm-9', $result->getMessageId());
        $this->assertTrue($result->isOk());
    }

    public function testPostmarkSendWithoutMetadataOmitsTheMetadataKey(): void
    {
        $posted = $this->captureBodyOn('https://api.postmarkapp.com/email', new ApiResponse(200, ['MessageID' => 'pm-9']));

        (new PostmarkProvider($this->apiClient, $this->resolver))
            ->transport()
            ->send($this->message(), $this->apiConnection('postmark'));

        $this->assertArrayNotHasKey('Metadata', $posted());
    }

    public function testBrevoSendPostsTrackingHeaderAndReturnsProviderMessageId(): void
    {
        $posted = $this->captureBodyOn('https://api.brevo.com/v3/smtp/email', new ApiResponse(201, ['messageId' => '<b@r>']));

        $result = (new BrevoProvider($this->apiClient, $this->resolver))
            ->transport()
            ->send($this->message(['headers' => ['X-Mailin-custom' => 'u-1']]), $this->apiConnection('brevo'));

        $this->assertSame(['X-Mailin-custom' => 'u-1'], $posted()['headers']);
        $this->assertSame('<b@r>', $result->getMessageId());
        $this->assertTrue($result->isOk());
    }

    /**
     * Stub the mocked client's post() for $url, capturing the decoded JSON body it receives.
     *
     * @return callable():array a lazy accessor for the captured body (call after send())
     */
    private function captureBodyOn(string $url, ApiResponse $response): callable
    {
        $captured = null;

        $this->apiClient->shouldReceive('setHeaders')->once()->andReturnSelf();
        $this->apiClient->shouldReceive('post')
            ->once()
            ->with($url, Mockery::on(function (string $json) use (&$captured): bool {
                $captured = json_decode($json, true);

                return true;
            }))
            ->andReturn($response);

        return static function () use (&$captured): array {
            return $captured;
        };
    }

    private function message(array $overrides = []): MailMessage
    {
        return MailMessage::fromArray(array_merge([
            'to' => ['to@example.com'], 'subject' => 'Subject', 'body' => 'Body text',
        ], $overrides));
    }

    private function apiConnection(string $provider): Connection
    {
        return Connection::fromArray([
            'id'          => 'conn_1', 'provider' => $provider, 'kind' => 'api',
            'fromEmail'   => 'from@example.com', 'fromName' => 'From Name',
            'credentials' => ['api_key' => ['source' => 'database', 'value' => 'TESTTOKEN']],
        ]);
    }
}
