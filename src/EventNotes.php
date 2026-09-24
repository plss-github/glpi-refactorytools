<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Nota do gestor sobre UM compromisso do calendário, independente do
 * itemtype real por trás dele (Chamado, Reserva, Lembrete...).
 *
 * Várias linhas por compromisso (itemtype+items_id): um histórico de notas,
 * não um campo único que a próxima edição sobrescreve. A nota é sobre o
 * EVENTO, não sobre uma relação gestor-subordinado guardada à parte — quem
 * pode gravá-la é decidido a cada chamada por `AccessPolicy::canManageNoteFor()`,
 * nunca lido de volta desta tabela.
 */

namespace GlpiPlugin\Refactorytools;

use Migration;
use NotificationEvent;

final class EventNotes
{
    public static function getTable(): string
    {
        return 'glpi_plugin_refactorytools_notes';
    }

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();

        // Rename do plugin (planner -> refactorytools): a tabela de uma
        // instalação antiga só precisa mudar de nome, os dados continuam
        // válidos como estão. Feito antes de qualquer outra checagem, para
        // que a migração do índice único logo abaixo já opere sobre o nome
        // novo.
        $old_table = 'glpi_plugin_planner_notes';
        if (!$DB->tableExists($table) && $DB->tableExists($old_table)) {
            $DB->doQuery("RENAME TABLE `{$old_table}` TO `{$table}`");
        }

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
                    KEY `pair` (`itemtype`, `items_id`),
                    KEY `users_id_owner` (`users_id_owner`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            return;
        }

        // Instalações existentes vinham com uma linha só por compromisso
        // (`UNIQUE KEY unicity`), que travava em uma nota só. Trocar por um
        // índice comum de leitura libera múltiplas notas por compromisso sem
        // perder as já gravadas.
        if ($DB->fieldExists($table, 'itemtype') && self::hasUniqueIndex($table, 'unicity')) {
            $DB->doQuery("ALTER TABLE `{$table}` DROP INDEX `unicity`");
            $DB->doQuery("ALTER TABLE `{$table}` ADD INDEX `pair` (`itemtype`, `items_id`)");
        }
    }

    private static function columnType(string $table, string $column): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $result = $DB->doQuery("SHOW FIELDS FROM `{$table}` WHERE Field = '{$column}'");
        $row    = $result !== false ? $DB->fetchAssoc($result) : null;

        if (!$row) {
            return null;
        }

        // `SHOW FIELDS` devolve algo como "timestamp" ou "datetime" (sem
        // parênteses/tamanho para estes dois tipos), então comparar o valor
        // inteiro em minúsculas já basta.
        return strtolower((string) $row['Type']);
    }

    private static function hasUniqueIndex(string $table, string $index_name): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $result = $DB->doQuery("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index_name}'");

        return $result !== false && $DB->numrows($result) > 0;
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
     * WHERE combina IN/IN em vez de pares exatos), aceitável porque a lista
     * de eventos de uma tela é sempre pequena.
     *
     * @param array<int, string> $itemtypes
     * @param array<int, int>    $items_ids
     * @return array<string, array<int, array{id: int, note: string, author_name: string, users_id_owner: int, date_mod: string}>> chave "itemtype|items_id", notas mais recentes primeiro
     */
    public static function getForPairs(array $itemtypes, array $items_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($itemtypes === [] || $items_ids === []) {
            return [];
        }

        $rows = $DB->request([
            'FROM'    => self::getTable(),
            'WHERE'   => [
                'itemtype' => array_values($itemtypes),
                'items_id' => array_values($items_ids),
            ],
            'ORDER' => 'date_mod DESC',
        ]);

        $out = [];
        foreach ($rows as $row) {
            if ((string) $row['note'] === '') {
                continue;
            }
            $key = $row['itemtype'] . '|' . $row['items_id'];
            $out[$key][] = [
                'id'             => (int) $row['id'],
                'note'           => (string) $row['note'],
                'author_name'    => EventProvider::getUserName((int) $row['users_id_author']),
                'users_id_owner' => (int) $row['users_id_owner'],
                'date_mod'       => (string) $row['date_mod'],
            ];
        }

        return $out;
    }

    /**
     * Grava uma nota de um compromisso. Sem `$note_id`, sempre acrescenta uma
     * linha nova ao histórico — não sobrescreve a anterior, é assim que várias
     * notas convivem no mesmo compromisso. Com `$note_id`, edita aquela nota
     * específica no lugar (o chamador já confirmou que ela pertence a este
     * par itemtype/items_id antes de chegar aqui). Nota vazia só é aceita em
     * conjunto com `$note_id`, e apaga a linha em vez de gravar texto vazio.
     */
    public static function save(
        string $itemtype,
        int $items_id,
        int $users_id_owner,
        int $users_id_author,
        string $note,
        int $note_id = 0
    ): bool {
        /** @var \DBmysql $DB */
        global $DB;

        $note = trim($note);

        if ($note === '') {
            if ($note_id <= 0) {
                return false;
            }

            return $DB->delete(self::getTable(), [
                'id'       => $note_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ]);
        }

        $fields = [
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'users_id_owner'  => $users_id_owner,
            'users_id_author' => $users_id_author,
            'note'            => $note,
            'date_mod'        => date('Y-m-d H:i:s'),
        ];

        if ($note_id > 0) {
            $ok = (bool) $DB->update(self::getTable(), $fields, [
                'id'       => $note_id,
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ]);
        } else {
            $ok      = (bool) $DB->insert(self::getTable(), $fields);
            $note_id = (int) $DB->insertId();
        }

        // Dono anotando a própria agenda não recebe e-mail sobre si mesmo —
        // `NotificationTargetEventNoteItem::addAdditionalTargets()` manda
        // para `users_id_owner`, e ele já sabe o que escreveu.
        if ($ok && $note_id > 0 && $users_id_owner !== $users_id_author) {
            self::notify($note_id, $fields);
        }

        return $ok;
    }

    /**
     * Dispara a notificação (ver `NotificationTargetEventNoteItem`), num
     * `EventNoteItem` montado à mão com os campos já gravados — não é preciso
     * reconsultar o banco, e o disparo não passa pelo ciclo de
     * add()/update() de um `CommonDBTM` de verdade (direito por entidade,
     * histórico…), que não fazem sentido para esta tabela simples.
     *
     * @param array<string, mixed> $fields
     */
    private static function notify(int $note_id, array $fields): void
    {
        $item = new EventNoteItem();
        $item->fields = ['id' => $note_id] + $fields;

        NotificationEvent::raiseEvent('new_note', $item);
    }
}
