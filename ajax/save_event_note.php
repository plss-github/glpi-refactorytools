<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Grava (ou apaga, se vazia) a nota de gestor de UM compromisso do calendário
 * — qualquer itemtype, é por isso que existe um endpoint próprio em vez de
 * reaproveitar o formulário nativo de cada um.
 *
 * A permissão não vem do itemtype do compromisso, vem da RELAÇÃO entre quem
 * grava e o dono da agenda onde ele aparece (`AccessPolicy::canManageNoteFor()`
 * — responsável direto, gerente do grupo dele, ou acesso administrativo). O
 * `items_id` só precisa existir de verdade num itemtype de planejamento
 * conhecido; não se reconfere aqui se aquele item específico é mesmo do
 * `users_id_owner` informado — o mesmo grau de confiança que o restante do
 * plugin deposita em quem já tem uma posição de liderança reconhecida sobre
 * a pessoa.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\AccessPolicy;
use GlpiPlugin\Planner\EventNotes;
use GlpiPlugin\Planner\Right;

Session::checkRight(Right::NAME, Right::USE_PLANNER);

header('Content-Type: application/json; charset=UTF-8');

/** Itemtypes reais que este endpoint aceita — os mesmos que alimentam a agenda. */
const ALLOWED_NOTE_ITEMTYPES = [
    'TicketTask', 'ChangeTask', 'ProblemTask', 'ProjectTask',
    'Reminder', 'PlanningExternalEvent', 'Reservation',
];

$itemtype       = (string) ($_POST['itemtype'] ?? '');
$items_id       = (int) ($_POST['items_id'] ?? 0);
$users_id_owner = (int) ($_POST['users_id'] ?? 0);
$note           = (string) ($_POST['note'] ?? '');

$ok = false;

if (
    in_array($itemtype, ALLOWED_NOTE_ITEMTYPES, true)
    && $items_id > 0
    && AccessPolicy::canManageNoteFor($users_id_owner)
) {
    $item = getItemForItemtype($itemtype);

    if ($item !== false && $item->getFromDB($items_id)) {
        $ok = EventNotes::save(
            $itemtype,
            $items_id,
            $users_id_owner,
            (int) Session::getLoginUserID(),
            $note
        );
    }
}

echo json_encode([
    'ok'   => $ok,
    'note' => $ok ? trim($note) : null,
]);
