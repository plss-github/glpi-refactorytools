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
use GlpiPlugin\Refactorytools\ReservationSearchView;
use GlpiPlugin\Refactorytools\Settings;

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

if (!Settings::canViewReservationReport()) {
    throw new AccessDeniedHttpException();
}

Html::header(
    __('Reservation report', 'refactorytools'),
    $_SERVER['PHP_SELF'],
    'tools',
    ReservationMenu::getMenuItemKey(),
    'refactorytools_reservation_report'
);

// Tab nav
echo '<ul class="nav nav-tabs mb-0 px-3 pt-2">';
echo '<li class="nav-item"><a class="nav-link" href="' . htmlescape($CFG_GLPI['root_doc']) . '/plugins/refactorytools/front/reservations.php"><i class="ti ti-calendar me-1"></i>' . htmlescape(__('Reservations', 'refactorytools')) . '</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="' . htmlescape($CFG_GLPI['root_doc']) . '/plugins/refactorytools/front/reservation_history.php"><i class="ti ti-history me-1"></i>' . htmlescape(__('History', 'refactorytools')) . '</a></li>';
echo '<li class="nav-item"><a class="nav-link active" href="#"><i class="ti ti-chart-bar me-1"></i>' . htmlescape(__('Reports', 'refactorytools')) . '</a></li>';
echo '</ul>';

Search::show(ReservationSearchView::class);

Html::footer();
