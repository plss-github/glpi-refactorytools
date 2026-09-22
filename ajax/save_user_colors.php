<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Grava a personalização de cores de tipo de compromisso DE QUEM ESTÁ
 * LOGADO — nunca de outro usuário. Não há parâmetro `users_id` na requisição
 * de propósito: usar sempre `Session::getLoginUserID()` é o que torna
 * impossível uma pessoa alterar a cor que aparece na tela de outra.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\Right;
use GlpiPlugin\Planner\UserColors;

Session::checkRight(Right::NAME, Right::USE_PLANNER);

$colors = $_POST['colors'] ?? [];
if (is_array($colors)) {
    UserColors::saveForUser((int) Session::getLoginUserID(), $colors);
}

Session::addMessageAfterRedirect(htmlescape(__('Your colours were saved.', 'planner')), false, INFO);

Html::back();
