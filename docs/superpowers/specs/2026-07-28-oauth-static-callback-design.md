# OAuth Static Callback Design

Date: 2026-07-28

## Problem

Bit SMTP currently gives OAuth providers a WordPress REST URL:

```text
https://site.example/wp-json/bit-smtp/v1/mail/oauth/callback
```

Some WordPress sites or security layers block `wp-json`, which prevents the provider from returning
the authorization code to Bit SMTP. The callback needs a public, non-REST URL without weakening the
existing signed-state checks.

## Decision

Use the WP Kit `StaticRouter` development branch to expose this callback:

```text
https://site.example/bit-smtp/oauth/callback
```

Pin WP Kit to commit `f27a354f423984e9a61dd960acbcc4cc939298e8` from
`dev-feat/static-router`. Do not keep the former REST callback as an alias because this OAuth flow
has only been exercised in the current test environment and does not require backward
compatibility.

## Routing

The callback route and delivery-webhook route share the `bit-smtp` path prefix:

```text
/bit-smtp/oauth/callback
/bit-smtp/{connection_id}/{webhook_secret}
```

Routing must resolve the exact static route before the dynamic webhook route:

1. Register the WP Kit static `oauth/callback` GET route at `template_redirect` priority 10.
2. Register `WebhookRouter::match()` at `template_redirect` priority 20.
3. The OAuth controller terminates a matched callback response, so the webhook router is never
   invoked for the callback.
4. A webhook URL does not match the static route and falls through to the dynamic router.
5. Unrelated requests fall through to normal WordPress handling.

This preserves the webhook endpoint's existing method and body-size enforcement while removing its
current opportunity to classify `oauth/callback` as a webhook connection and secret.

## WP Kit Adapter

The pinned `StaticRouter` branch compares its route regex against the full `REQUEST_URI`. OAuth
providers append `code`, `state`, and possibly `error` query parameters, so the current branch does
not match an OAuth callback without a narrow adapter.

Create an OAuth static-router adapter that:

1. Owns the WP Kit `StaticRouter` instance and its static `Router`.
2. Registers `oauth/callback` with `OAuthController::callback`.
3. Replaces only that instance's default `template_redirect` callback with an adapter callback at
   the same priority.
4. Temporarily supplies the path component of `REQUEST_URI` while WP Kit performs route matching.
5. Leaves `$_GET` and `$_REQUEST` untouched so WP Kit's `Request` still receives `code`, `state`,
   and `error`.
6. Restores `REQUEST_URI` if WP Kit returns. A normal OAuth callback exits through
   `OAuthController::emit()`.

The workaround remains isolated so it can be removed when WP Kit matches against a parsed path.

## URL Generation

`OAuthCallbackUrl::get()` returns:

```php
home_url('/bit-smtp/oauth/callback')
```

The same value is used for:

- provider metadata displayed in the connection editor;
- the provider authorization request;
- the authorization-code token exchange.

Using one generator prevents redirect URI mismatches. WordPress supplies the site scheme, host,
port, and subdirectory prefix.

## REST Changes

Remove `Route::get('mail/oauth/callback', ...)` from `backend/hooks/api.php`.

Keep `mail/oauth/authorize` as the admin-protected REST endpoint. Only the public browser callback
moves outside REST.

## Security And Errors

The routing change does not alter OAuth trust decisions:

- `OAuthStateCodec` signs, expires, and validates state before token exchange.
- The state provider must match the stored connection provider.
- Tokens are stored through the existing encrypted credential path.
- Tokens and provider errors are never rendered in callback HTML.
- Provider cancellation, missing or invalid state, token exchange failure, and persistence failure
  continue to return the generic failure page.
- Only GET is registered for the static OAuth callback.

The callback remains public because an OAuth provider cannot supply a WordPress nonce or logged-in
session. Signed state is its authentication boundary.

## Dependency

Change the Composer requirement to:

```json
"bitapps/wp-kit": "dev-feat/static-router#f27a354f423984e9a61dd960acbcc4cc939298e8"
```

Regenerate `composer.lock` and the scoped dependency tree through Composer. Do not manually edit
generated vendor files.

## Tests

Use test-first development for each behavior:

1. `OAuthCallbackUrl` returns the root-install static URL.
2. `OAuthCallbackUrl` preserves a WordPress subdirectory install.
3. Provider metadata exposes the static URL.
4. Authorization and token exchange use the identical static redirect URI.
5. The REST server no longer registers `mail/oauth/callback`.
6. The static router registers a public GET `oauth/callback` route.
7. A callback URI with query parameters reaches the OAuth action with all parameters intact.
8. Static OAuth dispatch runs before dynamic webhook dispatch.
9. Existing webhook path parsing and method/body-size behavior remain covered.
10. Invalid state and provider mismatch tests continue to prove that no token is stored.

Run focused unit and integration tests after each change, followed by PHP syntax checks, formatter,
static analysis, and the complete PHP test suites.

## Acceptance Criteria

- The copyable OAuth redirect URL contains no `/wp-json/`.
- A Google OAuth authorization-code callback succeeds at `/bit-smtp/oauth/callback`.
- The token request uses that exact redirect URI.
- The former REST callback returns no registered Bit SMTP REST route.
- Delivery webhooks still resolve and enforce their current safeguards.
- Root and subdirectory WordPress installations produce correct callback URLs.
- No client IDs, secrets, authorization codes, state values, or tokens appear in tests,
  documentation output, or logs.
