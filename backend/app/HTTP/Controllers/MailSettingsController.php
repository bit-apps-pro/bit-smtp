<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Requests\MailSettingsSaveRequest;
use BitApps\SMTP\Mail\Exceptions\AlertSettingsConflictException;
use BitApps\SMTP\Mail\Exceptions\InvalidAlertChannelException;
use BitApps\SMTP\Plugin;

class MailSettingsController
{
    private const ALERTS_CONFLICT_CODE = 'alerts_conflict';

    private const ALERTS_CONFLICT_STATUS = 409;

    private const INVALID_ALERT_CHANNEL_CODE = 'invalid_alert_channel';

    private const INVALID_ALERT_CHANNEL_STATUS = 422;

    /**
     * The full v2 settings in their masked API shape.
     */
    public function index()
    {
        return Response::success(['settings' => Plugin::instance()->mailConfigService()->apiSettings()]);
    }

    /**
     * Persist the full v2 settings; a stale alerts revision is a 409 and an unusable alert channel a 422.
     */
    public function save(MailSettingsSaveRequest $request)
    {
        try {
            $saved = Plugin::instance()->mailConfigService()->saveSettings($request->validated());
        } catch (AlertSettingsConflictException $e) {
            return self::error(self::ALERTS_CONFLICT_STATUS, self::ALERTS_CONFLICT_CODE, $e->getMessage());
        } catch (InvalidAlertChannelException $e) {
            return self::error(self::INVALID_ALERT_CHANNEL_STATUS, self::INVALID_ALERT_CHANNEL_CODE, $e->getMessage());
        }

        if (!$saved) {
            return Response::error(__('Failed to save settings', 'bit-smtp'));
        }

        return Response::success(__('Settings saved', 'bit-smtp'));
    }

    /**
     * An error response with a stable machine code and a message safe to show the admin.
     */
    private static function error(int $httpStatus, string $code, string $message): Response
    {
        return Response::error([], $httpStatus)->code($code)->message($message);
    }
}
