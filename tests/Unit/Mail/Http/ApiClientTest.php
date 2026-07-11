<?php

namespace BitApps\SMTP\Tests\Unit\Mail\Http;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * HttpClient is `final`, so Mockery cannot subclass it to mock it directly: these tests inject
 * a real HttpClient and stub the WP HTTP functions it calls internally via Brain Monkey. No
 * real HTTP occurs.
 *
 * @internal
 *
 * @coversNothing
 */
class ApiClientTest extends BaseUnitTestCase
{
    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_parse_args')->alias(static function ($args, $defaults = []) {
            return array_merge($defaults, (array) $args);
        });
        Functions\when('is_wp_error')->justReturn(false);

        $this->client = new ApiClient(new HttpClient());
    }

    public function testGetAppendsQueryStringAndSendsHeaders(): void
    {
        $this->stubRemoteRequest(function ($url, $options): bool {
            $this->assertSame('https://api.example.com/messages?page=2', $url);
            $this->assertSame('GET', $options['method']);
            $this->assertSame(['Authorization' => 'Bearer token'], $options['headers']);
            $this->assertNull($options['body']);

            return true;
        });
        $this->stubRetrieve('', 200);

        $response = $this->client
            ->setHeaders(['Authorization' => 'Bearer token'])
            ->get('https://api.example.com/messages', ['page' => 2]);

        $this->assertSame(200, $response->getStatus());
    }

    public function testPostJsonEncodesArrayBodyAndDecodesJsonResponse(): void
    {
        $this->stubRemoteRequest(function ($url, $options): bool {
            $this->assertSame('https://api.example.com/send', $url);
            $this->assertSame('POST', $options['method']);
            $this->assertSame('{"to":"a@example.com"}', $options['body']);
            $this->assertSame(['Content-Type' => 'application/json'], $options['headers']);

            return true;
        });
        $this->stubRetrieve('{"id":"abc"}', 202, ['Content-Type' => 'application/json']);

        $response = $this->client
            ->setHeaders(['Content-Type' => 'application/json'])
            ->post('https://api.example.com/send', ['to' => 'a@example.com']);

        $this->assertTrue($response->isOk());
        $this->assertSame(202, $response->getStatus());
        $this->assertSame(['id' => 'abc'], $response->getBody());
    }

    public function testPutSendsRawStringBodyUnchanged(): void
    {
        $this->stubRemoteRequest(function ($url, $options): bool {
            $this->assertSame('PUT', $options['method']);
            $this->assertSame('raw-payload', $options['body']);

            return true;
        });
        $this->stubRetrieve('', 204);

        $response = $this->client->put('https://api.example.com/messages/1', 'raw-payload');

        $this->assertTrue($response->isOk());
    }

    public function testDeleteWithNoBodySendsNull(): void
    {
        $this->stubRemoteRequest(function ($url, $options): bool {
            $this->assertSame('DELETE', $options['method']);
            $this->assertNull($options['body']);

            return true;
        });
        $this->stubRetrieve('', 204);

        $this->client->delete('https://api.example.com/messages/1');
    }

    public function testFailureStatusIsNotOk(): void
    {
        $this->stubRemoteRequest(null);
        $this->stubRetrieve('{"error":"bad request"}', 400, ['Content-Type' => 'application/json']);

        $response = $this->client->post('https://api.example.com/send', ['to' => 'a@example.com']);

        $this->assertFalse($response->isOk());
        $this->assertSame(400, $response->getStatus());
    }

    public function testAddHeaderUpdatesSingleHeaderWithoutRemovingOthers(): void
    {
        $this->client->setHeaders(['A' => '1', 'B' => '2']);
        $this->client->addHeader('B', '3');

        $this->stubRemoteRequest(function ($url, $options): bool {
            $this->assertSame(['A' => '1', 'B' => '3'], $options['headers']);

            return true;
        });
        $this->stubRetrieve('', 200);

        $this->client->get('https://api.example.com');
    }

    public function testSetHeadersReplacesPreviouslySetHeaders(): void
    {
        $this->client->setHeaders(['A' => '1']);
        $this->client->setHeaders(['B' => '2']);

        $this->stubRemoteRequest(function ($url, $options): bool {
            $this->assertSame(['B' => '2'], $options['headers']);

            return true;
        });
        $this->stubRetrieve('', 200);

        $this->client->get('https://api.example.com');
    }

    public function testWithHeadersMergesOntoExistingHeaders(): void
    {
        $this->client->setHeaders(['A' => '1']);
        $this->client->withHeaders(['B' => '2']);

        $this->stubRemoteRequest(function ($url, $options): bool {
            $this->assertSame(['A' => '1', 'B' => '2'], $options['headers']);

            return true;
        });
        $this->stubRetrieve('', 200);

        $this->client->get('https://api.example.com');
    }

    public function testRecoversJsonArrayBodyThatHttpClientTreatedAsEmpty(): void
    {
        $this->stubRemoteRequest(null);
        // "[]" decodes to an empty array, which HttpClient's own opportunistic decode
        // discards as "empty" and falls back to the raw string — ApiClient must recover it.
        $this->stubRetrieve('[]', 200, ['Content-Type' => 'application/json; charset=utf-8']);

        $response = $this->client->get('https://api.example.com');

        $this->assertSame([], $response->getBody());
    }

    public function testNonJsonContentTypeBodyIsReturnedAsRawString(): void
    {
        $this->stubRemoteRequest(null);
        $this->stubRetrieve('plain text', 200, ['Content-Type' => 'text/plain']);

        $response = $this->client->get('https://api.example.com');

        $this->assertSame('plain text', $response->getBody());
    }

    public function testWpErrorProducesFailureApiResponseWithZeroStatus(): void
    {
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_request')->justReturn(new WP_Error('http_request_failed', 'Could not resolve host'));

        $response = $this->client->get('https://api.example.com');

        $this->assertSame(0, $response->getStatus());
        $this->assertFalse($response->isOk());
        $this->assertSame('Could not resolve host', $response->getBody());
    }

    private function stubRemoteRequest(?callable $assertion, string $return = 'response-fixture'): void
    {
        if ($assertion === null) {
            Functions\when('wp_remote_request')->justReturn($return);

            return;
        }

        Functions\expect('wp_remote_request')
            ->once()
            ->andReturnUsing(static function ($url, $options) use ($assertion, $return) {
                $assertion($url, $options);

                return $return;
            });
    }

    private function stubRetrieve(string $body, int $status, array $headers = []): void
    {
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('wp_remote_retrieve_headers')->justReturn($headers);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
    }
}
