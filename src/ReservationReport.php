<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Gate de acesso à tela de relatório de reservas. O relatório em si é
 * renderizado por `ReservationSearchView` + `Search::show()` em
 * `front/reservation_report.php`. Esta classe existe para que
 * `ReservationMenu` possa decidir se exibe a aba "Relatórios" sem depender
 * diretamente de `Settings`.
 */

namespace GlpiPlugin\Refactorytools;

final class ReservationReport
{
    public static function canView(): bool
    {
        return ReservationView::canView() && Settings::canViewReservationReport();
    }
}
