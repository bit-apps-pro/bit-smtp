<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Heuristically classifies an open/click fire as machine-generated (automated) rather than a human
 * engagement, so counts stay honest instead of inflated. Every rule below is a HEURISTIC over
 * unverified external behavior — user-agent strings and IP ownership can change and are trivially
 * spoofable; treat a positive result as "detected automated", never as an authoritative fact. All
 * thresholds/lists are tunable class constants.
 */
final class BotDetector
{
    /**
     * Gmail proxies remote images through googleusercontent.com; its fetcher presents this UA
     * substring. A proxy fetch is the mail client caching the image, not proof a human opened.
     */
    private const GOOGLE_PROXY_UA = 'googleimageproxy';

    /**
     * Apple owns 17.0.0.0/8. Apple Mail Privacy Protection pre-fetches every remote image through
     * Apple's proxy network on delivery regardless of a real open, so a fetch from this block is
     * treated as automated. (Heuristic: Apple's published block; the exact proxy sub-ranges are not
     * enumerated here.)
     */
    private const APPLE_PROXY_IPV4_LEADING_OCTET = 17;

    /**
     * A fire within this many seconds of the send is almost certainly an automated cache-warm or
     * security scanner rather than a human reading within seconds of receipt. Tunable heuristic.
     */
    private const PREFETCH_WINDOW_SECONDS = 10;

    /**
     * Lowercase UA substrings for security appliances / link scanners that fetch pixels and links to
     * vet them, not to read them. Heuristic list — broad substrings ("bot") favor flagging over
     * inflating human counts.
     *
     * @var array<int,string>
     */
    private const SCANNER_UA_SUBSTRINGS = [
        'proofpoint',
        'barracuda',
        'mimecast',
        'symantec',
        'bot',
        'spider',
        'crawler',
        'preview',
    ];

    /**
     * True when the fire looks machine-generated. Pure: all signals are passed in so the classifier
     * is unit-testable without any WordPress or request state.
     */
    public static function classify(string $userAgent, ?string $ip, int $secondsSinceSend): bool
    {
        $ua = strtolower($userAgent);

        if (strpos($ua, self::GOOGLE_PROXY_UA) !== false) {
            return true;
        }

        if ($ip !== null && self::isAppleProxyIp($ip)) {
            return true;
        }

        foreach (self::SCANNER_UA_SUBSTRINGS as $needle) {
            if (strpos($ua, $needle) !== false) {
                return true;
            }
        }

        // A negative delta (clock skew) is deliberately not treated as a prefetch.
        return $secondsSinceSend >= 0 && $secondsSinceSend < self::PREFETCH_WINDOW_SECONDS;
    }

    /**
     * True when $ip is a valid IPv4 address inside Apple's 17.0.0.0/8 block.
     */
    private static function isAppleProxyIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return (int) explode('.', $ip)[0] === self::APPLE_PROXY_IPV4_LEADING_OCTET;
    }
}
