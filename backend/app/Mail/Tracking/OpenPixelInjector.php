<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Injects a 1x1 transparent open-tracking pixel into an HTML email body so an open can be attributed
 * to the message's signed token. The pixel is placed just before </body> when present, otherwise
 * appended to the end.
 */
final class OpenPixelInjector
{
    private string $openBaseUrl;

    /**
     * @param string $openBaseUrl the self-hosted open endpoint base, e.g. home_url('bit-smtp/track/open/')
     */
    public function __construct(string $openBaseUrl)
    {
        $this->openBaseUrl = $openBaseUrl;
    }

    /**
     * Return $html with the signed open-pixel <img> injected before </body> (else appended).
     */
    public function inject(string $html, string $token): string
    {
        $pixel    = $this->pixel($token);
        $position = stripos($html, '</body>');
        if ($position === false) {
            return $html . $pixel;
        }

        return substr($html, 0, $position) . $pixel . substr($html, $position);
    }

    /**
     * The hidden tracking <img>. The src is composed only of trusted parts (our own endpoint plus a
     * URL-safe base64url/hex token), so it carries no attribute-injection surface.
     */
    private function pixel(string $token): string
    {
        $src = $this->openBaseUrl . TokenSigner::sign(['t' => $token]);

        return '<img src="' . $src . '" width="1" height="1" alt="" style="display:none" />';
    }
}
