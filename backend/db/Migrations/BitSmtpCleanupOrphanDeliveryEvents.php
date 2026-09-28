<?php

use BitApps\SMTP\Deps\BitApps\WPDatabase\Connection;
use BitApps\SMTP\Deps\BitApps\WPKit\Migration\Migration;
use BitApps\SMTP\Model\Log;
use BitApps\SMTP\Model\LogDeliveryEvent;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Remove delivery-event PII predating app-level cleanup with no log parent (no FK due to WordPress DB config fragility).
 */
final class BitSmtpCleanupOrphanDeliveryEvents extends Migration
{
    /**
     * Remove orphaned delivery events; all SQL identifiers are model-derived (safe from injection).
     */
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

        $sql = 'DELETE `' . $eventsTable . '` FROM `' . $eventsTable . '` '
            . 'LEFT JOIN `' . $logsTable . '` ON `' . $eventsTable . '`.`log_id` = `' . $logsTable . '`.`id` '
            . 'WHERE `' . $logsTable . '`.`id` IS NULL';

        if (Connection::query($sql) === false || Connection::prop('last_error') !== '') {
            throw new RuntimeException('Unable to remove orphaned delivery events.');
        }
    }

    /**
     * No-op: data cleanup is deliberately irreversible to honor deletion guarantees.
     */
    public function down()
    {
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
