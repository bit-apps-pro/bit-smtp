<?php

namespace BitApps\SMTP\Mail\OAuth;

final class OAuthCallbackUrl
{
    private const CALLBACK_PATH = '/bit-smtp/oauth/callback';

    public static function get(): string
    {
        return home_url(self::CALLBACK_PATH);
    }
}
