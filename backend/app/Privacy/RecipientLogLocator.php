<?php

namespace BitApps\SMTP\Privacy;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Model\Log;

\defined('ABSPATH') || exit();

/**
 * Resolve log rows for a GDPR request's email (shared by exporter/eraser, re-verified in PHP to block substring leaks).
 */
final class RecipientLogLocator
{
    /**
     * Candidate logs per page (bounds memory, keeps heavily-mailed addresses paginated per WP privacy contract).
     */
    public const PAGE_SIZE = 100;

    /**
     * Return log ids whose recipients include $email for the page and done flag (OFFSET paging, deterministic order).
     *
     * @return array{ids: array<int,int>, done: bool}
     */
    public function locate(string $email, int $page): array
    {
        $email = trim($email);
        if ($email === '') {
            return ['ids' => [], 'done' => true];
        }

        $page = max(1, $page);
        $skip = ($page - 1) * self::PAGE_SIZE;

        $pattern    = '%' . (string) Connection::__callStatic('esc_like', [$email]) . '%';
        $candidates = Log::query()
            ->where('to_addr', 'LIKE', $pattern)
            ->orderBy('id')
            ->skip((string) $skip)
            ->take((string) self::PAGE_SIZE)
            ->get(['id', 'to_addr'])
            ->all();

        $ids = [];
        foreach ($candidates as $log) {
            if ($this->recipientMatches($log->to_addr, $email)) {
                $ids[] = (int) $log->id;
            }
        }

        return ['ids' => $ids, 'done' => \count($candidates) < self::PAGE_SIZE];
    }

    /**
     * Return true when any recipient matches $email (case-insensitive, exact address, rejects LIKE substrings).
     *
     * @param mixed $recipients
     */
    private function recipientMatches($recipients, string $email): bool
    {
        if (!\is_array($recipients)) {
            return false;
        }

        $needle = strtolower($email);
        foreach ($recipients as $recipient) {
            if (!\is_scalar($recipient)) {
                continue;
            }

            $address = $this->extractEmail((string) $recipient);
            if ($address !== '' && strtolower($address) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bare email from a recipient string, unwrapping an RFC "Display Name <email>" form.
     */
    private function extractEmail(string $recipient): string
    {
        if (preg_match('/<([^>]+)>/', $recipient, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($recipient);
    }
}
