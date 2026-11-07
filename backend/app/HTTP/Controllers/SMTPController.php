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
use BitApps\SMTP\HTTP\Services\TestMailReport;
use BitApps\SMTP\Mail\Message\ContentTypeValue;
use BitApps\SMTP\Mail\Message\HtmlMarkup;
use BitApps\SMTP\Mail\Message\MailMessage;
use BitApps\SMTP\Mail\Message\MailMessageFactory;
use BitApps\SMTP\Plugin;
use BitApps\SMTP\Views\EmailTemplate;
use Throwable;

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

    /**
     * Send an admin test email through routing + failover, or through one chosen connection, and report who sent it.
     */
    public function sendTestEmail(MailTestRequest $request)
    {
        if (!Capabilities::check('manage_options')) {
            return Response::error([])->message(__('Unauthorized', 'bit-smtp'));
        }

        $data       = $request->validated();
        $bridge     = Plugin::instance()->smtpProvider();
        $connection = null;

        $connectionId = trim((string) ($data['connection_id'] ?? ''));
        if ($connectionId !== '') {
            $connection = Plugin::instance()->mailConfigService()->connectionById($connectionId);
            if ($connection === null || !$connection->isEnabled() || !$bridge->canSend($connection)) {
                return Response::error([])->message(__('The selected connection is unavailable', 'bit-smtp'));
            }
        }

        $atts = [
            'to'      => $data['to'],
            'subject' => $data['subject'],
            'message' => $this->testMessageBody($data),
        ];

        try {
            $bridge->setDebug(true);
            Hooks::addFilter('wp_mail_content_type', [$this, 'setContentType']);
            $succeeded = $connection === null
                ? (bool) wp_mail($atts['to'], $atts['subject'], $atts['message'])
                : $bridge->dispatchTest($atts, $connection);
        } catch (Throwable $e) {
            return Response::error(TestMailReport::fromAttempts(false, [], [$e->getMessage()])->toArray())
                ->message(__('Mail send testing failed', 'bit-smtp'));
        } finally {
            remove_filter('wp_mail_content_type', [$this, 'setContentType']);
        }

        $report = TestMailReport::fromAttempts($succeeded, $bridge->lastAttempts(), $bridge->getDebugOutput());

        if (!$succeeded) {
            return Response::error($report->toArray())->message(__('Mail send testing failed', 'bit-smtp'));
        }

        $this->countSuccessfulTestSend();

        return Response::success($report->toArray())->message(__('Mail sent successfully', 'bit-smtp'));
    }

    public function resend(Request $request)
    {
        if (!Capabilities::check('manage_options')) {
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
        $resent  = 0;
        $skipped = 0;
        foreach ($logs as $log) {
            $message = (string) Arr::get($log->details, 'message', '');
            if (!LogBodyRedactor::isBodyRetained($message)) {
                ++$skipped;

                continue;
            }

            $smtpProvider->setResendParentId((int) $log->id)->setDebug(true);
            $this->resendLoggedMessage($log, trim($message));
            ++$resent;
        }

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
     * Resend logged message with edited recipients/subject; reuse body/attachments, log as child row.
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

        $body = trim((string) Arr::get($log->details, 'message', ''));
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
            'message'     => $body,
            'headers'     => $this->resendHeaders($this->editedResendContentType($log, $to, $body), $request->recipients('cc'), $request->recipients('bcc')),
            'attachments' => Arr::get($log->details, 'attachments', []),
        ];

        if ($bridge->dispatchResend($atts, $connection, (int) $log->id)) {
            return Response::success(__('Mail resent', 'bit-smtp'));
        }

        return Response::message(__('Failed to resend mail', 'bit-smtp'))->error($bridge->getDebugOutput());
    }

    public function setContentType()
    {
        return 'text/html';
    }

    /**
     * Resend one logged message under a content type decided from its own body; the per-message filter is removed even if wp_mail throws.
     */
    private function resendLoggedMessage(object $log, string $body): void
    {
        $headers = Arr::get($log->details, 'headers', '');
        $promote = $this->promotesToHtml($headers, $body);
        $filter  = static fn ($contentType) => $promote && \is_string($contentType) && stripos(ltrim($contentType), ContentTypeValue::MULTIPART_PREFIX) !== 0
            ? ContentTypeValue::TEXT_HTML
            : $contentType;

        Hooks::addFilter('wp_mail_content_type', $filter);

        try {
            wp_mail($log->to_addr, $log->subject, $body, $headers, Arr::get($log->details, 'attachments', []));
        } finally {
            remove_filter('wp_mail_content_type', $filter);
        }
    }

    /**
     * Treat a logged body as HTML only when its headers declare no type at all (a filter set it, as Bit Form does) and it has real tags; a declared type is honoured.
     *
     * @param string|array<int,string> $headers
     */
    private function promotesToHtml($headers, string $body): bool
    {
        return !(new MailMessageFactory())->declaresContentType($headers) && HtmlMarkup::isPresentIn($body);
    }

    /**
     * Content-Type header value for an edited resend: the type the logged headers resolve to, in UTF-8 because the edited fields come from the admin UI as UTF-8.
     *
     * @param string[] $to
     */
    private function editedResendContentType(object $log, array $to, string $body): string
    {
        $headers = Arr::get($log->details, 'headers', '');
        $logged  = (new MailMessageFactory())->fromWpMailAtts([
            'to'      => $to,
            'subject' => '',
            'message' => $body,
            'headers' => $headers,
        ]);

        $contentType = $this->promotesToHtml($headers, $body) && !$logged->isMultipart() ? ContentTypeValue::TEXT_HTML : $logged->getContentType();

        return $contentType . '; charset=' . MailMessage::DEFAULT_CHARSET;
    }

    /**
     * The admin's own message, or the branded default test template when none was given.
     *
     * @param array<string,mixed> $data
     */
    private function testMessageBody(array $data): string
    {
        $message = trim((string) ($data['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return EmailTemplate::getTemplate([
            'title'     => $data['subject'],
            'message'   => __('This is a test email sent via Bit SMTP plugin to verify your email configuration.', 'bit-smtp'),
            'site_name' => get_bloginfo('name'),
            'site_url'  => home_url(),
        ]);
    }

    /**
     * Increment the successful test-send counter reported by telemetry.
     */
    private function countSuccessfulTestSend(): void
    {
        Config::updateOption('test_mail_form_submitted', (int) Config::getOption('test_mail_form_submitted') + 1);
    }

    /**
     * Build wp_mail headers for edited resend (content type + Cc/Bcc); addresses pre-validated.
     *
     * @param string   $contentType full Content-Type header value
     * @param string[] $cc
     * @param string[] $bcc
     *
     * @return string[]
     */
    private function resendHeaders(string $contentType, array $cc, array $bcc): array
    {
        $headers = ['Content-Type: ' . $contentType];
        foreach ($cc as $address) {
            $headers[] = 'Cc: ' . $address;
        }
        foreach ($bcc as $address) {
            $headers[] = 'Bcc: ' . $address;
        }

        return $headers;
    }
}
