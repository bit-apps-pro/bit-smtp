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

    public function down()
    {
        // No-op: ciphertext is still readable as-is (CredentialCipher tolerates its own prefix on
        // repeat encrypts), so a downgrade has nothing to reverse and re-plaintexting would only
        // reintroduce the at-rest exposure this migration fixes.
    }
}
