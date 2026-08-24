<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Blueprint;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Schema;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Settings\UninstallPurge;

if (!\defined('ABSPATH')) {
    exit;
}

final class BitSmtpRetryQueueMigration extends Migration
{
    public function up()
    {
        Schema::withPrefix(Connection::wpPrefix() . Config::VAR_PREFIX)->create(
            'mail_retry_queue',
            function (Blueprint $table) {
                $table->id();
                $table->bigInt('log_id')->unsigned()->nullable()->index();
                $table->longtext('payload');
                $table->varchar('connection_chain', 255)->defaultValue('');
                $table->tinyint('attempts')->unsigned()->defaultValue(0);
                $table->tinyint('max_attempts')->unsigned();
                $table->varchar('failure_class', 24)->nullable();
                $table->datetime('next_attempt_at')->index();
                $table->varchar('claim_token', 64)->nullable();
                $table->datetime('locked_at')->nullable();
                $table->datetime('created_at');
                $table->datetime('updated_at');
            }
        );
    }

    public function down()
    {
        if (!UninstallPurge::shouldPurge()) {
            return;
        }

        // withWpPrefix(), not a bare Schema::drop(): the static form leaves the prefix null and
        // emits an unprefixed `DROP TABLE mail_retry_queue`, which matches nothing and leaves the
        // real (encrypted-payload) table behind on uninstall-with-purge.
        Schema::withWpPrefix()->drop('mail_retry_queue');
    }
}
