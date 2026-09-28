<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class NotificationTestRequest extends Request
{
    /**
     * Require channel as array; field validation is tester's job, not nested rules.
     *
     * @return array<string,string[]>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'array'],
        ];
    }

    /**
     * Error messages for the rules above.
     *
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'channel.required' => 'Notification channel is required',
            'channel.array'    => 'Notification channel must be an object',
        ];
    }
}
