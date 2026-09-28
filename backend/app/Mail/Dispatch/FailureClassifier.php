<?php

namespace BitApps\SMTP\Mail\Dispatch;

use BitApps\SMTP\Mail\Message\SendResult;

/**
 * Map SendResult outcome to FailureCategory via HTTP status or message-content heuristics.
 */
class FailureClassifier
{
    private const AUTH_PATTERN = '/\b(auth|authenticat|credential|username|password|535|534)\b/';

    private const RATE_LIMITED_PATTERN = '/(rate.?limit|too many|throttl|\b429\b)/';

    private const INVALID_RECIPIENT_PATTERN = '/(\b(550|551|553)\b|user unknown|no such user|mailbox (unavailable|not found)|recipient.*(rejected|invalid|unknown)|invalid.*recipient|address rejected)/';

    private const TRANSIENT_PATTERN = '/(timeout|timed out|could not connect|connection (refused|failed|reset)|network|temporar|try again|greylist|deferred|\b(421|450|451|452)\b)/';

    private const PERMANENT_PATTERN = '/(\b(552|554)\b|blocked|blacklist|spam|policy|permanently)/';

    /**
     * Classify a send outcome into a FailureCategory constant; treats only HTTP status codes in range 100-599 (SMTP's PHPMailer codes 0/1/2 must never be read as HTTP statuses).
     */
    public function classify(SendResult $result): string
    {
        if ($result->isOk()) {
            return FailureCategory::OK;
        }

        if ($result->isAccepted()) {
            return FailureCategory::PERMANENT;
        }

        $code = $result->getCode();
        $msg  = strtolower((string) $result->getError());

        if ($code !== null && is_numeric($code) && (int) $code >= 100 && (int) $code <= 599) {
            $category = $this->classifyHttpStatus((int) $code, $msg);

            if ($category !== null) {
                return $category;
            }
        }

        return $this->classifyMessage($msg);
    }

    /**
     * Map an HTTP status code to a category, or null when the message-heuristic path should decide instead.
     */
    private function classifyHttpStatus(int $status, string $msg): ?string
    {
        if ($status === 429) {
            return FailureCategory::RATE_LIMITED;
        }

        if ($status === 401 || $status === 403) {
            return FailureCategory::AUTH;
        }

        if ($status === 408 || $status >= 500) {
            return FailureCategory::TRANSIENT;
        }

        if ($status === 400 || $status === 421 || $status === 422) {
            return preg_match(self::INVALID_RECIPIENT_PATTERN, $msg) === 1
                ? FailureCategory::INVALID_RECIPIENT
                : FailureCategory::PERMANENT;
        }

        if ($status >= 400) {
            return FailureCategory::PERMANENT;
        }

        return null;
    }

    /**
     * Map an error message to a category via ordered regex heuristics (first match wins); TRANSIENT is tested before INVALID_RECIPIENT to avoid misclassifying 4xx/greylist rejections as permanent.
     */
    private function classifyMessage(string $msg): string
    {
        if (preg_match(self::AUTH_PATTERN, $msg) === 1) {
            return FailureCategory::AUTH;
        }

        if (preg_match(self::RATE_LIMITED_PATTERN, $msg) === 1) {
            return FailureCategory::RATE_LIMITED;
        }

        if (preg_match(self::TRANSIENT_PATTERN, $msg) === 1) {
            return FailureCategory::TRANSIENT;
        }

        if (preg_match(self::INVALID_RECIPIENT_PATTERN, $msg) === 1) {
            return FailureCategory::INVALID_RECIPIENT;
        }

        if (preg_match(self::PERMANENT_PATTERN, $msg) === 1) {
            return FailureCategory::PERMANENT;
        }

        return FailureCategory::TRANSIENT;
    }
}
