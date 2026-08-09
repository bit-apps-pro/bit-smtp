<?php

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Removes delivery-event PII that predates application-level log cleanup and no longer has a log
 * parent. No foreign key is used because WordPress sites may run on database configurations where
 * relying on FK creation or cascades is unsafe.
 */
final class BitSmtpCleanupOrphanDeliveryEvents extends Migration
{
    public function up()
    {
        $logsTable   = (new Log())->getTable();
        $eventsTable = (new LogDeliveryEvent())->getTable();

        if (!$this->tableExists($eventsTable)) {
            return;
        }

        if (!$this->tableExists($logsTable)) {
            $this->deleteAllDeliveryEvents($eventsTable);

            return;
        }

        // These are closed model-derived identifiers. The migration never interpolates request,
        // provider, or option data into SQL identifiers; only values in table-existence checks are
        // supplied through wpdb::prepare.
        $sql = 'DELETE `' . $eventsTable . '` FROM `' . $eventsTable . '` '
            . 'LEFT JOIN `' . $logsTable . '` ON `' . $eventsTable . '`.`log_id` = `' . $logsTable . '`.`id` '
            . 'WHERE `' . $logsTable . '`.`id` IS NULL';

        if (Connection::query($sql) === false || Connection::prop('last_error') !== '') {
            throw new RuntimeException('Unable to remove orphaned delivery events.');
        }
    }

    public function down()
    {
        // Data cleanup is deliberately irreversible: restoring PII after a downgrade would be
        // both impossible and contrary to the deletion guarantee.
    }

    private function tableExists(string $table): bool
    {
        $exists = Connection::get_var(
            Connection::prepare('SHOW TABLES LIKE %s', [Connection::esc_like($table)])
        );

        if (Connection::prop('last_error') !== '') {
            throw new RuntimeException('Unable to check delivery-event table availability.');
        }

        return $exists === $table;
    }

    private function deleteAllDeliveryEvents(string $eventsTable): void
    {
        if (Connection::query('DELETE FROM `' . $eventsTable . '`') === false || Connection::prop('last_error') !== '') {
            throw new RuntimeException('Unable to remove orphaned delivery events.');
        }
    }
}
