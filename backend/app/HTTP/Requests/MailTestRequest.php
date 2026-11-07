<?php

namespace BitApps\SMTP\HTTP\Requests;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\HTTP\Requests\Rules\RecipientEmailRule;

class MailTestRequest extends Request
{
    public function rules()
    {
        return
            [
                'to'            => ['required', 'email', new RecipientEmailRule(), 'sanitize:text'],
                'subject'       => ['required', 'sanitize:text'],
                'message'       => ['nullable', 'sanitize:wp_kses_post'],
                'connection_id' => ['nullable', 'string', 'sanitize:text'],
            ];
    }

    public function messages()
    {
        return [
            'to.required'      => __('Recipient email address is required', 'bit-smtp'),
            'to.email'         => __('Recipient must be a valid email address', 'bit-smtp'),
            'subject.required' => __('Subject is required', 'bit-smtp'),
        ];
    }
}
