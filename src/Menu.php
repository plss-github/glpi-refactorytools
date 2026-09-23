<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Entrada de menu do plugin em Assistência.
 *
 * Tem dois comportamentos, controlados pela configuração
 * `override_native_planning`:
 *
 *  - LIGADO (padrão): o plugin SUBSTITUI o item "Planejamento" nativo. O
 *    rótulo continua sendo o do core (`Planning::getMenuName()`, já traduzido
 *    em todos os idiomas do GLPI) — quem usa o sistema não precisa aprender um
 *    nome novo; "Planner" é o nome do plugin, não do item de menu. Só o
 *    destino muda. A entrada própria do plugin é removida para não ficarem
 *    dois itens parecidos lado a lado.
 *
 *  - DESLIGADO: o item nativo fica intacto e o Planner aparece como entrada
 *    separada. Útil para comparar as duas telas durante a adoção.
 *
 * A substituição é feita pelo hook `redefine_menus`, que o GLPI aplica sobre o
 * menu já montado (ver Html::generateMenuSession()). É o ponto de extensão
 * documentado para isso — não há necessidade de tocar em `Planning` nem de
 * desativar nada do core.
 */

namespace GlpiPlugin\Planner;

use CommonGLPI;
use Planning;

class Menu extends CommonGLPI
{
    /**
     * Chave do item nativo dentro de `$menu['helpdesk']['content']`.
     * O core monta essa chave como `strtolower(Planning::class)`.
     */
    private const NATIVE_KEY = 'planning';

    public static function getTypeName($nb = 0)
    {
        return __('Pellissari New Planners', 'planner');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-week';
    }

    public static function canView(): bool
    {
        return Right::canUse();
    }

    /**
     * Chave usada como `$item` em `Html::header()`.
     *
     * Precisa casar com a chave do item de menu correspondente, senão o
     * breadcrumb não destaca nada e — mais importante — o GLPI não resolve
     * `$CFG_GLPI['javascript'][setor][item]` e a página carrega sem o
     * FullCalendar. Como a chave muda conforme a substituição esteja ligada
     * ou não, ela é calculada num lugar só.
     */
    public static function getMenuItemKey(): string
    {
        return Settings::isTrue('override_native_planning')
            ? self::NATIVE_KEY
            : strtolower(self::class);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getMenuContent()
    {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => self::getPlannerPage(),
            'icon'  => self::getIcon(),
        ];

        $menu['options'] = self::getOptions();

        return $menu;
    }

    /**
     * Substitui o item "Planejamento" do setor Assistência pelo do plugin.
     *
     * Registrado em `$PLUGIN_HOOKS['redefine_menus']`. O GLPI chama este hook
     * em dois pontos de `Html.php` sobre o mesmo array, então a função precisa
     * ser idempotente: aplicar duas vezes tem que dar o mesmo resultado.
     *
     * @param array<string, mixed> $menu
     * @return array<string, mixed>
     */
    public static function redefineMenus(array $menu): array
    {
        $own_key = strtolower(self::class);

        if (!Settings::isTrue('override_native_planning')) {
            return $menu;
        }

        // Sem o direito de usar o Planner, o usuário fica com o planejamento
        // nativo — substituir levaria a uma tela que ele não pode abrir.
        if (!Right::canUse()) {
            return $menu;
        }

        if (!isset($menu['helpdesk']['content'][self::NATIVE_KEY])) {
            // Item nativo ausente (usuário sem o direito `planning` do core).
            // Nesse caso a entrada própria do plugin é o único acesso, então
            // ela é mantida.
            return $menu;
        }

        $native = $menu['helpdesk']['content'][self::NATIVE_KEY];

        // Mantém rótulo, ícone e atalho do core: para quem usa, o item é o
        // mesmo "Planejamento" de sempre. Só o destino muda.
        $menu['helpdesk']['content'][self::NATIVE_KEY] = array_merge($native, [
            'page'    => self::getPlannerPage(),
            // As opções nativas (Eventos externos) continuam valendo — são
            // telas do core que o plugin não substitui. As do plugin entram
            // depois delas.
            'options' => array_merge($native['options'] ?? [], self::getOptions()),
        ]);

        // Evita dois itens parecidos no mesmo setor.
        unset($menu['helpdesk']['content'][$own_key]);

        return $menu;
    }

    /**
     * Submenu do plugin, compartilhado pelos dois modos.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function getOptions(): array
    {
        $options = [];

        $options['planner_agenda'] = [
            'title' => __('Schedule', 'planner'),
            'page'  => self::getPlannerPage(),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getPlannerPage(),
            ],
        ];

        $options['planner_share'] = [
            'title' => Share::getTypeName(2),
            'page'  => '/plugins/planner/front/share.php',
            'icon'  => Share::getIcon(),
            'links' => [
                'search' => '/plugins/planner/front/share.php',
            ],
        ];

        if (Right::has(Right::READ_ALL)) {
            $options['planner_config'] = [
                'title' => __('Configuration', 'planner'),
                'page'  => '/plugins/planner/front/config.form.php',
                'icon'  => 'ti ti-settings',
                'links' => [
                    'search' => '/plugins/planner/front/config.form.php',
                ],
            ];
        }

        return $options;
    }

    public static function getPlannerPage(): string
    {
        return '/plugins/planner/front/planner.php';
    }

    /**
     * Rótulo da tela. Com a substituição ligada usa o nome do core
     * ("Planejamento"), para a tela combinar com o item de menu que a abriu.
     */
    public static function getScreenTitle(): string
    {
        return Settings::isTrue('override_native_planning')
            ? Planning::getTypeName(1)
            : self::getTypeName();
    }
}
