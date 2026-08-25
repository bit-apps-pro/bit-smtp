<?php

namespace BitApps\SMTP\Mail\Health\Probes;

use BitApps\SMTP\Mail\Connections\Connection;
use BitApps\SMTP\Mail\Dispatch\FailureClassifier;
use BitApps\SMTP\Mail\Health\Contracts\ConnectionProbeInterface;
use BitApps\SMTP\Mail\Health\ProbeResult;
use BitApps\SMTP\Mail\Message\SendResult;
use BitApps\SMTP\Mail\Transport\SmtpTransport;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Real SMTP liveness probe: connect + EHLO + AUTH (no message sent), reusing the send transport's
 * PHPMailer configuration and the dispatch failure classifier so a probe failure is categorized
 * exactly as a live send failure would be.
 */
class SmtpConnectionProbe implements ConnectionProbeInterface
{
    /**
     * Cap the persisted/surfaced error length; a PHPMailer connect error is a safe host/auth string,
     * but keep it short and single-line so nothing unbounded is ever stored.
     */
    private const MAX_ERROR_LENGTH = 200;

    /**
     * Cap the per-probe socket timeout (seconds). A liveness probe only needs a quick connect+auth, so
     * it caps the send timeout to bound a "Check now"/cron sweep of several dead hosts well under PHP's
     * max_execution_time (a full send keeps the longer configured send_timeout_seconds).
     */
    private const PROBE_TIMEOUT_SECONDS = 10;

    private SmtpTransport $transport;

    private FailureClassifier $classifier;

    public function __construct(SmtpTransport $transport, FailureClassifier $classifier)
    {
        $this->transport  = $transport;
        $this->classifier = $classifier;
    }

    /**
     * Connect and authenticate against the SMTP server, always closing the socket, and map the
     * outcome to a ProbeResult. Debug output stays off so no AUTH payload is ever captured.
     */
    public function probe(Connection $connection): ProbeResult
    {
        $this->requirePhpMailer();

        $mailer = $this->newMailer();

        try {
            $this->transport->configure($mailer, $connection);
            // Shorten the socket timeout for a liveness probe so a sweep of dead hosts can't run long.
            $mailer->Timeout = min($mailer->Timeout, self::PROBE_TIMEOUT_SECONDS);
            $mailer->smtpConnect();

            return ProbeResult::ok();
        } catch (PHPMailerException $e) {
            return $this->toFailure($e->getMessage(), (string) $e->getCode());
        } finally {
            $mailer->smtpClose();
        }
    }

    /**
     * Build the PHPMailer the probe drives; a seam so a test can supply a socket-free double.
     */
    protected function newMailer(): PHPMailer
    {
        return new PHPMailer(true);
    }

    /**
     * Map a probe error into a sanitized, classified ProbeResult, reusing the dispatch classifier so
     * a probe failure lands in the same FailureCategory a live send would.
     */
    private function toFailure(string $message, string $code): ProbeResult
    {
        $error = $this->sanitize($message);

        return ProbeResult::failure(
            $error,
            $this->classifier->classify(SendResult::failure($error, $code))
        );
    }

    /**
     * Reduce a PHPMailer error to a short, single-line string safe to persist and surface.
     */
    private function sanitize(string $message): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_substr($clean, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * Load WordPress's bundled PHPMailer classes; the health cron path never calls wp_mail(), so
     * they are not loaded for us the way a normal send would.
     */
    private function requirePhpMailer(): void
    {
        // Guarded so ABSPATH/WPINC read as defined; both always exist in the WP runtime the cron runs in.
        if (!\defined('ABSPATH') || !\defined('WPINC')) {
            return;
        }

        if (!class_exists(PHPMailer::class)) {
            require_once \ABSPATH . \WPINC . '/PHPMailer/PHPMailer.php';
        }
        if (!class_exists(SMTP::class)) {
            require_once \ABSPATH . \WPINC . '/PHPMailer/SMTP.php';
        }
        if (!class_exists(PHPMailerException::class)) {
            require_once \ABSPATH . \WPINC . '/PHPMailer/Exception.php';
        }
    }
}
