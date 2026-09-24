<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Configuração do plugin.
 *
 * Fica atrás do direito READ_ALL: são chaves globais que mudam o alcance da
 * visibilidade de todo mundo (por exemplo, tornar a equipe recursiva), então
 * quem as edita já precisa ser alguém com visão administrativa da agenda.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Refactorytools\EventProvider;
use GlpiPlugin\Refactorytools\Menu;
use GlpiPlugin\Refactorytools\ReservationView;
use GlpiPlugin\Refactorytools\Right;
use GlpiPlugin\Refactorytools\Settings;
use GlpiPlugin\Refactorytools\Share;

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
    if (isset($_POST['reservation_type_color']) && is_array($_POST['reservation_type_color'])) {
        Settings::saveReservationTypeColors($_POST['reservation_type_color']);
    }

    Session::addMessageAfterRedirect(
        htmlescape(__('Configuration saved.', 'refactorytools')),
        false,
        INFO
    );
    Html::back();
    return;
}

Html::header(
    __('Plugin configuration', 'refactorytools'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    Menu::getMenuItemKey(),
    'refactorytools_config'
);

TemplateRenderer::getInstance()->display('@refactorytools/config.html.twig', [
    'settings'     => Settings::getAll(),
    'level_labels' => Share::getLevelLabels(),
    'mode_labels'  => Settings::getModeLabels(),
    'types'        => EventProvider::getAvailableTypesForAdmin(),
    'reservation_types' => ReservationView::getReservableTypesForAdmin(),
    'csrf'         => Session::getNewCSRFToken(),
]);

Html::footer();
