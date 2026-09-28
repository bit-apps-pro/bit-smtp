<?php

use BitApps\SMTP\Config;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Blueprint;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPDatabase\Schema;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Settings\UninstallPurge;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Create log_engagement_events table (one row per log/open|click/target, deduped by event_key).
 */
final class BitSmtpEngagementTableMigration extends Migration
{
    /**
     * Create engagement events table (idempotent via CREATE TABLE IF NOT EXISTS).
     */
    public function up()
    {
        $this->addEngagementTableIfMissing();
    }

    /**
     * Drop the engagement events table on uninstall, only when the purge preference is enabled.
     */
    /**
     * Drop engagement table on uninstall-with-purge; must use withWpPrefix() not bare Schema::drop().
     */
    public function down()
    {
        if (!UninstallPurge::shouldPurge()) {
            return;
        }

        Schema::withWpPrefix()->drop('log_engagement_events');
    }

    /**
     * Idempotent create mirroring BitSmtpLogsTableMigration::createDeliveryEventsTableIfMissing().
     */
    private function addEngagementTableIfMissing()
    {
        Schema::withPrefix(Connection::wpPrefix() . Config::VAR_PREFIX)->create(
            'log_engagement_events',
            function (Blueprint $table) {
                $table->id();
                $table->bigInt('log_id')->unsigned()->index()->foreign('logs', 'id')->onDelete()->cascade();
                $table->varchar('type', 16);
                $table->text('target')->nullable();
                $table->integer('hits')->unsigned()->defaultValue(1);
                $table->integer('automated_hits')->unsigned()->defaultValue(0);
                $table->datetime('first_at')->nullable();
                $table->datetime('last_at')->nullable();
                $table->char('event_key', 64)->unique();

                $table->timestamps();
            }
        );
    }
}
