<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class NotificationTestRequest extends Request
{
    /**
     * @return array<string,string[]>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'sanitize:text'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'channel.required' => 'Notification channel is required',
        ];
    }
}
