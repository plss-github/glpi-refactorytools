<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Histórico de reservas — quem criou/editou/cancelou cada uma.
 * Qualquer pessoa com acesso à tela de Reservas pode ver o histórico.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Refactorytools\ReservationAuditSearch;
use GlpiPlugin\Refactorytools\ReservationMenu;
use GlpiPlugin\Refactorytools\Settings;

/** @var array $CFG_GLPI */
global $CFG_GLPI;

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

// Tab nav
$can_report = Settings::canViewReservationReport();
echo '<ul class="nav nav-tabs mb-0 px-3 pt-2">';
echo '<li class="nav-item"><a class="nav-link" href="' . htmlescape($CFG_GLPI['root_doc']) . '/plugins/refactorytools/front/reservations.php"><i class="ti ti-calendar me-1"></i>' . htmlescape(__('Reservations', 'refactorytools')) . '</a></li>';
echo '<li class="nav-item"><a class="nav-link active" href="#"><i class="ti ti-history me-1"></i>' . htmlescape(__('History', 'refactorytools')) . '</a></li>';
if ($can_report) {
    echo '<li class="nav-item"><a class="nav-link" href="' . htmlescape($CFG_GLPI['root_doc']) . '/plugins/refactorytools/front/reservation_report.php"><i class="ti ti-chart-bar me-1"></i>' . htmlescape(__('Reports', 'refactorytools')) . '</a></li>';
}
echo '</ul>';

Search::show(ReservationAuditSearch::class);

Html::footer();
