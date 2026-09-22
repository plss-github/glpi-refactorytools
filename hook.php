<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Rotinas de instalação e desinstalação.
 */

use GlpiPlugin\Planner\Right;
use GlpiPlugin\Planner\Settings;
use GlpiPlugin\Planner\Share;

/**
 * Instalação.
 *
 * O GLPI chama esta função também a cada ATUALIZAÇÃO de versão do plugin, não
 * só na primeira instalação — por isso tudo aqui precisa ser idempotente.
 */
function plugin_planner_install(): bool
{
    $migration = new Migration(PLUGIN_PLANNER_VERSION);

    Share::install($migration);

    $migration->executeMigration();

    // `ProfileRight::addProfileRights()` cria a linha do direito com rights=0
    // ("sem acesso") para TODOS os perfis existentes. Sem isso, a matriz de
    // direitos mostraria o valor como indefinido em vez de "sem acesso".
    //
    // O guard existe porque, numa reinstalação/atualização, a linha já existe
    // e um segundo INSERT quebraria com erro de chave duplicada.
    if (countElementsInTable('glpi_profilerights', ['name' => Right::NAME]) === 0) {
        ProfileRight::addProfileRights([Right::NAME]);

        // Sem isto, nem quem instalou o plugin consegue abrir a tela até
        // entrar em Administração > Perfis e marcar as permissões à mão.
        foreach (Profile::getSuperAdminProfilesId() as $profiles_id) {
            ProfileRight::updateProfileRights($profiles_id, [Right::NAME => Right::all()]);
        }
    }

    // Grava os defaults de configuração explicitamente, para que a tela de
    // configuração mostre o estado real desde a primeira abertura em vez de
    // valores que só existem em memória.
    Settings::save(Settings::getDefaults());

    return true;
}

/**
 * Desinstalação: remove a tabela, o direito e a configuração.
 *
 * O direito é removido de todos os perfis: deixá-lo para trás povoaria a
 * matriz de direitos com uma linha órfã que nenhuma tela do GLPI sabe mais
 * explicar.
 */
function plugin_planner_uninstall(): bool
{
    Share::uninstall();

    ProfileRight::deleteProfileRights([Right::NAME]);

    Settings::purge();

    return true;
}

/**
 * Direitos do plugin, lidos pelo GLPI ao montar a matriz de perfis.
 *
 * @return array<string, string>
 */
function plugin_planner_getrights(): array
{
    return [
        Right::NAME => __('Planning (Planner)', 'planner'),
    ];
}
