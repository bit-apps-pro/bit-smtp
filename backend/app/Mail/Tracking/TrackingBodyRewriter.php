<?php

namespace BitApps\SMTP\Mail\Tracking;

use BitApps\SMTP\Mail\Message\MailMessage;
use Throwable;

\defined('ABSPATH') || exit();

/**
 * Orchestrate tracking injection: rewrite links and append pixel; return original on failure to prevent broken email.
 */
final class TrackingBodyRewriter
{
    private const CLICK_PATH = TrackingRoutes::BASE . '/' . TrackingRoutes::CLICK . '/';

    private const OPEN_PATH = TrackingRoutes::BASE . '/' . TrackingRoutes::OPEN . '/';

    /**
     * Return message copy with rewritten links and injected open pixel, or original on any error.
     */
    public function rewrite(MailMessage $message, string $token): MailMessage
    {
        try {
            $linkRewriter  = new LinkRewriter(home_url(self::CLICK_PATH));
            $pixelInjector = new OpenPixelInjector(home_url(self::OPEN_PATH));

            $html = $linkRewriter->rewrite($message->getBody(), $token);
            $html = $pixelInjector->inject($html, $token);

            return MailMessage::fromArray(array_merge($message->toArray(), ['body' => $html]));
        } catch (Throwable $e) {
            return $message;
        }
    }
}
