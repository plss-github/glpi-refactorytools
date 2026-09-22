<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Aba de direitos do plugin em Administração > Perfis.
 *
 * Não é uma entidade de banco — existe só para plugar no sistema de abas do
 * GLPI sobre `Profile`, via
 * `Plugin::registerClass(self::class, ['addtabon' => Profile::class])`.
 * `Profile::getRightsForForm()` (a matriz nativa das abas Ativos/Administração)
 * é uma estrutura cacheada e sem ponto de extensão para plugins; uma aba
 * própria é o caminho usado pelo próprio core.
 *
 * A matriz é montada com a chave `rights` (lista explícita direito => rótulo)
 * em vez de `itemtype`. `Profile::displayRightsChoiceMatrix()` aceita as duas
 * formas, e a explícita evita ter que existir uma CommonDBTM só para carregar
 * `getRights()` — os direitos do Planner não são o CRUD de nenhuma tabela.
 */

namespace GlpiPlugin\Planner;

use CommonGLPI;
use Profile;

class ProfileRights extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Planner', 'planner');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-week';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Profile)) {
            return false;
        }

        $item->displayRightsChoiceMatrix(
            [
                [
                    'rights' => Right::getAll(),
                    'label'  => __('Planning (Planner)', 'planner'),
                    'field'  => Right::NAME,
                ],
            ],
            [
                'title' => self::getTypeName(),
            ]
        );

        echo '<p class="text-muted mt-2 mb-0 small">'
            . htmlescape(__('Someone else schedule can also be granted individually, with the owner approval, under Planner > Shares. That path does not depend on the rights above, except for "Use Planner".',
                'planner'
            ))
            . '</p>';

        return true;
    }
}
