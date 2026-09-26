<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Substituição do item "Reservas" em Ferramentas.
 *
 * Mesma mecânica do Menu do planejamento: o hook `redefine_menus` troca para
 * onde o item nativo aponta, mantendo rótulo e ícone do core, e
 * `NativeRedirect` cuida de quem chega pela URL antiga. O usuário não precisa
 * aprender um nome novo — é a mesma "Reservas" de sempre, com outra tela.
 *
 * A chave do item nativo em `$menu['tools']['content']` é
 * `strtolower(ReservationItem::class)`, porque é `ReservationItem` que fornece
 * `getMenuContent()` para o setor Ferramentas (e não `Reservation`).
 */

namespace GlpiPlugin\Refactorytools;

use CommonGLPI;
use Reservation;
use ReservationItem;

class ReservationMenu extends CommonGLPI
{
    private const NATIVE_KEY = 'reservationitem';

    public static function getTypeName($nb = 0)
    {
        return Reservation::getTypeName($nb);
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-time';
    }

    public static function getPage(): string
    {
        return '/plugins/refactorytools/front/reservations.php';
    }

    /**
     * Chave usada como `$item` em `Html::header()`. Precisa casar com a chave
     * do item de menu, senão o GLPI não resolve
     * `$CFG_GLPI['javascript'][setor][item]` e a tela carrega sem FullCalendar.
     */
    public static function getMenuItemKey(): string
    {
        return self::NATIVE_KEY;
    }

    /**
     * @param array<string, mixed> $menu
     * @return array<string, mixed>
     */
    public static function redefineMenus(array $menu): array
    {
        if (!Settings::isTrue('override_native_reservation')) {
            return $menu;
        }

        // Sem o direito de reserva o item nativo nem existe para este usuário.
        if (!ReservationView::canView()) {
            return $menu;
        }

        if (!isset($menu['tools']['content'][self::NATIVE_KEY])) {
            return $menu;
        }

        $native = $menu['tools']['content'][self::NATIVE_KEY];

        // As `options`/`links` nativos são mantidos como estão: eles levam à
        // lista de itens reserváveis, que continua sendo onde se marca um
        // ativo como reservável. A única opção acrescentada é o Relatório,
        // e só para quem o administrador autorizou a vê-lo — ver
        // `Settings::canViewReservationReport()`.
        $options = $native['options'] ?? [];
        if (ReservationReport::canView()) {
            $options['refactorytools_reservation_report'] = [
                'title' => __('Reservation report', 'refactorytools'),
                'page'  => '/plugins/refactorytools/front/reservation_report.php',
                'icon'  => 'ti ti-chart-bar',
                'links' => [
                    'search' => '/plugins/refactorytools/front/reservation_report.php',
                ],
            ];
        }
        if (Settings::canViewReservationHistory()) {
            $options['refactorytools_reservation_history'] = [
                'title' => __('Reservation history', 'refactorytools'),
                'page'  => '/plugins/refactorytools/front/reservation_history.php',
                'icon'  => 'ti ti-history',
                'links' => [
                    'search' => '/plugins/refactorytools/front/reservation_history.php',
                ],
            ];
        }

        $menu['tools']['content'][self::NATIVE_KEY] = array_merge($native, [
            'page'    => self::getPage(),
            'options' => $options,
        ]);

        return $menu;
    }
}
