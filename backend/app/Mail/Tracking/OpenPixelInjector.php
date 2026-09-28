<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Inject 1x1 transparent open-tracking pixel into HTML email body before </body> or at end.
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
     * Inject signed open-pixel image into HTML before </body> or at end; return modified HTML.
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
     * Generate hidden tracking image tag; src uses only trusted parts (endpoint + token) to prevent injection.
     */
    private function pixel(string $token): string
    {
        $src = $this->openBaseUrl . TokenSigner::sign(['t' => $token]);

        return '<img src="' . $src . '" width="1" height="1" alt="" style="display:none" />';
    }
}
