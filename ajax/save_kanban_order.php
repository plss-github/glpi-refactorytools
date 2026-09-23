<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Grava a ordem das colunas de SITUAÇÃO do Kanban de Planejamento, DE QUEM
 * ESTÁ LOGADO — sem parâmetro `users_id` na requisição, ninguém reordena a
 * tela de outra pessoa.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\KanbanPrefs;
use GlpiPlugin\Planner\Right;

Session::checkRight(Right::NAME, Right::USE_PLANNER);

header('Content-Type: application/json; charset=UTF-8');

$states = $_POST['states'] ?? [];
if (!is_array($states)) {
    $states = [$states];
}

$ok = KanbanPrefs::saveStateOrder((int) Session::getLoginUserID(), $states);

echo json_encode(['ok' => $ok]);
