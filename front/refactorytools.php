<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Tela principal: a agenda remodelada.
 *
 * Padrão clássico front/ + ajax/ em vez de Controller com rotas por atributo.
 * É o único caminho garantidamente estável em qualquer instalação GLPI 11.0.x,
 * e é o que o próprio core ainda usa para o planejamento (front/planning.php).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Refactorytools\Menu;
use GlpiPlugin\Refactorytools\Right;
use GlpiPlugin\Refactorytools\View;

Session::checkRight(Right::NAME, Right::USE_REFACTORYTOOLS);

// O terceiro e quarto argumentos (setor e item) são o que faz Html::header()
// resolver $CFG_GLPI['javascript']['helpdesk'][<menu>] e carregar o
// FullCalendar embarcado — ver o registro em setup.php. Trocar o item aqui
// sem trocar lá deixa a tela sem calendário.
//
// A chave é 'planning' quando a substituição do planejamento nativo está
// ligada, e a entrada própria do plugin quando não está (ver
// Menu::getMenuItemKey()) — é também o que destaca o item certo no menu.
Html::header(
    Menu::getScreenTitle(),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    Menu::getMenuItemKey(),
    'refactorytools_agenda'
);

View::showRefactoryTools();

Html::footer();
