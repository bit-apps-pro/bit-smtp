<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class TelemetryAccessRequest extends Request
{
    public function rules()
    {
        return [
            'isChecked' => ['nullable', 'boolean'],
        ];
    }
}
