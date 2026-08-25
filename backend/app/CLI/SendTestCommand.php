<?php

namespace BitApps\SMTP\CLI;

use BitApps\SMTP\Mail\Dispatch\WpMailBridge;

\defined('ABSPATH') || exit();

/**
 * `wp bit-smtp send-test <to>`: send a test email through the plugin's wp_mail dispatch path and
 * report success or the transport debug output on failure. Reuses WpMailBridge exactly as the REST
 * test-mail controller does.
 */
final class SendTestCommand
{
    private WpMailBridge $mailBridge;

    public function __construct(WpMailBridge $mailBridge)
    {
        $this->mailBridge = $mailBridge;
    }

    /**
     * Validate the recipient, dispatch a debug-enabled test send, then report the outcome; on failure
     * the transport's debug lines are printed before the terminal error.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function run(array $args, array $assocArgs, CliReporter $reporter): void
    {
        $to = trim($args[0] ?? '');
        if (!is_email($to)) {
            $reporter->error(\sprintf('Not a valid email address: %s', $to === '' ? '(empty)' : $to));

            return;
        }

        $subject = $this->stringArg($assocArgs, 'subject', 'Bit SMTP test email');
        $message = $this->stringArg($assocArgs, 'message', 'This is a test email sent via Bit SMTP (WP-CLI) to verify your email configuration.');

        $this->mailBridge->setDebug(true);
        wp_mail($to, $subject, $message);

        if ($this->mailBridge->isFailed() === false) {
            $reporter->success(\sprintf('Test email sent to %s', $to));

            return;
        }

        foreach ($this->mailBridge->getDebugOutput() as $line) {
            $reporter->line($line);
        }
        $reporter->error(\sprintf('Test email to %s failed to send.', $to));
    }

    /**
     * Read a non-empty string flag, falling back to the given default when absent or blank.
     *
     * @param array<string,string> $assocArgs
     */
    private function stringArg(array $assocArgs, string $key, string $default): string
    {
        $value = isset($assocArgs[$key]) ? trim((string) $assocArgs[$key]) : '';

        return $value === '' ? $default : $value;
    }
}
