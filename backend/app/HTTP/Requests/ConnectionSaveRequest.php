<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class ConnectionSaveRequest extends Request
{
    public function rules()
    {
        return [
            'id'           => ['nullable', 'string', 'sanitize:text'],
            'provider'     => ['required', 'string', 'sanitize:text'],
            'kind'         => ['nullable', 'string', 'sanitize:text'],
            'name'         => ['nullable', 'string', 'sanitize:text'],
            'enabled'      => ['nullable', 'boolean'],
            'fromEmail'    => ['nullable', 'email', 'sanitize:email'],
            'fromName'     => ['nullable', 'string', 'sanitize:text'],
            'replyToEmail' => ['nullable', 'email', 'sanitize:email'],
            'settings'     => ['nullable', 'array'],
            'credentials'  => ['nullable', 'array'],
        ];
    }

    public function messages()
    {
        return [
            'provider.required' => 'Provider is required',
        ];
    }
}
