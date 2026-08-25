<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class MailSettingsSaveRequest extends Request
{
    public function rules()
    {
        // Routing rules are not validated here: mail/settings/save is a shared whole-blob endpoint
        // that unrelated Connections-page actions resend verbatim, so a hard reject would block them
        // on legacy data. Dead routing conditions are self-healed in MailSettingsSanitizer instead.
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
