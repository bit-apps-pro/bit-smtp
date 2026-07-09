<?php

namespace BitApps\SMTP\Providers;

use BitApps\SMTP\Mail\Dispatch\WpMailBridge;

\defined('ABSPATH') || exit();

/**
 * @deprecated Use \BitApps\SMTP\Mail\Dispatch\WpMailBridge. Kept as a thin alias for backward
 *             compatibility with external references; all logic lives in WpMailBridge.
 */
class SmtpProvider extends WpMailBridge
{
}
