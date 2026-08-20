<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\OAuth\OAuthCallbackUrl;
use BitApps\SMTP\Mail\OAuth\OAuthStateCodec;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Plugin;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * OAuth2 consent flow. `authorize` (admin-only) mints the provider consent URL carrying a signed
 * state; `callback` is a public, state-gated browser redirect that exchanges the code for tokens
 * and stores them on the connection. Tokens are never rendered to the browser.
 */
class OAuthController
{
    /**
     * @var null|ApiClient
     */
    private $client;

    /**
     * @var null|MailConfigService
     */
    private $config;

    /**
     * @var null|ProviderRegistry
     */
    private $registry;

    public function __construct(
        ?ApiClient $client = null,
        ?MailConfigService $config = null,
        ?ProviderRegistry $registry = null
    ) {
        $this->client   = $client;
        $this->config   = $config;
        $this->registry = $registry;
    }

    /**
     * Build the provider consent URL for a connection. Runs under `cap:admin`; the explicit
     * capability check is defence in depth.
     *
     * @return Response
     */
    public function authorize(Request $request)
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message(__('Unauthorized', 'bit-smtp'));
        }

        $connectionId = (string) $request->get('connection_id', '');
        $provider     = (string) $request->get('provider', '');

        $connection = $this->config()->connectionById($connectionId);
        if ($connection === null) {
            return Response::error(__('Connection not found', 'bit-smtp'));
        }

        if ($provider !== $connection->getProvider()) {
            return Response::error(__('Provider does not match this connection', 'bit-smtp'));
        }

        try {
            $transport = $this->oauth2Transport($provider);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $clientId = (string) $connection->setting('client_id', '');
        if ($clientId === '') {
            return Response::error(__('This connection is missing its Client ID', 'bit-smtp'));
        }

        $queryParams = array_merge([
            'client_id'     => $clientId,
            'redirect_uri'  => OAuthCallbackUrl::get(),
            'response_type' => 'code',
            'scope'         => implode(' ', $transport->scopes()),
            'state'         => OAuthStateCodec::encode($connectionId, $provider),
        ], $transport->extraAuthParams());

        $consentUrl = $transport->authUrl($connection) . '?' . http_build_query($queryParams);

        return Response::success(['url' => $consentUrl]);
    }

    /**
     * Public browser redirect target. Secured solely by the signed `state`: it is verified before
     * any token exchange, and the response is HTML (never JSON, never the tokens).
     */
    public function callback(Request $request): void
    {
        $code  = (string) $request->get('code', '');
        $error = (string) $request->get('error', '');
        $state = (string) $request->get('state', '');

        if ($error !== '') {
            $this->emit($this->errorPage());

            return;
        }

        try {
            $decoded = OAuthStateCodec::decode($state);
            $this->exchangeAndStore($decoded['connection_id'], $decoded['provider'], $code);
        } catch (Throwable $e) {
            $this->emit($this->errorPage());

            return;
        }

        $this->emit($this->successPage($decoded['connection_id'], $decoded['provider']));
    }

    /**
     * Terminal HTML output. Overridable so tests can capture the page without terminating the
     * process. The exit is required: this is a browser redirect, not a JSON API response.
     */
    protected function emit(string $html): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted first-party page; the dynamic parts (heading, JSON payload) are escaped in page().
        echo $html;

        exit;
    }

    private function exchangeAndStore(string $connectionId, string $provider, string $code): void
    {
        $connection = $this->config()->connectionById($connectionId);
        if ($connection === null) {
            throw new RuntimeException('Connection not found for callback.');
        }

        // Reject a state whose provider does not match the connection: never write tokens for one
        // provider onto a connection configured for another.
        if ($provider !== $connection->getProvider()) {
            throw new RuntimeException('Provider does not match this connection.');
        }

        $transport    = $this->oauth2Transport($provider);
        $clientId     = (string) $connection->setting('client_id', '');
        $clientSecret = (string) ($connection->getCredentials()['client_secret']['value'] ?? '');

        $response = $this->client()->postForm($transport->tokenUrl($connection), [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => OAuthCallbackUrl::get(),
        ]);

        $body = $response->getBody();
        if (!$response->isOk() || !\is_array($body) || empty($body['access_token'])) {
            throw new RuntimeException('OAuth token exchange failed.');
        }

        if (!$this->config()->saveConnection($this->withTokens($connection, $body))) {
            throw new RuntimeException('Failed to persist OAuth tokens.');
        }
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function withTokens(Connection $connection, array $body): array
    {
        $updated = $connection->toArray();

        // Providers only return a refresh_token on first consent; never blank an existing one.
        if (!empty($body['refresh_token'])) {
            $updated['credentials']['refresh_token'] = ['source' => 'database', 'value' => (string) $body['refresh_token']];
        }

        $updated['credentials']['access_token']  = ['source' => 'database', 'value' => (string) $body['access_token']];
        $updated['settings']['token_expires_at'] = time() + (int) ($body['expires_in'] ?? 0);

        return $updated;
    }

    /**
     * @throws InvalidArgumentException when the provider is unknown or not OAuth2-capable
     */
    private function oauth2Transport(string $provider): OAuth2ProviderInterface
    {
        if ($provider === '' || !$this->registry()->has($provider)) {
            // translators: %s: provider slug
            throw new InvalidArgumentException(esc_html(\sprintf(__('Unknown provider: %s', 'bit-smtp'), $provider)));
        }

        $transport = $this->registry()->get($provider)->transport();
        if (!$transport instanceof OAuth2ProviderInterface) {
            // translators: %s: provider slug
            throw new InvalidArgumentException(esc_html(\sprintf(__('Provider does not support OAuth2: %s', 'bit-smtp'), $provider)));
        }

        return $transport;
    }

    private function successPage(string $connectionId, string $provider): string
    {
        return $this->page(__('Authorization complete. You can close this window.', 'bit-smtp'), [
            'type'         => 'bit-smtp-oauth',
            'status'       => 'success',
            'connectionId' => $connectionId,
            'provider'     => $provider,
        ]);
    }

    private function errorPage(): string
    {
        return $this->page(__('Authorization failed. Please close this window and try again.', 'bit-smtp'), [
            'type'   => 'bit-smtp-oauth',
            'status' => 'error',
        ]);
    }

    /**
     * @param array<string,string> $message postMessage payload (no secrets)
     */
    private function page(string $heading, array $message): string
    {
        $payload = (string) json_encode($message, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        $heading = htmlspecialchars($heading, \ENT_QUOTES, 'UTF-8');

        return implode("\n", [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head><meta charset="utf-8"><title>Bit SMTP</title></head>',
            '<body>',
            '<p>' . $heading . '</p>',
            '<script>',
            '(function () {',
            '    var message = ' . $payload . ';',
            '    if (window.opener) {',
            '        window.opener.postMessage(message, window.location.origin);',
            '    }',
            '    window.close();',
            '})();',
            '</script>',
            '</body>',
            '</html>',
        ]);
    }

    private function client(): ApiClient
    {
        if ($this->client === null) {
            $this->client = new ApiClient(new HttpClient());
        }

        return $this->client;
    }

    private function config(): MailConfigService
    {
        if ($this->config === null) {
            $this->config = Plugin::instance()->mailConfigService();
        }

        return $this->config;
    }

    private function registry(): ProviderRegistry
    {
        if ($this->registry === null) {
            $this->registry = Plugin::instance()->providerRegistry();
        }

        return $this->registry;
    }
}
