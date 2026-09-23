<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Configuração do plugin.
 *
 * Fica atrás do direito READ_ALL: são chaves globais que mudam o alcance da
 * visibilidade de todo mundo (por exemplo, tornar a equipe recursiva), então
 * quem as edita já precisa ser alguém com visão administrativa da agenda.
 */

include('../../../inc/includes.php');

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Planner\EventProvider;
use GlpiPlugin\Planner\Menu;
use GlpiPlugin\Planner\Right;
use GlpiPlugin\Planner\Settings;
use GlpiPlugin\Planner\Share;

Session::checkRight(Right::NAME, Right::READ_ALL);

// CSRF já é validado (e o token consumido) pelo CheckCsrfListener do kernel
// antes deste arquivo ser incluído — repetir a checagem aqui derrubaria todo
// POST com 403. Ver a nota longa em ajax/share.php.
if (isset($_POST['update'])) {
    Settings::save($_POST);

    // As cores chegam como `type_color[<itemtype>]` e são gravadas à parte:
    // `Settings::save()` só aceita chaves escalares conhecidas, e misturar um
    // array nele abriria a porta para gravar estrutura arbitrária no contexto
    // do plugin.
    if (isset($_POST['type_color']) && is_array($_POST['type_color'])) {
        Settings::saveTypeColors($_POST['type_color']);
    }

    Session::addMessageAfterRedirect(
        htmlescape(__('Configuration saved.', 'planner')),
        false,
        INFO
    );
    Html::back();
    return;
}

Html::header(
    __('Pellissari New Planners', 'planner') . ' - ' . __('Configuration', 'planner'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    Menu::getMenuItemKey(),
    'planner_config'
);

TemplateRenderer::getInstance()->display('@planner/config.html.twig', [
    'settings'     => Settings::getAll(),
    'level_labels' => Share::getLevelLabels(),
    'mode_labels'  => Settings::getModeLabels(),
    'types'        => EventProvider::getAvailableTypesForAdmin(),
    'csrf'         => Session::getNewCSRFToken(),
]);

Html::footer();
