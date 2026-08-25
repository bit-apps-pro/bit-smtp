<?php

namespace BitApps\SMTP\Privacy;

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Mail\Support\ModelRows;
use BitApps\SMTP\Model\Log;

\defined('ABSPATH') || exit();

/**
 * Resolves the log rows a GDPR request's email actually appears on, shared by the engagement
 * exporter and eraser so both scope to the exact same recipient set. `to_addr` is a JSON array of
 * recipients (optionally "Name <email>"); the bounded LIKE prunes candidates through the column
 * index, then each candidate is re-verified in PHP so a substring can never leak another
 * recipient's data.
 */
final class RecipientLogLocator
{
    /**
     * Candidate logs scanned per exporter/eraser page. Bounds memory and the follow-up WHERE IN so a
     * heavily-mailed address stays paginated per WordPress's privacy contract.
     */
    public const PAGE_SIZE = 100;

    /**
     * Log ids whose recipients include $email for the 1-based $page, plus whether this was the last
     * page. Candidates are ordered by `id` so OFFSET paging is deterministic across the successive
     * exporter/eraser calls (MySQL gives no stable order without an explicit key), never skipping a
     * boundary row.
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

        // esc_like proxies to $wpdb via Connection's dynamic dispatch so LIKE wildcards in the email
        // (e.g. an underscore) are matched literally rather than as single-char wildcards.
        $pattern    = '%' . (string) Connection::__callStatic('esc_like', [$email]) . '%';
        $candidates = ModelRows::toArray(
            Log::query()
                ->where('to_addr', 'LIKE', $pattern)
                ->orderBy('id')
                ->skip((string) $skip)
                ->take((string) self::PAGE_SIZE)
                ->get(['id', 'to_addr'])
        );

        $ids = [];
        foreach ($candidates as $log) {
            if ($this->recipientMatches($log->to_addr, $email)) {
                $ids[] = (int) $log->id;
            }
        }

        return ['ids' => $ids, 'done' => \count($candidates) < self::PAGE_SIZE];
    }

    /**
     * True when any of a log's recipients resolves to $email (case-insensitive, exact address), so a
     * LIKE substring hit on an unrelated address is rejected before its data is ever touched.
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
