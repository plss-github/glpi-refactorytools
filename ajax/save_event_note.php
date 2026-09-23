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
 * — responsável direto, gerente do grupo dele, ou acesso administrativo).
 *
 * O dono é sempre lido DO ITEM já carregado do banco, nunca do `users_id` que
 * o cliente manda — um `users_id` só de confiança abriria uma brecha real:
 * qualquer gestor de UM subordinado poderia anexar nota em QUALQUER
 * item do sistema (um chamado de outra equipe, uma reserva de outra pessoa),
 * bastando alegar no POST que o dono é o próprio subordinado dele. Lendo do
 * item, a nota só se grava contra quem de fato é o dono daquele registro.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\AccessPolicy;
use GlpiPlugin\Planner\EventNotes;
use GlpiPlugin\Planner\Right;

Session::checkRight(Right::NAME, Right::USE_PLANNER);

header('Content-Type: application/json; charset=UTF-8');

/**
 * Itemtypes reais que este endpoint aceita, com o campo que guarda o DONO em
 * cada um — é dali, não do POST, que o dono usado na checagem de permissão
 * vem.
 */
const ALLOWED_NOTE_ITEMTYPES = [
    'TicketTask'            => 'users_id_tech',
    'ChangeTask'             => 'users_id_tech',
    'ProblemTask'            => 'users_id_tech',
    'ProjectTask'            => 'users_id_tech',
    'Reminder'               => 'users_id',
    'PlanningExternalEvent'  => 'users_id',
    'Reservation'            => 'users_id',
];

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);
// Presente só ao editar uma nota já existente; ausente (ou 0), o save()
// sempre acrescenta uma nova ao histórico do compromisso.
$note_id  = (int) ($_POST['note_id'] ?? 0);
// Teto de tamanho: é um recado curto, não um campo de descrição — sem isto,
// nada impedia um POST com um valor gigante indo parar na coluna TEXT.
$note = mb_substr((string) ($_POST['note'] ?? ''), 0, 2000);

$ok             = false;
$users_id_owner = 0;

if (array_key_exists($itemtype, ALLOWED_NOTE_ITEMTYPES) && $items_id > 0) {
    $item = getItemForItemtype($itemtype);

    if ($item !== false && $item->getFromDB($items_id)) {
        $owner_field    = ALLOWED_NOTE_ITEMTYPES[$itemtype];
        $users_id_owner = (int) ($item->fields[$owner_field] ?? 0);

        if ($users_id_owner > 0 && AccessPolicy::canManageNoteFor($users_id_owner)) {
            $ok = EventNotes::save(
                $itemtype,
                $items_id,
                $users_id_owner,
                (int) Session::getLoginUserID(),
                $note,
                $note_id
            );
        }
    }
}

echo json_encode([
    'ok'   => $ok,
    'note' => $ok ? trim($note) : null,
]);
