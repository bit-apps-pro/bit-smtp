<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Rewrites absolute http(s) <a href> targets in an HTML email body to signed, self-hosted click
 * URLs so clicks can be attributed without leaking recipient identity. Only genuinely clickable
 * external links are touched; mailto/tel/#fragment/relative/protocol-relative hrefs, over-length
 * URLs, and links already pointing at our own track path are left byte-for-byte intact.
 */
final class LinkRewriter
{
    /**
     * Original hrefs longer than this are left untouched to avoid pathological token/URL bloat.
     */
    private const MAX_TRACKED_URL = 2048;

    private string $clickBaseUrl;

    /**
     * @param string $clickBaseUrl the self-hosted click endpoint base, e.g. home_url('bit-smtp/track/click/')
     */
    public function __construct(string $clickBaseUrl)
    {
        $this->clickBaseUrl = $clickBaseUrl;
    }

    /**
     * Rewrite every eligible absolute http(s) href in $html to a signed click URL carrying $token.
     */
    public function rewrite(string $html, string $token): string
    {
        // \shref (not \bhref) so a preceding whitespace is required — otherwise `data-href` (and any
        // *-href attribute) would match and the real href be left untracked.
        $rewritten = preg_replace_callback(
            '/(<a\b[^>]*?\shref\s*=\s*)(["\'])(.*?)\2/is',
            function (array $matches) use ($token): string {
                $url = $matches[3];
                if (!$this->isRewritable($url)) {
                    return $matches[0];
                }

                return $matches[1] . $matches[2] . $this->clickUrl($url, $token) . $matches[2];
            },
            $html
        );

        // preg_replace_callback returns null on a PCRE failure (e.g. backtrack-limit exhaustion on a
        // huge/pathological body); fall back to the untouched body so a rewrite failure never emits an
        // empty email (the (string) cast would silently turn that null into '').
        return $rewritten ?? $html;
    }

    /**
     * True only for an absolute http(s) URL within the length cap that does not already point at our
     * own click path (idempotency guard against a double rewrite).
     */
    private function isRewritable(string $url): bool
    {
        if (\strlen($url) > self::MAX_TRACKED_URL) {
            return false;
        }

        if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
            return false;
        }

        return strpos($url, $this->clickBaseUrl) !== 0;
    }

    /**
     * The signed click URL for $url: base endpoint + a token binding the message token and destination.
     */
    private function clickUrl(string $url, string $token): string
    {
        return $this->clickBaseUrl . TokenSigner::sign(['t' => $token, 'u' => $url]);
    }
}
