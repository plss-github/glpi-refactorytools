<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Plugin GLPI 11.0.x — remodela o módulo de Planejamento (agenda) com uma
 * interface atual e adiciona visibilidade de equipe para supervisores.
 *
 * Por padrão o plugin SUBSTITUI o item "Planejamento" de Assistência: o rótulo
 * do menu continua sendo o do core, só o destino muda (ver Menu e
 * NativeRedirect). A substituição é desligável em Configuração, e aí as duas
 * telas convivem. O código do planejamento nativo nunca é alterado nem
 * desativado — as telas que o plugin não substitui (exportação iCal, popup de
 * disponibilidade) seguem intactas.
 *
 * Sobre o modelo de visibilidade, que vai além dos três direitos nativos
 * (`Planning::READMY` / `READGROUP` / `READALL`):
 *
 *   - liderados diretos, derivados do campo `users_id_supervisor` do usuário;
 *   - colegas dos meus grupos;
 *   - agendas compartilhadas explicitamente, com aceite do dono.
 *
 * Licença: AGPL-3.0. O GLPI é GPL-3.0-or-later; a GPLv3 §13 permite combinar
 * código GPLv3 com AGPLv3 num mesmo programa — ver LICENSE.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Refactorytools\EventNoteItem;
use GlpiPlugin\Refactorytools\Menu;
use GlpiPlugin\Refactorytools\NativeRedirect;
use GlpiPlugin\Refactorytools\NotificationTargetEventNoteItem;
use GlpiPlugin\Refactorytools\ProfileRights;
use GlpiPlugin\Refactorytools\ReservationMenu;
use GlpiPlugin\Refactorytools\Share;

define('PLUGIN_REFACTORYTOOLS_VERSION', '0.11.9');

// Alvo: GLPI 11.0.x. As assinaturas usadas aqui (Planning::$rightname,
// CFG_GLPI['planning_types'], populatePlanning(), Html::requireJs('fullcalendar'))
// foram conferidas contra o código-fonte da imagem oficial glpi/glpi:11.0.9.
define('PLUGIN_REFACTORYTOOLS_MIN_GLPI', '11.0.0');
define('PLUGIN_REFACTORYTOOLS_MAX_GLPI', '11.9.99');

/**
 * Init: registrado a cada carregamento do GLPI.
 */
