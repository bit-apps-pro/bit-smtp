<?php

namespace BitApps\SMTP\Mail\Message;

/**
 * Tells real HTML apart from text that merely contains angle brackets, for mail whose headers don't say which it is.
 */
final class HtmlMarkup
{
    private const TAG_PATTERN = '/<\/?[a-z][a-z0-9-]*(?:[ \t]+[a-z_:][a-z0-9_:.-]*=(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s"\'=<>`]+))*[ \t]*\/?>/i';

    /**
     * Whether the text holds a tag on one line whose attributes all have values, so "if a<b and c>d" and "<me@example.org>" don't count.
     */
    public static function isPresentIn(string $text): bool
    {
        return preg_match(self::TAG_PATTERN, $text) === 1;
    }
}
