<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Aws\Sns;

/**
 * Confirms an SNS subscription by fetching its SubscribeURL exactly once. The caller must have already
 * verified the message signature; this class independently re-checks that SubscribeURL is an AWS SNS
 * host (SSRF guard) before issuing the outbound GET, so a forged confirmation can never make us fetch
 * an arbitrary URL.
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
     * GET the SubscribeURL. Overridable so tests confirm without a network call; the URL is already
     * host-allow-listed by confirm().
     */
    protected function fetch(string $url): bool
    {
        $response = wp_remote_get($url, ['timeout' => 5]);

        return !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200;
    }
}
