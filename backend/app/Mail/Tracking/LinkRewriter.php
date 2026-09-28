<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Rewrite absolute http(s) hrefs in email body to signed click URLs for attribution without leaking identity.
 */
final class LinkRewriter
{
    /**
     * Maximum tracked URL length; longer URLs left untouched to avoid token/URL bloat.
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
     * Rewrite eligible absolute http(s) hrefs to signed click URLs; null-safe on PCRE failure.
     */
    public function rewrite(string $html, string $token): string
    {
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

        return $rewritten ?? $html;
    }

    /**
     * Check if URL is rewritable: absolute http(s), within length cap, and not already a click path.
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
