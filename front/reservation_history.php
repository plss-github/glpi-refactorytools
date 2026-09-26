<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Histórico de reservas — quem criou/editou/cancelou cada uma. Só para quem
 * o administrador autorizou (ver `Settings::canViewReservationHistory()`).
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Refactorytools\ReservationHistory;
use GlpiPlugin\Refactorytools\ReservationMenu;
use GlpiPlugin\Refactorytools\Settings;

Session::checkLoginUser();

if (!Settings::canViewReservationHistory()) {
    throw new AccessDeniedHttpException();
}

Html::header(
    __('Reservation history', 'refactorytools'),
    $_SERVER['PHP_SELF'],
    'tools',
    ReservationMenu::getMenuItemKey(),
    'refactorytools_reservation_history'
);

TemplateRenderer::getInstance()->display('@refactorytools/reservation_history.html.twig', [
    'entries' => ReservationHistory::getEntries(),
]);

Html::footer();
