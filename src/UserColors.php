<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Cores de tipo de compromisso, por USUÁRIO.
 *
 * A configuração do administrador (`Settings::getTypeColors()`) define o
 * padrão da instância inteira. Esta classe guarda a exceção pessoal: cada
 * pessoa pode preferir enxergar "Chamado" em outro tom, sem que isso afete a
 * tela de ninguém mais. A precedência é usuário > administrador > paleta de
 * fábrica (ver `EventProvider::getTypeColor()`).
 *
 * Tabela própria em vez de reaproveitar `glpi_configs` (que é por contexto
 * global, não por usuário) ou um campo solto em `glpi_users` (que exigiria
 * migração da tabela nativa a cada nova preferência). Uma linha por
 * (usuário, tipo) é o formato mais simples de guardar "algumas pessoas
 * mudaram alguns tipos" sem gravar um JSON grande para todo mundo.
 */

namespace GlpiPlugin\Planner;

use Migration;

final class UserColors
{
    private static function getTable(): string
    {
        return 'glpi_plugin_planner_user_colors';
    }

    /**
     * @return array<string, string> chave virtual do tipo => cor
     */
    public static function getForUser(int $users_id): array
    {
        if ($users_id <= 0) {
            return [];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['type_key', 'color'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $users_id],
        ]) as $row) {
            $out[(string) $row['type_key']] = (string) $row['color'];
        }

        return $out;
    }

    /**
     * @param array<string, string> $colors tipo => cor; vazio ou inválido
     *                                      apaga a customização daquele tipo
     *                                      (volta a herdar do administrador)
     */
    public static function saveForUser(int $users_id, array $colors): void
    {
        if ($users_id <= 0) {
            return;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $known = EventTypes::getAll();

        foreach ($colors as $type_key => $color) {
            if (!in_array($type_key, $known, true)) {
                continue;
            }

            $color = trim((string) $color);

            if ($color === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                // Sem valor válido: remove a linha, se existir, e a pessoa
                // volta a herdar a cor do administrador.
                $DB->delete(self::getTable(), ['users_id' => $users_id, 'type_key' => $type_key]);
                continue;
            }

            $color = strtolower($color);

            // Apaga e reinsere em vez de tentar decidir entre update/insert:
            // é o mesmo número de consultas e não depende de saber de
            // antemão se a linha já existia.
            $DB->delete(self::getTable(), ['users_id' => $users_id, 'type_key' => $type_key]);
            $DB->insert(self::getTable(), ['users_id' => $users_id, 'type_key' => $type_key, 'color' => $color]);
        }
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
                    `users_id` INT UNSIGNED NOT NULL,
                    `type_key` VARCHAR(64) NOT NULL,
                    `color` VARCHAR(7) NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`users_id`, `type_key`),
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
}
