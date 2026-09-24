<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Relatório de reservas — só para quem o administrador autorizou (ver
 * `Settings::canViewReservationReport()`), independente do direito nativo de
 * reserva.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Refactorytools\ReservationMenu;
use GlpiPlugin\Refactorytools\ReservationReport;

Session::checkLoginUser();

if (!ReservationReport::canView()) {
    // Ver front/reservations.php — `Html::displayRightError()` está
    // depreciado desde o GLPI 11.
    throw new AccessDeniedHttpException();
}

Html::header(
    __('Reservation report', 'refactorytools'),
    $_SERVER['PHP_SELF'],
    'tools',
    ReservationMenu::getMenuItemKey(),
    'refactorytools_reservation_report'
);

ReservationReport::show();

Html::footer();
