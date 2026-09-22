<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Compartilhamentos de agenda: conceder, pedir, aceitar, recusar, revogar.
 *
 * Tela montada à mão em vez de `Search::show(Share::class)`: a lista nativa
 * mostraria todas as linhas às quais o direito dá acesso, e aqui o recorte
 * correto é "as minhas" — as duas pontas de um acordo do usuário logado.
 * Além disso o que importa nesta tela são as ações (aceitar/recusar), que a
 * lista de busca não oferece.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\Menu;
use GlpiPlugin\Planner\Right;
use GlpiPlugin\Planner\Share;
use GlpiPlugin\Planner\View;

Session::checkRight(Right::NAME, Right::USE_PLANNER);

Html::header(
    Share::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    Menu::getMenuItemKey(),
    'planner_share'
);

View::showShares();

Html::footer();
