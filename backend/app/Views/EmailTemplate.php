<?php

namespace BitApps\SMTP\Views;

/**
 * The email template class for test emails.
 */
class EmailTemplate
{
    /**
     * Generate the test email content with Bit SMTP branding.
     *
     * @param array $data Additional data for the email
     *
     * @return string HTML content for the test email
     */
    public static function getTemplate($data = [])
    {
        $title    = isset($data['title']) ? $data['title'] : 'Bit SMTP Test Email';
        $message  = isset($data['message']) ? $data['message'] : 'This is a test email sent via Bit SMTP plugin to verify your email configuration.';
        $siteName = isset($data['site_name']) ? $data['site_name'] : get_bloginfo('name');
        $siteUrl  = isset($data['site_url']) ? $data['site_url'] : home_url();

        return '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>' . esc_html($title) . '</title>
    <style type="text/css">
        /* Media Queries */
        @media only screen and (max-width: 600px) {
            .email-body_inner,
            .email-footer {
                width: 100% !important;
            }
        }
        
        @media only screen and (max-width: 500px) {
            .button {
                width: 100% !important;
            }
        }
        
        /* Body */
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        /* Email Body */
        .email-body {
            background-color: #f5f5f5;
            width: 100%;
            padding: 20px 0;
        }
        
        .email-body_inner {
            background-color: #ffffff;
            margin: 0 auto;
            width: 570px;
            overflow: hidden;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* Email Footer */
        .email-footer {
            margin: 0 auto;
            padding: 20px 0;
            text-align: center;
            width: 570px;
        }
        
        .email-footer p {
            color: #aeaeae;
            font-size: 12px;
        }
        
        /* Typography */
        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: Arial, sans-serif;
            color: #292929;
            margin-top: 0;
            margin-bottom: 12px;
            font-weight: bold;
        }
        
        h1 {
            font-size: 22px;
            line-height: 28px;
        }
        
        p {
            font-size: 16px;
            line-height: 22px;
            margin-top: 0;
            margin-bottom: 12px;
            color: #747474;
        }
        
        .sub {
            font-size: 13px;
            color: #aeaeae;
            margin-top: 10px;
        }
        
        /* Content */
        .content-cell {
            padding: 45px;
        }
        
        .align-center {
            text-align: center;
        }
        
        .align-right {
            text-align: right;
        }
        
        .align-left {
            text-align: left;
        }
        
        .full-width {
            width: 100%;
        }
        
        .bit-smtp-logo {
            text-align: center;
            padding: 20px 0;
        }
        
        .bit-smtp-logo img {
            max-height: 60px;
        }
        
        /* Buttons */
        .button {
            display: inline-block;
            padding: 10px 20px;
            border: 1px solid #4a6cf7;
            background-color: #4a6cf7;
            border-radius: 4px;
            text-decoration: none;
            color: #ffffff;
            font-size: 15px;
            text-align: center;
            cursor: pointer;
        }
        
        .button:hover {
            background-color: #3858e9;
        }
        
        .button--green {
            border-color: #5cb85c;
            background-color: #5cb85c;
        }
        
        .button--red {
            border-color: #d9534f;
            background-color: #d9534f;
        }
        
        /* Code Block */
        .code {
            background-color: #f2f2f2;
            border-left: 4px solid #4a6cf7;
            padding: 12px 15px;
            font-family: monospace;
            font-size: 14px;
            margin: 15px 0;
        }
        
        /* Status */
        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            background-color: #d4edda;
            color: #155724;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <table class="email-wrapper" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table class="email-content" width="100%" cellpadding="0" cellspacing="0">
                    <!-- Email Body -->
                    <tr>
                        <td class="email-body" width="100%">
                            <table class="email-body_inner" align="center" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="content-cell">
                                        <div class="bit-smtp-logo">
                                            <!-- Bit SMTP SVG Logo -->
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 124 124">
  <rect width="124" height="124" fill="#d9d9d9" rx="30.38"/>
  <rect width="124" height="124" fill="#158af8" rx="30.38"/>
  <g filter="url(#c)">
    <path fill="#fff" fill-rule="evenodd" d="m26 48.135 31.079 24.173a8.67 8.67 0 0 0 10.642 0L98.8 48.135V82.8c0 1.38-.548 2.702-1.524 3.676A5.2 5.2 0 0 1 93.6 88H31.2a5.2 5.2 0 0 1-3.676-1.524A5.2 5.2 0 0 1 26 82.8z" clip-rule="evenodd"/>
  </g>
  <g filter="url(#d)">
    <path fill="#fff" fill-rule="evenodd" d="M98.8 41.236a5.2 5.2 0 0 1-2.007 4.069l-31.2 24.266a5.2 5.2 0 0 1-6.386 0l-31.2-24.266A5.2 5.2 0 0 1 26 41.235V41.2c0-1.38.548-2.702 1.524-3.676A5.2 5.2 0 0 1 31.2 36h62.4c1.38 0 2.702.548 3.676 1.524A5.2 5.2 0 0 1 98.8 41.2z" clip-rule="evenodd"/>
  </g>
</svg>
                                            <h1>' . esc_html($title) . '</h1>
                                        </div>
                                        
                                        <p>Dear ' . esc_html(wp_get_current_user()->display_name ?: 'Administrator') . ',</p>
                                        
                                        <p>' . esc_html($message) . '</p>
                                        
                                        <div class="code">
                                            <strong>Test Details:</strong><br>
                                            Date: ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format')) . '<br>
                                            Site: <a href="' . esc_url($siteUrl) . '">' . esc_html($siteName) . '</a><br>
                                            Plugin: Bit SMTP
                                        </div>
                                        
                                        <p>Configuration Status: <span class="status">Working Properly</span></p>
                                        
                                        <p>If you received this email, your SMTP configuration is working correctly. You can now send emails reliably from your WordPress site using Bit SMTP.</p>
                                        
                                        <p>Best regards,<br>
                                        <strong>Bit Apps Team</strong></p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Email Footer -->
                    <tr>
                        <td>
                            <table class="email-footer" align="center" width="570" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="content-cell" align="center">
                                        <p class="sub align-center">
                                            &copy; ' . gmdate('Y') . ' Bit SMTP Plugin. All rights reserved.
                                        </p>
                                        <p class="sub align-center">
                                            This is an automated email sent from your WordPress site using the Bit SMTP plugin.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Get a plain text version of the test email
     *
     * @param array $data Additional data for the email
     *
     * @return string Plain text content for the test email
     */
    public static function getPlainTextTemplate($data = [])
    {
        $title       = isset($data['title']) ? $data['title'] : 'Bit SMTP Test Email';
        $message     = isset($data['message']) ? $data['message'] : 'This is a test email sent via Bit SMTP plugin to verify your email configuration.';
        $siteName    = isset($data['site_name']) ? $data['site_name'] : get_bloginfo('name');
        $siteUrl     = isset($data['site_url']) ? $data['site_url'] : home_url();
        $currentUser = wp_get_current_user()->display_name ?: 'Administrator';

        return "
{$title}

Dear {$currentUser},

{$message}

Test Details:
Date: " . date_i18n(get_option('date_format') . ' ' . get_option('time_format')) . "
Site: {$siteName} ({$siteUrl})
Plugin: Bit SMTP

Configuration Status: Working Properly

If you received this email, your SMTP configuration is working correctly. You can now send emails reliably from your WordPress site using Bit SMTP.

Best regards,
Bit Apps Team

----------------------------------------
© " . gmdate('Y') . ' Bit SMTP Plugin. All rights reserved.
This is an automated email sent from your WordPress site using the Bit SMTP plugin.
';
    }
}
