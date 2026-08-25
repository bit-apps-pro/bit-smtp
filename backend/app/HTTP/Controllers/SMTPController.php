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
        foreach ($logs as $log) {
            // Preserve the original row and log this send as a fresh child linked to it, so the resend
            // is visible as history rather than overwriting the log it was launched from.
            $smtpProvider->setResendParentId((int) $log->id)->setDebug(true);
            $message     = Arr::get($log->details, 'message', '');
            $headers     = Arr::get($log->details, 'headers', '');
            $attachments = Arr::get($log->details, 'attachments', []);

            wp_mail($log->to_addr, $log->subject, trim($message), $headers, $attachments);
        }

        remove_filter('wp_mail_content_type', [$this, 'setContentType']);

        if ($smtpProvider->isFailed() === false) {
            return Response::success(__('Mail resent', 'bit-smtp'));
        }

        return Response::message(__('Failed to resend mail', 'bit-smtp'))->error($smtpProvider->getDebugOutput());
    }

    public function setContentType()
    {
        return 'text/html';
    }
}
