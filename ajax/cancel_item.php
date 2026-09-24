<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Cancela (envia para a lixeira) UM compromisso — a ação "Cancelar" do
 * popover, ao lado de "Editar".
 *
 * Só os itemtypes que o próprio plugin cria por um formulário seu entram
 * aqui: Lembrete, Evento (as 4 variantes de `PlanningExternalEvent`) e
 * Reserva. Chamado/Mudança/Problema ficam de fora de propósito — uma tarefa
 * desses tipos não tem existência própria fora do chamado/mudança/problema
 * pai (ver `EventTypes::CREATABLE`), cancelá-la aqui seria mexer no chamado
 * de outra pessoa por um atalho que não passa pelas regras do chamado.
 *
 * A permissão de verdade não é do plugin — é `$item->can($id, DELETE)`, a
 * mesma checagem que o formulário nativo de cada itemtype já faz. O direito
 * do plugin (`Right::USE_REFACTORYTOOLS`) só garante que quem chama está
 * logado e tem acesso à tela; ele nunca amplia quem pode apagar o quê.
 */

use GlpiPlugin\Refactorytools\Right;

Session::checkRight(Right::NAME, Right::USE_REFACTORYTOOLS);

header('Content-Type: application/json; charset=UTF-8');

const CANCELABLE_ITEMTYPES = [
    'Reminder',
    'PlanningExternalEvent',
    'Reservation',
];

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);

$ok = false;

if (in_array($itemtype, CANCELABLE_ITEMTYPES, true) && $items_id > 0) {
    $item = new $itemtype();

    if ($item->getFromDB($items_id) && $item->can($items_id, DELETE)) {
        $ok = (bool) $item->delete(['id' => $items_id]);
    }
}

echo json_encode(['ok' => $ok]);
