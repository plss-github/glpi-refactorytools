<?php

namespace GlpiPlugin\Refactorytools;

use DBConnection;
use Migration;
use Session;

final class ReservationBehalf
{
    private static int $pending_for_users_id = 0;

    public static function getTable(): string
    {
        return 'glpi_plugin_refactorytools_reservation_behalf';
    }

    public static function setPending(int $for_users_id): void
    {
        self::$pending_for_users_id = $for_users_id;
    }

    public static function clearPending(): void
    {
        self::$pending_for_users_id = 0;
    }

    public static function hasPending(): bool
    {
        return self::$pending_for_users_id > 0;
    }

    public static function getPendingForUserId(): int
    {
        return self::$pending_for_users_id;
    }

    public static function record(int $reservations_id, int $for_users_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $created_by = (int) Session::getLoginUserID();
        if ($created_by <= 0 || $reservations_id <= 0 || $for_users_id <= 0) {
            return;
        }

        $DB->insert(self::getTable(), [
            'reservations_id'     => $reservations_id,
            'for_users_id'        => $for_users_id,
            'created_by_users_id' => $created_by,
        ]);
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();

        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `reservations_id` INT UNSIGNED NOT NULL,
                    `for_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_by_users_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `reservations_id` (`reservations_id`),
                    KEY `for_users_id` (`for_users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        } else {
            $migration->addField($table, 'for_users_id', 'int unsigned NOT NULL DEFAULT 0', ['after' => 'reservations_id']);
            $migration->addKey($table, 'for_users_id');
        }
    }

    public static function uninstall(): void
    {
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }
}
