<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Client\HttpClient;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Services\MailConfigService;
use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Contracts\OAuth2ProviderInterface;
use BitApps\SMTP\Mail\Exceptions\OAuthCallbackException;
use BitApps\SMTP\Mail\Exceptions\OAuthStateException;
use BitApps\SMTP\Mail\Http\ApiClient;
use BitApps\SMTP\Mail\OAuth\IdTokenAccount;
use BitApps\SMTP\Mail\OAuth\OAuthCallbackUrl;
use BitApps\SMTP\Mail\OAuth\OAuthFailureReason;
use BitApps\SMTP\Mail\OAuth\OAuthStateCodec;
use BitApps\SMTP\Mail\Providers\ProviderRegistry;
use BitApps\SMTP\Plugin;
use InvalidArgumentException;
use Throwable;

/**
 * OAuth2 consent flow (admin-authed authorize, public state-gated callback); tokens never rendered.
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
     * Build provider consent URL for connection (admin-gated; explicit capability check is defence-in-depth).
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
        ], $transport->extraAuthParams(), $this->loginHint($connection));

        $consentUrl = $transport->authUrl($connection) . '?' . http_build_query($queryParams);

        return Response::success(['url' => $consentUrl]);
    }

    /**
     * Clear OAuth tokens without deleting connection; keeps client_id/secret for re-consent.
     *
     * @return Response
     */
    public function disconnect(Request $request)
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message(__('Unauthorized', 'bit-smtp'));
        }

        $connectionId = (string) $request->get('connection_id', '');
        if ($this->config()->connectionById($connectionId) === null) {
            return Response::error(__('Connection not found', 'bit-smtp'));
        }

        if (!$this->config()->disconnectOAuth($connectionId)) {
            return Response::error(__('Failed to disconnect OAuth account', 'bit-smtp'));
        }

        return Response::success([])->message(__('OAuth account disconnected', 'bit-smtp'));
    }

    /**
     * Public browser redirect, secured by signed state; response is HTML (never tokens).
     */
    public function callback(Request $request): void
    {
        $code  = (string) $request->get('code', '');
        $error = (string) $request->get('error', '');
        $state = (string) $request->get('state', '');

        if ($error !== '') {
            $this->emit($this->errorPage(OAuthFailureReason::fromProviderError($error)));

            return;
        }

        try {
            $decoded = OAuthStateCodec::decode($state);
            $this->exchangeAndStore($decoded['connection_id'], $decoded['provider'], $code);
        } catch (OAuthStateException $e) {
            $this->emit($this->errorPage(OAuthFailureReason::INVALID_STATE));

            return;
        } catch (OAuthCallbackException $e) {
            $this->emit($this->errorPage($e->reason()));

            return;
        } catch (Throwable $e) {
            $this->emit($this->errorPage(OAuthFailureReason::UNKNOWN));

            return;
        }

        $this->emit($this->successPage($decoded['connection_id'], $decoded['provider']));
    }

    /**
     * Emit HTML and exit (overridable for test capture); required for browser redirect.
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

    /**
     * Only a well-formed From address is sent as login_hint, so the provider pre-selects the sending mailbox.
     *
     * @return array<string,string>
     */
    private function loginHint(Connection $connection): array
    {
        $fromEmail = $connection->getFromEmail();

        return filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false ? ['login_hint' => $fromEmail] : [];
    }

    /**
     * Swap the authorization code for tokens and persist them plus the signed-in account's email in one write.
     *
     * @throws OAuthCallbackException with a safe OAuthFailureReason code
     */
    private function exchangeAndStore(string $connectionId, string $provider, string $code): void
    {
        $connection = $this->config()->connectionById($connectionId);
        if ($connection === null || $provider !== $connection->getProvider()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Enumerated reason code; message is never output.
            throw OAuthCallbackException::because(OAuthFailureReason::CONNECTION_NOT_FOUND);
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
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Enumerated reason code; message is never output.
            throw OAuthCallbackException::because(OAuthFailureReason::fromTokenResponse($body));
        }

        if (!$this->config()->saveOAuthGrant($this->withTokens($connection, $body))) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Enumerated reason code; message is never output.
            throw OAuthCallbackException::because(OAuthFailureReason::SAVE_FAILED);
        }
    }

    /**
     * Connection payload carrying the granted tokens, their expiry and the signed-in account's email.
     *
     * @param array<string,mixed> $body token endpoint response
     *
     * @return array<string,mixed>
     */
    private function withTokens(Connection $connection, array $body): array
    {
        $updated = $connection->toArray();

        if (!empty($body['refresh_token'])) {
            $updated['credentials']['refresh_token'] = ['source' => 'database', 'value' => (string) $body['refresh_token']];
        }

        $updated['credentials']['access_token'] = ['source' => 'database', 'value' => (string) $body['access_token']];
        $updated['settings']                    = array_merge($updated['settings'] ?? [], [
            'token_expires_at'                       => time() + (int) ($body['expires_in'] ?? 0),
            MailConfigService::OAUTH_ACCOUNT_SETTING => IdTokenAccount::email($body),
        ]);

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

    /**
     * Popup page reporting failure with a safe OAuthFailureReason code (never raw provider text).
     */
    private function errorPage(string $reason): string
    {
        return $this->page(__('Sign-in did not finish. Close this window and try again.', 'bit-smtp'), [
            'type'   => 'bit-smtp-oauth',
            'status' => 'error',
            'reason' => $reason,
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
