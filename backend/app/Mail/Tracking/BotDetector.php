<?php

namespace BitApps\SMTP\Mail\Tracking;

\defined('ABSPATH') || exit();

/**
 * Classify engagement as machine-generated vs human using UA/IP heuristics; never authoritative.
 */
final class BotDetector
{
    /**
     * Gmail proxy UA substring; proxy fetch is mail client caching, not human open.
     */
    private const GOOGLE_PROXY_UA = 'googleimageproxy';

    /**
     * Apple proxy IPv4 block (17.0.0.0/8); prefetch via proxy treated as automated.
     */
    private const APPLE_PROXY_IPV4_LEADING_OCTET = 17;

    /**
     * Prefetch window (seconds); fire within this is automated, not human read.
     */
    private const PREFETCH_WINDOW_SECONDS = 10;

    /**
     * Scanner UA substrings; heuristic list favors flagging over inflating counts.
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
     * Classify engagement as automated; negative skew not treated as prefetch.
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
