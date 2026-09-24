<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Relatório de reservas — só para quem o administrador autorizou (ver
 * `Settings::canViewReservationReport()`), independente do direito nativo de
 * reserva.
 */

use GlpiPlugin\Refactorytools\ReservationMenu;
use GlpiPlugin\Refactorytools\ReservationReport;

Session::checkLoginUser();

if (!ReservationReport::canView()) {
    Html::displayRightError();
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
