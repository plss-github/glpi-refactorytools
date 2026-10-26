<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Cores por usuário foram removidas na 1.0.8 — o administrador define a cor
 * de cada tipo de compromisso para a instância inteira. Esta classe existe
 * apenas para apagar a tabela em instalações que ainda a tinham.
 */

namespace GlpiPlugin\Refactorytools;

final class UserColors
{
    public static function uninstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = 'glpi_plugin_refactorytools_user_colors';
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }

        // Tabela do plugin com nome anterior (planner → refactorytools)
        $old_table = 'glpi_plugin_planner_user_colors';
        if ($DB->tableExists($old_table)) {
            $DB->doQuery("DROP TABLE `{$old_table}`");
        }
    }
}
