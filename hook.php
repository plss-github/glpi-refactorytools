<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Rotinas de instalação e desinstalação.
 */

use GlpiPlugin\Refactorytools\EventNoteItem;
use GlpiPlugin\Refactorytools\EventNotes;
use GlpiPlugin\Refactorytools\EventTypes;
use GlpiPlugin\Refactorytools\KanbanPrefs;
use GlpiPlugin\Refactorytools\MeetingGuest;
use GlpiPlugin\Refactorytools\ReservationHistory;
use GlpiPlugin\Refactorytools\Right;
use GlpiPlugin\Refactorytools\Settings;
use GlpiPlugin\Refactorytools\Share;
use GlpiPlugin\Refactorytools\UserColors;
use GlpiPlugin\Refactorytools\VisitLink;

/**
 * Instalação.
 *
 * O GLPI chama esta função também a cada ATUALIZAÇÃO de versão do plugin, não
 * só na primeira instalação — por isso tudo aqui precisa ser idempotente.
 */
function plugin_refactorytools_install(): bool
{
    $migration = new Migration(PLUGIN_REFACTORYTOOLS_VERSION);

    // Rename do plugin (planner -> refactorytools): linhas que outras
    // tabelas do CORE guardam contra as classes/o direito deste plugin pelo
    // nome/itemtype ANTIGO ("GlpiPlugin\Planner\...", 'plugin_planner_planning')
    // precisam apontar para o nome NOVO, senão a instalação parece "zerada"
    // (direitos voltando a 0, notificação duplicada) mesmo com os dados das
    // tabelas próprias preservados pelos ::install() abaixo.
    plugin_refactorytools_migrate_from_planner();

    Share::install($migration);
    EventNotes::install($migration);
    KanbanPrefs::install($migration);
    VisitLink::install($migration);
    MeetingGuest::install($migration);
    ReservationHistory::install($migration);

    $migration->executeMigration();

    // A cor de tipo de compromisso deixou de ser personalizável por usuário
    // (ver `EventProvider::getTypeColor()`): só o administrador define, para
    // "vermelho" continuar significando a mesma coisa para quem olha a
    // agenda de outra pessoa. `uninstall()` aqui, e não mais `install()`
    // acima, existe para limpar a tabela de quem já tinha o plugin —
    // idempotente (`DROP TABLE IF EXISTS`), então rodar em toda atualização
    // não tem efeito depois da primeira vez.
    UserColors::uninstall();

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
    // Os 3 IDs de categoria ficam de FORA deste save: `plugin_refactorytools_install()`
    // roda de novo em toda ATUALIZAÇÃO de versão (não só na instalação), e um
    // `getDefaults()` sempre traz esses 3 campos como string vazia. Se
    // entrassem aqui, cada atualização apagaria o ID guardado ANTES de
    // `plugin_refactorytools_seed_event_categories()` rodar — e como essa função só
    // recria a categoria quando não encontra um ID válido, o resultado seria
    // uma categoria NOVA a cada atualização, duplicando "Evento Interno" /
    // "Viagem" / "Reunião" indefinidamente.
    $defaults = Settings::getDefaults();
    foreach (['category_internal_id', 'category_travel_id', 'category_meeting_id', 'category_visit_id'] as $key) {
        unset($defaults[$key]);
    }
    Settings::save($defaults);

    // Só depois de os defaults estarem gravados (e os 3 campos de categoria
    // preservados, por não terem sido tocados acima) é que a semeadura roda —
    // ela lê o ID atual antes de decidir se precisa criar a categoria.
    plugin_refactorytools_seed_event_categories();

    plugin_refactorytools_seed_note_notification();

    return true;
}

/**
 * Ajusta, pelo NOME/ITEMTYPE antigo, o que outras tabelas do core já tinham
 * gravado quando o plugin ainda se chamava "planner". Roda toda vez
 * (idempotente: cada UPDATE já não encontra mais linhas com o nome antigo
 * depois da primeira execução) — sem isto:
 *
 *   - `glpi_profilerights` continuaria com a linha `plugin_planner_planning`;
 *     o guard de `addProfileRights()` logo abaixo não reconheceria
 *     `plugin_refactorytools_planning` como já existente e criaria uma linha
 *     NOVA com direitos ZERADOS para todo perfil, sem herdar o que já tinha
 *     sido configurado.
 *   - `glpi_notifications`/`glpi_notificationtemplates` continuariam
 *     apontando para o itemtype `GlpiPlugin\Planner\EventNoteItem`; o guard
 *     de `plugin_refactorytools_seed_note_notification()` não acharia
 *     `GlpiPlugin\Refactorytools\EventNoteItem` e criaria um modelo
 *     duplicado, deixando o antigo órfão (itemtype que não existe mais).
 */
function plugin_refactorytools_migrate_from_planner(): void
{
    /** @var \DBmysql $DB */
    global $DB;

    if ($DB->fieldExists('glpi_profilerights', 'name')) {
        $DB->update(
            'glpi_profilerights',
            ['name' => Right::NAME],
            ['name' => 'plugin_planner_planning']
        );
    }

    $old_itemtype = 'GlpiPlugin\\Planner\\EventNoteItem';
    $new_itemtype = EventNoteItem::class;

    foreach (['glpi_notifications', 'glpi_notificationtemplates'] as $table) {
        if ($DB->tableExists($table) && $DB->fieldExists($table, 'itemtype')) {
            $DB->update($table, ['itemtype' => $new_itemtype], ['itemtype' => $old_itemtype]);
        }
    }
}

/**
 * Cria o modelo de notificação "nova nota" — sem isto, o administrador
 * precisaria montar o modelo à mão em Configuração > Notificações antes de
 * qualquer e-mail sair. Idempotente: só cria se ainda não existir uma
 * `Notification` para este itemtype+evento (checagem por linha, não por
 * versão — mais simples e sobrevive a uma reinstalação fora de ordem).
 */
