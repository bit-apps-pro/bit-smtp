<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class ConnectionTestRequest extends Request
{
    public function rules()
    {
        return [
            'id'           => ['nullable', 'string', 'sanitize:text'],
            'provider'     => ['required', 'string', 'sanitize:text'],
            'name'         => ['nullable', 'string', 'sanitize:text'],
            'kind'         => ['nullable', 'string', 'sanitize:text'],
            'enabled'      => ['nullable', 'boolean'],
            'fromEmail'    => ['nullable', 'email', 'sanitize:email'],
            'fromName'     => ['nullable', 'string', 'sanitize:text'],
            'replyToEmail' => ['nullable', 'email', 'sanitize:email'],
            'settings'     => ['nullable', 'array'],
            'credentials'  => ['nullable', 'array'],
            'to'           => ['required', 'email', 'sanitize:email'],
        ];
    }

    public function messages()
    {
        return [
            'provider.required' => 'Provider is required',
            'to.required'       => 'Recipient email is required',
            'to.email'          => 'Recipient must be a valid email address',
        ];
    }
}
