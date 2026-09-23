<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Rotinas de instalação e desinstalação.
 */

use GlpiPlugin\Planner\EventNotes;
use GlpiPlugin\Planner\EventTypes;
use GlpiPlugin\Planner\KanbanPrefs;
use GlpiPlugin\Planner\Right;
use GlpiPlugin\Planner\Settings;
use GlpiPlugin\Planner\Share;
use GlpiPlugin\Planner\UserColors;

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
    UserColors::install($migration);
    EventNotes::install($migration);
    KanbanPrefs::install($migration);

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
    //
    // Os 3 IDs de categoria ficam de FORA deste save: `plugin_planner_install()`
    // roda de novo em toda ATUALIZAÇÃO de versão (não só na instalação), e um
    // `getDefaults()` sempre traz esses 3 campos como string vazia. Se
    // entrassem aqui, cada atualização apagaria o ID guardado ANTES de
    // `plugin_planner_seed_event_categories()` rodar — e como essa função só
    // recria a categoria quando não encontra um ID válido, o resultado seria
    // uma categoria NOVA a cada atualização, duplicando "Evento Interno" /
    // "Viagem" / "Reunião" indefinidamente.
    $defaults = Settings::getDefaults();
    foreach (['category_internal_id', 'category_travel_id', 'category_meeting_id'] as $key) {
        unset($defaults[$key]);
    }
    Settings::save($defaults);

    // Só depois de os defaults estarem gravados (e os 3 campos de categoria
    // preservados, por não terem sido tocados acima) é que a semeadura roda —
    // ela lê o ID atual antes de decidir se precisa criar a categoria.
    plugin_planner_seed_event_categories();

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
    UserColors::uninstall();
    EventNotes::uninstall();
    KanbanPrefs::uninstall();

    ProfileRight::deleteProfileRights([Right::NAME]);

    Settings::purge();

    return true;
}

/**
 * Cria as 3 categorias de evento (`PlanningEventCategory`) que distinguem
 * Evento Interno, Viagem e Reunião de um Evento Externo genérico — ver
 * `EventTypes`. Rodado a cada instalação/atualização (`plugin_planner_install`
 * roda nos dois casos), por isso é preciso não duplicar numa reinstalação:
 * cada categoria só é criada se a configuração ainda não guarda um ID válido
 * para ela.
 *
 * "Válido" aqui checa se a linha ainda EXISTE, não só se o ID está gravado —
 * um administrador pode ter apagado a categoria manualmente, e nesse caso
 * o plugin recria em vez de continuar apontando para um ID morto.
 */
function plugin_planner_seed_event_categories(): void
{
    $variants = [EventTypes::EVENT_INTERNAL, EventTypes::EVENT_TRAVEL, EventTypes::EVENT_MEETING];

    foreach ($variants as $variant) {
        $existing_id = Settings::getCategoryId($variant);

        if ($existing_id > 0) {
            $category = new PlanningEventCategory();
            if ($category->getFromDB($existing_id)) {
                continue;
            }
        }

        $category = new PlanningEventCategory();
        $new_id   = $category->add([
            'name'    => EventTypes::seedCategoryName($variant),
            'comment' => __('Created by the Pellissari New Planners plugin to tell this event type apart.', 'planner'),
        ]);

        if ($new_id) {
            Settings::setCategoryId($variant, (int) $new_id);
        }
    }
}

/**
 * Direitos do plugin, lidos pelo GLPI ao montar a matriz de perfis.
 *
 * @return array<string, string>
 */
function plugin_planner_getrights(): array
{
    return [
        Right::NAME => __('Planning (Pellissari New Planners)', 'planner'),
    ];
}
