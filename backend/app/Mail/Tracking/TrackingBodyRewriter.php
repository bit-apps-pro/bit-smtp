<?php

namespace BitApps\SMTP\Mail\Tracking;

use BitApps\SMTP\Mail\Message\MailMessage;
use Throwable;

\defined('ABSPATH') || exit();

/**
 * Orchestrates open/click tracking injection for one outgoing HTML message: rewrites clickable links
 * then appends the open pixel, returning a COPY of the message with the rewritten body. Any failure
 * during rewriting returns the ORIGINAL message untouched — a broken rewrite must never send a broken
 * email or a body with a dangling tracker.
 */
final class TrackingBodyRewriter
{
    private const CLICK_PATH = TrackingRoutes::BASE . '/' . TrackingRoutes::CLICK . '/';

    private const OPEN_PATH = TrackingRoutes::BASE . '/' . TrackingRoutes::OPEN . '/';

    /**
     * Return a copy of $message with links rewritten and the open pixel injected for $token, or the
     * original message unchanged if any step throws.
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
