<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPKit\Helpers\Arr;
use BitApps\SMTP\Deps\BitApps\WPKit\Hooks\Hooks;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\Deps\BitApps\WPKit\Utils\Capabilities;
use BitApps\SMTP\HTTP\Requests\MailConfigStoreRequest;
use BitApps\SMTP\HTTP\Requests\MailTestRequest;
use BitApps\SMTP\HTTP\Requests\ResendEditRequest;
use BitApps\SMTP\HTTP\Services\LogBodyRedactor;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Views\EmailTemplate;
use Exception;

class SMTPController
{
    public function index()
    {
        return Response::success([
            'mailConfig' => Plugin::instance()->mailConfigService()->toLegacyShape(),
        ]);
    }

    public function saveMailConfig(MailConfigStoreRequest $request)
    {
        Plugin::instance()->mailConfigService()->saveFromLegacy($request->validated());

        return Response::success(__('SMTP config saved successfully', 'bit-smtp'));
    }

    public function sendTestEmail(MailTestRequest $request)
    {
        $queryParams = $request->validated();

        try {
            $smtpProvider = Plugin::instance()->smtpProvider();
            $smtpProvider->setDebug(true);
            Hooks::addFilter('wp_mail_content_type', [$this, 'setContentType']);

            if (!isset($queryParams['message']) || empty(trim($queryParams['message']))) {
                $emailData = [
                    'title'     => $queryParams['subject'],
                    'message'   => 'This is a test email sent via Bit SMTP plugin to verify your email configuration.',
                    'site_name' => get_bloginfo('name'),
                    'site_url'  => home_url()
                ];

                $message = EmailTemplate::getTemplate($emailData);
            } else {
                $message = $queryParams['message'];
            }

            wp_mail($queryParams['to'], $queryParams['subject'], $message);
            remove_filter('wp_mail_content_type', [$this, 'setContentType']);
            if ($smtpProvider->isFailed() === false) {
                $previousData = Config::getOption('test_mail_form_submitted');
                if (!$previousData) {
                    $previousData = 1;
                } else {
                    $previousData = (int) $previousData + 1;
                }
                Config::updateOption('test_mail_form_submitted', $previousData);

                return Response::success(__('Mail sent successfully', 'bit-smtp'));
            }

            return Response::message(__('Mail send testing failed', 'bit-smtp'))->error($smtpProvider->getDebugOutput());
        } catch (Exception $e) {
            $error = $e->getMessage();

            return Response::message(__('Mail send testing failed', 'bit-smtp'))->error([$error]);
        }
    }

    public function resend(Request $request)
    {
        if (!Capabilities::check('manage_options')) {
            // double checking to prevent misuse
            return Response::error([])->message('unauthorized access');
        }

        $validatedIds = array_map(function ($id) {
            return \intval($id);
        }, $request->ids);
        if (empty($validatedIds)) {
            return Response::error(__('Invalid log ID', 'bit-smtp'));
        }

        $smtpProvider = Plugin::instance()->smtpProvider();

        $logs = Plugin::instance()->logger()->getBulk($validatedIds);
        if (empty($logs)) {
            return Response::error(__('Log not found', 'bit-smtp'));
        }
        Hooks::addFilter('wp_mail_content_type', [$this, 'setContentType']);
        $resent  = 0;
        $skipped = 0;
        foreach ($logs as $log) {
            $message = (string) Arr::get($log->details, 'message', '');
            // A row whose body was dropped/redacted by the log_store_body preference has no content
            // to resend; skip it rather than silently mail a "[redacted]" or empty message.
            if (!LogBodyRedactor::isBodyRetained($message)) {
                ++$skipped;

                continue;
            }

            // Preserve the original row and log this send as a fresh child linked to it, so the resend
            // is visible as history rather than overwriting the log it was launched from.
            $smtpProvider->setResendParentId((int) $log->id)->setDebug(true);
            $headers     = Arr::get($log->details, 'headers', '');
            $attachments = Arr::get($log->details, 'attachments', []);

            wp_mail($log->to_addr, $log->subject, trim($message), $headers, $attachments);
            ++$resent;
        }

        remove_filter('wp_mail_content_type', [$this, 'setContentType']);

        if ($resent === 0 && $skipped > 0) {
            return Response::error(__('Cannot resend: the message body was not retained (log body storage is set to redacted or metadata only).', 'bit-smtp'));
        }

        if ($smtpProvider->isFailed() === false) {
            return $skipped > 0
                ? Response::success(__('Mail resent; some rows were skipped because their body was not retained.', 'bit-smtp'))
                : Response::success(__('Mail resent', 'bit-smtp'));
        }

        return Response::message(__('Failed to resend mail', 'bit-smtp'))->error($smtpProvider->getDebugOutput());
    }

    /**
     * Replay a single logged message with operator-edited recipients/subject over the chosen connection.
     * The original body/attachments are reused; the send bypasses routing and is logged as a fresh child
     * row of the original.
     */
    public function resendEdit(ResendEditRequest $request): Response
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message('unauthorized access');
        }

        $data = $request->validated();

        $log = Plugin::instance()->logger()->get((int) $data['id']);
        if ($log === null) {
            return Response::error(__('Log not found', 'bit-smtp'));
        }

        $body = (string) Arr::get($log->details, 'message', '');
        // A row whose body was dropped/redacted by the log_store_body preference has nothing to resend.
        if (!LogBodyRedactor::isBodyRetained($body)) {
            return Response::error(__('Cannot resend: the message body was not retained (log body storage is set to redacted or metadata only).', 'bit-smtp'));
        }

        $to = $request->recipients('to');
        if ($to === []) {
            return Response::error(__('At least one valid recipient email is required', 'bit-smtp'));
        }

        $bridge     = Plugin::instance()->smtpProvider()->setDebug(true);
        $connection = Plugin::instance()->mailConfigService()->connectionById(trim((string) $data['connection_id']));
        if ($connection === null || !$connection->isEnabled() || !$bridge->canSend($connection)) {
            return Response::error(__('The selected connection is unavailable', 'bit-smtp'));
        }

        $atts = [
            'to'          => $to,
            'subject'     => (string) $data['subject'],
            'message'     => trim($body),
            'headers'     => $this->resendHeaders($request->recipients('cc'), $request->recipients('bcc')),
            'attachments' => Arr::get($log->details, 'attachments', []),
        ];

        if ($bridge->dispatchResend($atts, $connection, (int) $log->id)) {
            return Response::success(__('Mail resent', 'bit-smtp'));
        }

        return Response::message(__('Failed to resend mail', 'bit-smtp'))->error($bridge->getDebugOutput());
    }

    /**
     * Build the wp_mail headers array for an edited resend: content type plus any edited Cc/Bcc. All
     * addresses are already validated by ResendEditRequest::recipients(), so they are safe in headers.
     *
     * @param string[] $cc
     * @param string[] $bcc
     *
     * @return string[]
     */
    private function resendHeaders(array $cc, array $bcc): array
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        foreach ($cc as $address) {
            $headers[] = 'Cc: ' . $address;
        }
        foreach ($bcc as $address) {
            $headers[] = 'Bcc: ' . $address;
        }

        return $headers;
    }

    public function setContentType()
    {
        return 'text/html';
    }
}
