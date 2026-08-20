<?php

namespace BitApps\SMTP\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Requests\MailSettingsSaveRequest;
use BitApps\SMTP\Plugin;

class MailSettingsController
{
    public function index()
    {
        return Response::success(['settings' => Plugin::instance()->mailConfigService()->apiSettings()]);
    }

    public function save(MailSettingsSaveRequest $request)
    {
        if (!Plugin::instance()->mailConfigService()->saveSettings($request->validated())) {
            return Response::error(__('Failed to save settings', 'bit-smtp'));
        }

        return Response::success(__('Settings saved', 'bit-smtp'));
    }
}