function plugin_init_refactorytools(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['refactorytools'] = true;

    // Entrada própria no setor "Assistência". Com a substituição ligada (o
    // padrão) ela é removida por Menu::redefineMenus(), que em troca aponta o
    // item nativo para cá — o registro precisa existir mesmo assim, porque é
    // ele que serve de menu quando a substituição está desligada.
    $PLUGIN_HOOKS['menu_toadd']['refactorytools'] = [
        'helpdesk' => Menu::class,
    ];

    // Aba própria de direitos em Administração > Perfis. `Profile::getRightsForForm()`
    // (a matriz nativa) é cacheada e não tem ponto de extensão para plugins —
    // uma aba própria é o caminho usado pelo próprio core.
    Plugin::registerClass(ProfileRights::class, ['addtabon' => Profile::class]);
    Plugin::registerClass(Share::class);

    // Notificação por e-mail quando alguém grava uma nota (ver
    // `EventNotes::save()` / `NotificationTargetEventNoteItem`). O item
    // precisa estar registrado para aparecer na tela de administração de
    // Modelos de notificação — a classe de destino já é resolvida pelo nome
    // (mesmo namespace do item) independente deste registro.
    Plugin::registerClass(EventNoteItem::class, ['notificationtemplates_types' => true]);

    // Substituição dos itens nativos "Planejamento" (Assistência) e "Reservas"
    // (Ferramentas). O hook aceita um callable só por plugin, então as duas
    // substituições passam por uma função que encadeia as duas.
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['refactorytools'] = 'plugin_refactorytools_redefine_menus';

    // Link de configuração na tela Configuração > Plugins. É o lugar onde o
    // GLPI espera encontrar a configuração de um plugin — o menu lateral não
    // exibe as `options` de um item (elas só alimentam o breadcrumb), então
    // sem este hook a tela de configuração ficaria sem caminho de acesso a
    // partir do menu.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['refactorytools'] = 'front/config.form.php';

    // Quem chega na URL antiga (/front/planning.php) por favorito ou link
    // também precisa cair na tela nova — o hook de menu sozinho não cobre isso.
    $PLUGIN_HOOKS[Hooks::POST_INIT]['refactorytools'] = NativeRedirect::class . '::handle';

    // Histórico de reservas (ver `ReservationHistory`): `Reservation` não
    // tem `$dohistory` nativo, então isto é alimentado por hook em vez de
    // ler `glpi_logs` (que nunca teria uma linha de reserva para ler). Os
    // três hooks cobrem criar, editar (inclusive pelo formulário NATIVO,
    // que o plugin não controla) e cancelar/apagar.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['refactorytools'] = [
        Reservation::class => 'plugin_refactorytools_reservation_add',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['refactorytools'] = [
        Reservation::class => 'plugin_refactorytools_reservation_update',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['refactorytools'] = [
        Reservation::class => 'plugin_refactorytools_reservation_purge',
    ];

    // O GLPI só carrega o FullCalendar quando a lib está declarada em
    // $CFG_GLPI['javascript'][setor][item] (ver Html::header(), que também
    // injeta lib/fullcalendar.css nesse caso). `$item` é passado em minúsculas
    // por Html::header(), daí o strtolower aqui — sem isso a comparação falha
    // e o calendário nunca é montado.
    //
    // A chave depende de a substituição estar ligada (ver Menu::getMenuItemKey()),
    // então as duas são registradas — assim a tela não fica sem calendário logo
    // depois de alguém ligar ou desligar a substituição.
    //
    // A entrada 'planning' é MESCLADA, nunca sobrescrita: ela é do core e
    // carrega também 'planning' (o planning.js nativo) e 'clipboard'. Trocar o
    // array inteiro deixaria a tela nativa sem o próprio JS quando a
    // substituição estivesse desligada.
    $CFG_GLPI['javascript']['helpdesk'][strtolower(Menu::class)] = ['fullcalendar'];
    $CFG_GLPI['javascript']['helpdesk']['planning'] = array_values(array_unique(array_merge(
        $CFG_GLPI['javascript']['helpdesk']['planning'] ?? [],
        ['fullcalendar']
    )));

    // A tela de Reservas usa o mesmo calendário. A entrada 'reservationitem'
    // do core carrega as libs de reserva; mesclar preserva isso e acrescenta
    // o FullCalendar, que a tela nativa de lista não pedia.
    $CFG_GLPI['javascript']['tools'][ReservationMenu::getMenuItemKey()] = array_values(array_unique(array_merge(
        $CFG_GLPI['javascript']['tools'][ReservationMenu::getMenuItemKey()] ?? [],
        ['fullcalendar']
    )));

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['refactorytools']        = 'public/css/refactorytools.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['refactorytools'] = 'public/js/refactorytools.js';
}

/**
 * Encadeia as substituições de menu do plugin.
 *
 * `$PLUGIN_HOOKS['redefine_menus']` guarda UM callable por plugin, e o GLPI
 * chama o hook em dois pontos de `Html.php` sobre o mesmo array — as duas
 * funções abaixo já são idempotentes, então aplicar de novo não muda nada.
 *
 * @param array<string, mixed> $menu
 * @return array<string, mixed>
 */
function plugin_refactorytools_redefine_menus(array $menu): array
{
    $menu = Menu::redefineMenus($menu);

    return ReservationMenu::redefineMenus($menu);
}

/**
 * Metadados exibidos na tela de plugins.
 */
function plugin_version_refactorytools(): array
{
    return [
        'name'         => 'Pellissari RefactoryTools',
        'version'      => PLUGIN_REFACTORYTOOLS_VERSION,
        'author'       => 'Pellissari',
        'license'      => 'AGPL-3.0',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_REFACTORYTOOLS_MIN_GLPI,
                'max' => PLUGIN_REFACTORYTOOLS_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Pré-requisitos de ambiente (o GLPI já valida `requirements` acima).
 */
function plugin_refactorytools_check_prerequisites(): bool
{
    return true;
}

/**
 * Rede de segurança para atualizações do GLPI: confirma que as peças do core
 * das quais este plugin depende diretamente ainda existem. Se uma delas for
 * renomeada/removida numa versão futura, a ativação falha aqui com mensagem
 * clara, em vez de o plugin quebrar num fatal error numa tela aleatória.
 *
 * Lista deliberadamente curta — cobre só o que é específico do subsistema de
 * planejamento (o ponto menos estável), não cada chamada do plugin.
 */
function plugin_refactorytools_check_config($verbose = false): bool
{
    /** @var array $CFG_GLPI */
    /** @var \DBmysql $DB */
    global $CFG_GLPI, $DB;

    // ATENÇÃO: esta função NÃO pode usar as classes do próprio plugin.
    // `Plugin::checkPluginState()` a chama antes de `Plugin::activate()`
    // registrar o autoloader PSR-4 do plugin — qualquer
    // `GlpiPlugin\Refactorytools\*` aqui morre com ClassNotFoundError e a ativação
    // falha com uma mensagem que não explica nada (confirmado testando a
    // ativação contra o GLPI 11.0.9: o rastro passa por
    // ActivateCommand::execute() -> checkPluginState(), não por activate()).
    // Só classes e funções do core.

    $checks = [
        '\Planning::$rightname'              => property_exists(Planning::class, 'rightname'),
        '\Planning::READALL / READGROUP'     => defined(Planning::class . '::READALL')
                                                 && defined(Planning::class . '::READGROUP'),
        "CFG_GLPI['planning_types']"          => isset($CFG_GLPI['planning_types'])
                                                 && is_array($CFG_GLPI['planning_types']),
        '\Html::requireJs()'                 => method_exists(Html::class, 'requireJs'),
        '\Profile::displayRightsChoiceMatrix()' => method_exists(Profile::class, 'displayRightsChoiceMatrix'),
        '\ProfileRight::addProfileRights()'  => method_exists(ProfileRight::class, 'addProfileRights'),
        // A visibilidade de equipe depende deste campo; se sumir, o direito
        // "ver a agenda da minha equipe" perde de onde derivar a equipe.
        'glpi_users.users_id_supervisor'      => $DB->fieldExists('glpi_users', 'users_id_supervisor'),
    ];

    $missing = array_keys(array_filter($checks, static fn($ok) => !$ok));

    if ($missing !== []) {
        if ($verbose) {
            echo __('Pellissari RefactoryTools: GLPI dependencies not found or incompatible:', 'refactorytools')
                . ' ' . implode(', ', $missing);
        }
        return false;
    }

    return true;
}
