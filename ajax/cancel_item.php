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
 * A permissão de verdade não é do plugin — é `$item->can($id, PURGE)`, a
 * mesma checagem que o formulário nativo de cada itemtype já faz. O direito
 * do plugin (`Right::USE_REFACTORYTOOLS`) só garante que quem chama está
 * logado e tem acesso à tela; ele nunca amplia quem pode apagar o quê.
 *
 * ATENÇÃO ao usar `PURGE`, não `DELETE`: nenhum dos três itemtypes abaixo
 * sobrescreve `canDeleteItem()` — só `canPurgeItem()`, que é quem de fato
 * confere dono/direito (`Reminder::canPurgeItem()`,
 * `PlanningExternalEvent::canPurgeItem()`, e `Reservation::canPurgeItem()`
 * via `canChildItem()`). O padrão de `CommonDBTM::canDeleteItem()` só
 * confere a ENTIDADE — sem checar dono nenhum — então checar `DELETE` aqui
 * deixaria qualquer pessoa com o direito básico de criar cancelar o
 * compromisso/reserva de QUALQUER outra pessoa. Nenhum dos três tem lixeira
 * (`is_deleted`), então "cancelar" já É "apagar de vez" mesmo — é o mesmo
 * `PURGE` que o formulário nativo usa para excluir estes itemtypes.
 */

use GlpiPlugin\Refactorytools\Right;
use GlpiPlugin\Refactorytools\Settings;

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

    if ($item->getFromDB($items_id)) {
        // "Editar/cancelar qualquer reserva" (ver Settings) só vale para
        // Reserva, e só afeta ESTE atalho do plugin — nunca sobrescreve a
        // permissão nativa em si. Um usuário sem direito nenhum de reserva
        // continua sem poder nada aqui.
        $bypass_owner = $itemtype === 'Reservation' && Settings::canEditAnyReservation();

        if ($bypass_owner ? Reservation::canDelete() : $item->can($items_id, PURGE)) {
            $ok = (bool) $item->delete(['id' => $items_id]);
        }
    }
}

echo json_encode(['ok' => $ok]);
