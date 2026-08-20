<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;

class ConnectionDeleteRequest extends Request
{
    public function rules()
    {
        return [
            'id' => ['required', 'string', 'sanitize:text'],
        ];
    }

    public function messages()
    {
        return [
            'id.required' => 'Connection ID is required',
        ];
    }
}
