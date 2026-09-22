<?php

/**
 * Planner
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

namespace GlpiPlugin\Planner;

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
        return '/plugins/planner/front/reservations.php';
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

        // Só o destino muda. As `options` e os `links` do core são mantidos
        // como estão: eles levam à lista de itens reserváveis, que continua
        // sendo onde se marca um ativo como reservável.
        //
        // Nenhuma opção nova é acrescentada de propósito. Uma opção com o
        // mesmo nome do item deixaria a trilha como "Reservas / Reservas" —
        // uma migalha a mais que não leva a lugar nenhum novo.
        $menu['tools']['content'][self::NATIVE_KEY] = array_merge($native, [
            'page' => self::getPage(),
        ]);

        return $menu;
    }
}
