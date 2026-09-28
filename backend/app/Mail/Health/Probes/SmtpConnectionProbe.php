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
 * Real SMTP liveness probe that categorizes failures exactly as live sends would.
 */
class SmtpConnectionProbe implements ConnectionProbeInterface
{
    /**
     * Cap persisted error length to a short single line for safe storage.
     */
    private const MAX_ERROR_LENGTH = 200;

    /**
     * Cap probe socket timeout to keep health checks under PHP max_execution_time.
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
     * Connect, authenticate, and map outcome to ProbeResult; no AUTH debug output.
     */
    public function probe(Connection $connection): ProbeResult
    {
        $this->requirePhpMailer();

        $mailer = $this->newMailer();

        try {
            $this->transport->configure($mailer, $connection);
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
     * Map probe error to ProbeResult using dispatch classifier for consistency.
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
     * Load WordPress bundled PHPMailer; health cron path doesn't call wp_mail().
     */
    private function requirePhpMailer(): void
    {
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
