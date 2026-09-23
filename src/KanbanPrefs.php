<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Ordem das colunas de SITUAÇÃO no Kanban do Planejamento, por USUÁRIO.
 *
 * `Planning::INFO/TODO/DONE` valem 0/1/2 — a ordem padrão da tela
 * (A fazer, Informação, Concluído) é [1, 0, 2]. Arrastar o cabeçalho de uma
 * coluna no cliente só muda a APRESENTAÇÃO; esta classe só guarda essa
 * preferência, não decide o que cada estado significa.
 */

namespace GlpiPlugin\Refactorytools;

use Migration;

final class KanbanPrefs
{
    /** @var array<int, int> */
    private const DEFAULT_ORDER = [1, 0, 2];

    private static function getTable(): string
    {
        return 'glpi_plugin_refactorytools_kanban_prefs';
    }

    /** @return array<int, int> */
    public static function getStateOrder(int $users_id): array
    {
        if ($users_id <= 0) {
            return self::DEFAULT_ORDER;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
        ])->current();

        if (!$row) {
            return self::DEFAULT_ORDER;
        }

        $order = self::parseAndValidate((string) $row['state_order']);

        return $order ?? self::DEFAULT_ORDER;
    }

    /**
     * @param array<int, int|string> $order
     */
    public static function saveStateOrder(int $users_id, array $order): bool
    {
        if ($users_id <= 0) {
            return false;
        }

        $order = self::parseAndValidate(implode(',', $order));
        if ($order === null) {
            return false;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $csv = implode(',', $order);

        $existing = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
        ])->current();

        if ($existing) {
            return $DB->update(self::getTable(), ['state_order' => $csv], ['id' => $existing['id']]);
        }

        return (bool) $DB->insert(self::getTable(), ['users_id' => $users_id, 'state_order' => $csv]);
    }

    /**
     * Só aceita uma permutação exata dos 3 estados conhecidos — qualquer
     * outra coisa (valor cru de outra versão, entrada manipulada) cai no
     * default em vez de desenhar colunas a menos ou duplicadas.
     *
     * @return array<int, int>|null
     */
    private static function parseAndValidate(string $csv): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($p) => $p !== ''));
        $order = array_map('intval', $parts);

        $sorted   = $order;
        $expected = self::DEFAULT_ORDER;
        sort($sorted);
        sort($expected);

        if ($sorted !== $expected) {
            return null;
        }

        return $order;
    }

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();

        // Rename do plugin (planner -> refactorytools): a tabela de uma
        // instalação antiga só precisa mudar de nome, os dados continuam
        // válidos como estão.
        $old_table = 'glpi_plugin_planner_kanban_prefs';
        if (!$DB->tableExists($table) && $DB->tableExists($old_table)) {
            $DB->doQuery("RENAME TABLE `{$old_table}` TO `{$table}`");
        }

        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `users_id` INT UNSIGNED NOT NULL,
                    `state_order` VARCHAR(20) NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`users_id`)
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
}
