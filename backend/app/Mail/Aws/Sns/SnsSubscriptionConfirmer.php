<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Aws\Sns;

/**
 * Confirm SNS subscription by fetching SubscribeURL with SSRF validation.
 */
class SnsSubscriptionConfirmer
{
    /**
     * @return bool true when the subscription was confirmed (a 200 from a trusted SubscribeURL)
     */
    public function confirm(SnsMessage $message): bool
    {
        $url = $message->subscribeUrl();
        if (!SnsEndpoint::isAwsSnsUrl($url)) {
            return false;
        }

        return $this->fetch($url);
    }

    /**
     * Fetch the SubscribeURL; overridable for testing.
     */
    protected function fetch(string $url): bool
    {
        $response = wp_remote_get($url, ['timeout' => 5]);

        return !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200;
    }
}
