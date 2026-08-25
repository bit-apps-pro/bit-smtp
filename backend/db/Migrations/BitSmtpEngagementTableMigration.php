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
 * Creates the log_engagement_events child table: one folded row per (log, open|click, target),
 * deduped by a UNIQUE event_key so a re-fired pixel/link increments counters instead of inserting a
 * duplicate. Foundation for Phase 8 open/click tracking; nothing writes to it until the send path
 * is wired in a later sub-phase.
 */
final class BitSmtpEngagementTableMigration extends Migration
{
    /**
     * Create the engagement events table. Schema::create() is CREATE TABLE IF NOT EXISTS, so this is
     * idempotent across the repeated activation-time runs of the migration list.
     */
    public function up()
    {
        $this->addEngagementTableIfMissing();
    }

    /**
     * Drop the engagement events table on uninstall, only when the purge preference is enabled.
     */
    public function down()
    {
        if (!UninstallPurge::shouldPurge()) {
            return;
        }

        // withWpPrefix(), not a bare Schema::drop(): the static form leaves the prefix null and emits
        // an unprefixed DROP that matches nothing, leaving recipient-linked engagement data behind.
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
                $table->bigInt('log_id')->index();
                $table->varchar('type', 16);
                $table->text('target')->nullable();
                $table->integer('hits')->unsigned()->defaultValue(1);
                $table->integer('automated_hits')->unsigned()->defaultValue(0);
                $table->datetime('first_at')->nullable();
                $table->datetime('last_at')->nullable();
                // One NOT-NULL hash over (log_id, type, target); a re-fire folds via ON DUPLICATE KEY
                // rather than inserting a duplicate. Mirrors log_delivery_events.event_hash.
                $table->char('event_key', 64)->unique();

                $table->timestamps();
            }
        );
    }
}
