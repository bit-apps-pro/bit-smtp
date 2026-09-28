<?php

use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Plugin;

if (! \defined('ABSPATH')) {
    exit;
}

final class BitSmtpEncryptSecrets extends Migration
{
    public function up()
    {
        $svc = Plugin::instance()->mailConfigService();
        $svc->store($svc->load());
    }

    /**
     * No-op: downgrade would re-introduce at-rest credential exposure.
     */
    public function down()
    {
    }
}
