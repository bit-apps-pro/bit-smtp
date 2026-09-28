<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class MailSettingsSaveRequest extends Request
{
    public function rules()
    {
        return [
            'enabled'                 => ['nullable', 'boolean'],
            'default_connection_id'   => ['nullable', 'string', 'sanitize:text'],
            'connections'             => ['nullable', 'array'],
            'features'                => ['nullable', 'array'],
            'fallback_connection_ids' => ['nullable', 'array'],
        ];
    }

    public function messages()
    {
        return [];
    }
}
