<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Histórico de quem criou/editou/cancelou cada reserva.
 *
 * `Reservation` não tem histórico nativo (`$dohistory` não é ligado pelo
 * core para este itemtype), então isto não duplica nada — é a única
 * trilha que existe. Alimentado pelos hooks `item_add`/`item_update`/
 * `item_purge` registrados em `setup.php` (`plugin_init_refactorytools()`),
 * não pelos endpoints do plugin diretamente: assim uma edição feita pelo
 * formulário NATIVO de reserva (que o plugin não controla) também entra no
 * histórico.
 */

namespace GlpiPlugin\Refactorytools;

use Migration;
use Reservation;
use ReservationItem;
use Session;

final class ReservationHistory
{
    public const ACTION_ADD   = 'add';
    public const ACTION_UPDATE = 'update';
    public const ACTION_PURGE = 'purge';

    public static function getTable(): string
    {
        return 'glpi_plugin_refactorytools_reservation_history';
    }

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `reservations_id` INT UNSIGNED NOT NULL,
                    `action` VARCHAR(20) NOT NULL,
                    `users_id_author` INT UNSIGNED NOT NULL,
                    `users_id_owner` INT UNSIGNED NOT NULL,
                    `item_label` VARCHAR(255) NOT NULL DEFAULT '',
                    -- `TIMESTAMP`, não `DATETIME`: o próprio core do GLPI 11
                    -- avisa (`DBmysql::checkForDeprecatedTableOptions()`)
                    -- que `DATETIME` está desencorajado em favor de
                    -- `TIMESTAMP` — o oposto do que se imaginava numa
                    -- correção anterior deste plugin, que chegou a ser
                    -- revertida por outro desenvolvedor por este mesmo
                    -- motivo (ver EventNotes/Share).
                    `begin` TIMESTAMP NULL DEFAULT NULL,
                    `end` TIMESTAMP NULL DEFAULT NULL,
                    `date` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `reservations_id` (`reservations_id`),
                    KEY `date` (`date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public static function uninstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    /**
     * Grava uma entrada — chamado pelos hooks em hook.php, nunca direto por
     * um endpoint do plugin (ver o comentário da classe).
     */
    public static function log(Reservation $reservation, string $action): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $fields = $reservation->fields;

        $item_label = sprintf('#%d', (int) ($fields['reservationitems_id'] ?? 0));
        $ri = new ReservationItem();
        if ($ri->getFromDB((int) ($fields['reservationitems_id'] ?? 0))) {
            $asset = \getItemForItemtype((string) $ri->fields['itemtype']);
            if ($asset !== false && $asset->getFromDB((int) $ri->fields['items_id'])) {
                $item_label = sprintf('%s - %s', $asset::getTypeName(1), $asset->getName());
            }
        }

        $DB->insert(self::getTable(), [
            'reservations_id' => (int) ($fields['id'] ?? 0),
            'action'          => $action,
            'users_id_author' => (int) Session::getLoginUserID(),
            'users_id_owner'  => (int) ($fields['users_id'] ?? 0),
            'item_label'      => $item_label,
            'begin'           => $fields['begin'] ?? null,
            'end'             => $fields['end'] ?? null,
            'date'            => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getEntries(int $limit = 200): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'ORDER' => 'date DESC',
            'LIMIT' => $limit,
        ]) as $row) {
            $out[] = [
                'action'      => (string) $row['action'],
                'action_label' => match ($row['action']) {
                    self::ACTION_ADD    => __('Created', 'refactorytools'),
                    self::ACTION_UPDATE => __('Edited', 'refactorytools'),
                    self::ACTION_PURGE  => __('Cancelled', 'refactorytools'),
                    default             => (string) $row['action'],
                },
                'author'      => EventProvider::getUserName((int) $row['users_id_author']),
                'owner'       => EventProvider::getUserName((int) $row['users_id_owner']),
                'item_label'  => (string) $row['item_label'],
                'begin'       => (string) $row['begin'],
                'end'         => (string) $row['end'],
                'date'        => (string) $row['date'],
            ];
        }

        return $out;
    }
}
