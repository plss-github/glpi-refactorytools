<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Convidados de uma Reunião (`PlanningExternalEvent` com categoria
 * "Reunião"), além do que o próprio core já resolve.
 *
 * O core JÁ espelha o compromisso na agenda de cada convidado sozinho —
 * `users_id_guests` é um campo nativo de `PlanningExternalEvent`
 * (`Glpi\Features\PlanningEvent::populatePlanning()` inclui o convidado no
 * filtro `who`). Esta tabela não duplica isso: ela só guarda o que o core
 * não tem — se a presença de CADA convidado é obrigatória ou opcional, e se
 * ele já respondeu (aceitou/recusou).
 */

namespace GlpiPlugin\Refactorytools;

use Migration;
use Session;

final class MeetingGuest
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';

    public static function getTable(): string
    {
        return 'glpi_plugin_refactorytools_meeting_guests';
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
                    `items_id` INT UNSIGNED NOT NULL,
                    `users_id` INT UNSIGNED NOT NULL,
                    `mandatory` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`items_id`, `users_id`),
                    KEY `users_id` (`users_id`)
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
     * Grava a lista de convidados de uma reunião recém-criada — chamado uma
     * vez, na criação, nunca linha a linha. `$mandatory_ids`/`$optional_ids`
     * não precisam ser disjuntos: quem aparece nos dois é gravado como
     * obrigatório (a opção mais forte vence).
     *
     * @param array<int, int|string> $mandatory_ids
     * @param array<int, int|string> $optional_ids
     */
    public static function setGuests(int $items_id, array $mandatory_ids, array $optional_ids): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $mandatory = array_map('intval', $mandatory_ids);
        $optional  = array_diff(array_map('intval', $optional_ids), $mandatory);

        $table = self::getTable();

        foreach ($mandatory as $users_id) {
            if ($users_id > 0) {
                $DB->insert($table, ['items_id' => $items_id, 'users_id' => $users_id, 'mandatory' => 1]);
            }
        }
        foreach ($optional as $users_id) {
            if ($users_id > 0) {
                $DB->insert($table, ['items_id' => $items_id, 'users_id' => $users_id, 'mandatory' => 0]);
            }
        }
    }

    /**
     * Convidados de cada reunião já presente numa resposta — uma consulta
     * só, nunca uma por evento.
     *
     * @param array<int, int> $items_ids
     * @return array<int, array<int, array{users_id: int, name: string, mandatory: bool, status: string}>> items_id => lista de convidados
     */
    public static function getForItems(array $items_ids): array
    {
        if ($items_ids === []) {
            return [];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['items_id' => $items_ids]]) as $row) {
            $items_id = (int) $row['items_id'];
            $out[$items_id][] = [
                'users_id'  => (int) $row['users_id'],
                'name'      => EventProvider::getUserName((int) $row['users_id']),
                'mandatory' => (bool) $row['mandatory'],
                'status'    => (string) $row['status'],
            ];
        }

        return $out;
    }

    /**
     * Resposta do PRÓPRIO convidado — nunca de quem organizou a reunião por
     * ele. `$items_id` + o id de quem está logado precisam bater com uma
     * linha existente, senão não há o que responder.
     */
    public static function respond(int $items_id, string $status): bool
    {
        if (!in_array($status, [self::STATUS_ACCEPTED, self::STATUS_DECLINED], true)) {
            return false;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $me = (int) Session::getLoginUserID();
        if ($me <= 0) {
            return false;
        }

        return (bool) $DB->update(self::getTable(), ['status' => $status], [
            'items_id' => $items_id,
            'users_id' => $me,
        ]);
    }
}
