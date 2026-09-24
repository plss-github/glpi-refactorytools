<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Relatório de reservas: totais por tipo de ativo e por pessoa, num período.
 *
 * Tela separada da agenda de Reservas de propósito — os números aqui não são
 * "quantas reservas neste calendário agora" (isso já existe, ver
 * `ReservationEventProvider::getStats()`), são um resumo administrativo, e
 * só quem o administrador autorizou (`Settings::canViewReservationReport()`)
 * deve enxergar. Misturar os dois exporia contagem para qualquer pessoa com
 * acesso à tela de Reservas, que é exatamente o que a mudança pediu para
 * parar de fazer.
 */

namespace GlpiPlugin\Refactorytools;

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Reservation;
use ReservationItem;

final class ReservationReport
{
    public static function canView(): bool
    {
        return ReservationView::canView() && Settings::canViewReservationReport();
    }

    public static function show(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $begin = (string) ($_GET['begin'] ?? date('Y-m-01'));
        $end   = (string) ($_GET['end'] ?? date('Y-m-t'));

        if (date_create($begin) === false) {
            $begin = date('Y-m-01');
        }
        if (date_create($end) === false) {
            $end = date('Y-m-t');
        }

        TemplateRenderer::getInstance()->display('@refactorytools/reservation_report.html.twig', [
            'root_doc'   => $CFG_GLPI['root_doc'],
            'begin'      => $begin,
            'end'        => $end,
            'by_type'    => self::getCountsByType($begin, $end),
            'by_user'    => self::getCountsByUser($begin, $end),
            'total'      => self::getTotal($begin, $end),
        ]);
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private static function getCountsByType(string $begin, string $end): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $counts = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_reservationitems.itemtype', new QueryExpression('COUNT(*) AS c')],
            'FROM'       => Reservation::getTable(),
            'INNER JOIN' => [
                ReservationItem::getTable() => [
                    'ON' => [
                        Reservation::getTable()     => 'reservationitems_id',
                        ReservationItem::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE'   => [
                'glpi_reservations.begin' => ['<=', $end . ' 23:59:59'],
                'glpi_reservations.end'   => ['>=', $begin . ' 00:00:00'],
            ],
            'GROUPBY' => 'glpi_reservationitems.itemtype',
            'ORDER'   => 'c DESC',
        ]) as $row) {
            $itemtype = (string) $row['itemtype'];
            $counts[] = [
                'label' => class_exists($itemtype) ? $itemtype::getTypeName(2) : $itemtype,
                'count' => (int) $row['c'],
            ];
        }

        return $counts;
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private static function getCountsByUser(string $begin, string $end): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $counts = [];
        foreach ($DB->request([
            'SELECT'  => ['users_id', new QueryExpression('COUNT(*) AS c')],
            'FROM'    => Reservation::getTable(),
            'WHERE'   => [
                'begin' => ['<=', $end . ' 23:59:59'],
                'end'   => ['>=', $begin . ' 00:00:00'],
            ],
            'GROUPBY' => 'users_id',
            'ORDER'   => 'c DESC',
            'LIMIT'   => 20,
        ]) as $row) {
            $counts[] = [
                'label' => EventProvider::getUserName((int) $row['users_id']),
                'count' => (int) $row['c'],
            ];
        }

        return $counts;
    }

    private static function getTotal(string $begin, string $end): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (int) $DB->request([
            'COUNT'   => 'c',
            'FROM'    => Reservation::getTable(),
            'WHERE'   => [
                'begin' => ['<=', $end . ' 23:59:59'],
                'end'   => ['>=', $begin . ' 00:00:00'],
            ],
        ])->current()['c'];
    }
}