function plugin_refactorytools_seed_note_notification(): void
{
    /** @var \DBmysql $DB */
    global $DB;

    $itemtype = EventNoteItem::class;
    $event    = 'new_note';

    $existing = $DB->request([
        'FROM'  => Notification::getTable(),
        'WHERE' => ['itemtype' => $itemtype, 'event' => $event],
        'LIMIT' => 1,
    ])->current();

    if ($existing) {
        return;
    }

    $template = new NotificationTemplate();
    $templates_id = $template->add([
        'name'     => __('RefactoryTools: new note', 'refactorytools'),
        'itemtype' => $itemtype,
    ]);

    if (!$templates_id) {
        return;
    }

    $translation = new NotificationTemplateTranslation();
    $translation->add([
        'notificationtemplates_id' => $templates_id,
        // Vazio = modelo padrão, usado por qualquer idioma sem tradução
        // própria — é a mesma convenção do core.
        'language'     => '',
        'subject'      => __('A note was added to your schedule', 'refactorytools'),
        'content_text' => "##refactorytoolsnote.author##\n\n##refactorytoolsnote.content##\n\n##refactorytoolsnote.url##",
        'content_html' => '<p><strong>##refactorytoolsnote.author##</strong></p>'
            . '<p>##refactorytoolsnote.content##</p>'
            . '<p><a href="##refactorytoolsnote.url##">##refactorytoolsnote.url##</a></p>',
    ]);

    $notification = new Notification();
    $notifications_id = $notification->add([
        'name'         => __('RefactoryTools: new note', 'refactorytools'),
        'itemtype'     => $itemtype,
        'event'        => $event,
        'is_active'    => 1,
        // Entidade raiz + recursivo: vale para a instalação inteira, não só
        // para quem estiver na entidade raiz no momento em que a nota for
        // gravada.
        'entities_id'  => 0,
        'is_recursive' => 1,
    ]);

    if (!$notifications_id) {
        return;
    }

    $link = new Notification_NotificationTemplate();
    $link->add([
        'notifications_id'         => $notifications_id,
        'notificationtemplates_id' => $templates_id,
        'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
    ]);

    // Quem recebe: sem esta linha em `glpi_notificationtargets`, a
    // notificação existiria mas não teria destinatário nenhum — é essa
    // tabela, não os métodos de `NotificationTarget`, que
    // `NotificationEventAbstract::raise()` lê para saber para quem mandar
    // (ver `NotificationTargetEventNoteItem::TARGET_OWNER`). INSERT direto,
    // e não `(new NotificationTarget())->add()`, porque essa classe também
    // é a base de toda a lógica de RESOLUÇÃO de destinatário (o que este
    // arquivo não quer executar aqui) — a tabela é só 4 colunas simples.
    $DB->insert('glpi_notificationtargets', [
        'items_id'         => 9999,
        'type'             => Notification::USER_TYPE,
        'notifications_id' => $notifications_id,
        'is_exclusion'     => 0,
    ]);
}

/**
 * Desinstalação: remove a tabela, o direito e a configuração.
 *
 * O direito é removido de todos os perfis: deixá-lo para trás povoaria a
 * matriz de direitos com uma linha órfã que nenhuma tela do GLPI sabe mais
 * explicar.
 */
function plugin_refactorytools_uninstall(): bool
{
    Share::uninstall();
    UserColors::uninstall();
    EventNotes::uninstall();
    KanbanPrefs::uninstall();
    VisitLink::uninstall();
    MeetingGuest::uninstall();
    ReservationHistory::uninstall();

    ProfileRight::deleteProfileRights([Right::NAME]);

    Settings::purge();

    return true;
}

/**
 * Cria as 3 categorias de evento (`PlanningEventCategory`) que distinguem
 * Evento Interno, Viagem e Reunião de um Evento Externo genérico — ver
 * `EventTypes`. Rodado a cada instalação/atualização (`plugin_refactorytools_install`
 * roda nos dois casos), por isso é preciso não duplicar numa reinstalação:
 * cada categoria só é criada se a configuração ainda não guarda um ID válido
 * para ela.
 *
 * "Válido" aqui checa se a linha ainda EXISTE, não só se o ID está gravado —
 * um administrador pode ter apagado a categoria manualmente, e nesse caso
 * o plugin recria em vez de continuar apontando para um ID morto.
 */
function plugin_refactorytools_seed_event_categories(): void
{
    $variants = [EventTypes::EVENT_INTERNAL, EventTypes::EVENT_TRAVEL, EventTypes::EVENT_MEETING, EventTypes::EVENT_VISIT];

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
            'comment' => __('Created by the Pellissari RefactoryTools plugin to tell this event type apart.', 'refactorytools'),
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
function plugin_refactorytools_getrights(): array
{
    return [
        Right::NAME => __('Planning (Pellissari RefactoryTools)', 'refactorytools'),
    ];
}

/**
 * Histórico de reservas (ver `ReservationHistory`) — os três hooks abaixo
 * cobrem criar, editar e cancelar/apagar, INCLUSIVE pelo formulário nativo
 * de reserva, que o plugin não controla diretamente.
 */
function plugin_refactorytools_reservation_add(Reservation $reservation): void
{
    ReservationHistory::log($reservation, ReservationHistory::ACTION_ADD);
}

function plugin_refactorytools_reservation_update(Reservation $reservation): void
{
    ReservationHistory::log($reservation, ReservationHistory::ACTION_UPDATE);
}

function plugin_refactorytools_reservation_purge(Reservation $reservation): void
{
    ReservationHistory::log($reservation, ReservationHistory::ACTION_PURGE);
}
