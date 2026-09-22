<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Nota do gestor sobre UM compromisso do calendário, independente do
 * itemtype real por trás dele (Chamado, Reserva, Lembrete...).
 *
 * Uma linha por compromisso (chave única itemtype+items_id): a nota é sobre
 * o EVENTO, não sobre uma relação gestor-subordinado guardada à parte — quem
 * pode gravá-la é decidido a cada chamada por `AccessPolicy::canManageNoteFor()`,
 * nunca lido de volta desta tabela.
 */

namespace GlpiPlugin\Planner;

use Migration;

final class EventNotes
{
    public static function getTable(): string
    {
        return 'glpi_plugin_planner_notes';
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
                    `itemtype` VARCHAR(100) NOT NULL,
                    `items_id` INT UNSIGNED NOT NULL,
                    `users_id_owner` INT UNSIGNED NOT NULL,
                    `users_id_author` INT UNSIGNED NOT NULL,
                    `note` TEXT NOT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`itemtype`, `items_id`),
                    KEY `users_id_owner` (`users_id_owner`)
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
     * Uma consulta só, pelos pares (itemtype, items_id) já presentes na
     * resposta — nunca uma consulta por evento. Faz um leve sobre-fetch (o
     * WHERE combina IN/IN em vez de pares exatos), aceitável porque a tabela
     * só tem uma linha por compromisso que alguém decidiu anotar.
     *
     * @param array<int, string> $itemtypes
     * @param array<int, int>    $items_ids
     * @return array<string, array{note: string, author_name: string, users_id_owner: int}> chave "itemtype|items_id"
     */
    public static function getForPairs(array $itemtypes, array $items_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($itemtypes === [] || $items_ids === []) {
            return [];
        }

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => array_values($itemtypes),
                'items_id' => array_values($items_ids),
            ],
        ]);

        $out = [];
        foreach ($rows as $row) {
            if ((string) $row['note'] === '') {
                continue;
            }
            $key = $row['itemtype'] . '|' . $row['items_id'];
            $out[$key] = [
                'note'           => (string) $row['note'],
                'author_name'    => EventProvider::getUserName((int) $row['users_id_author']),
                'users_id_owner' => (int) $row['users_id_owner'],
            ];
        }

        return $out;
    }

    /**
     * Grava ou apaga a nota de um compromisso. Nota vazia remove a linha —
     * não faz sentido guardar uma linha "sem nota" à espera de reuso.
     */
    public static function save(
        string $itemtype,
        int $items_id,
        int $users_id_owner,
        int $users_id_author,
        string $note
    ): bool {
        /** @var \DBmysql $DB */
        global $DB;

        $note = trim($note);

        if ($note === '') {
            return $DB->delete(self::getTable(), [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ]);
        }

        $existing = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ])->current();

        $fields = [
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'users_id_owner'  => $users_id_owner,
            'users_id_author' => $users_id_author,
            'note'            => $note,
            'date_mod'        => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            return $DB->update(self::getTable(), $fields, ['id' => $existing['id']]);
        }

        return (bool) $DB->insert(self::getTable(), $fields);
    }
}
