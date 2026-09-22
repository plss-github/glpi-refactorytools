<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Cria uma reserva, com duas checagens que o formulário nativo não faz por
 * conta própria:
 *
 *  1. A JUSTIFICATIVA é obrigatória. A empresa vinha reservando pelo Discord,
 *     num chat manual, onde combinar "para quê" fazia parte da conversa; sem
 *     isso aqui, uma reserva anônima ("por que a sala está ocupada?") é
 *     exatamente o problema que essa migração deveria resolver, não repetir.
 *  2. O item pedido é reconferido como LIVRE no intervalo, no servidor. A
 *     lista que o cliente viu (`ajax/reservation_availability.php`) é só uma
 *     conveniência de interface — sem reconferir aqui, duas pessoas com a
 *     tela aberta ao mesmo tempo poderiam ambas ver o item como livre e uma
 *     das duas perderia silenciosamente, sem clareza de que perdeu.
 *
 * Delega a gravação em si para `Reservation::handleAddForm()`, a mesma rotina
 * do núcleo do GLPI usada pelo formulário nativo — inclusive a checagem final
 * de conflito e a expansão de recorrência (`periodicity`). Reescrever isso
 * aqui seria reimplementar, com chance de divergir, uma lógica que o core já
 * resolve.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\ReservationView;

if (!ReservationView::canReserve()) {
    Session::addMessageAfterRedirect(
        htmlescape(__('You are not allowed to create reservations.', 'planner')),
        false,
        ERROR
    );
    Html::back();
}

$comment = trim((string) ($_POST['comment'] ?? ''));

if ($comment === '') {
    Session::addMessageAfterRedirect(
        htmlescape(__('The reservation justification is required.', 'planner')),
        false,
        ERROR
    );
    Html::back();
}

$items = $_POST['items'] ?? [];
if (!is_array($items)) {
    $items = [$items];
}
$items = array_values(array_unique(array_filter(array_map('intval', $items))));

$begin = (string) ($_POST['resa']['begin'] ?? '');
$end   = (string) ($_POST['resa']['end'] ?? '');

if ($items === [] || $begin === '' || $end === '' || $begin >= $end) {
    Session::addMessageAfterRedirect(
        htmlescape(__('Fill in the item and the period before reserving.', 'planner')),
        false,
        ERROR
    );
    Html::back();
}

// Reconfere no servidor: só os itens que ainda estão livres neste intervalo
// seguem para o core. Um item que o cliente pediu mas já não está mais livre
// (reservado por outra pessoa entre a listagem e o envio) é descartado aqui,
// silenciosamente — `Reservation::handleAddForm()` também checa conflito por
// conta própria, então mesmo sem este filtro nada indevido seria gravado;
// isto só adianta a mensagem seria dada de qualquer forma.
$available_ids = array_column(ReservationView::getAvailableItems($begin, $end), 'id');
$items = array_values(array_intersect($items, $available_ids));

if ($items === []) {
    Session::addMessageAfterRedirect(
        htmlescape(__('The selected item is no longer available for this period.', 'planner')),
        false,
        ERROR
    );
    Html::back();
}

$input = [
    'users_id' => Session::getLoginUserID(),
    'items'    => $items,
    'comment'  => $comment,
    'resa'     => ['begin' => $begin, 'end' => $end],
];

// Recorrência: mesmos nomes de campo do formulário nativo
// (`periodicity[type|end|days|subtype]`), repassados sem tradução — é
// `Reservation::computePeriodicities()`, no core, quem sabe o que fazer com
// eles.
if (isset($_POST['periodicity']) && is_array($_POST['periodicity']) && !empty($_POST['periodicity']['type'])) {
    $input['periodicity'] = $_POST['periodicity'];
}

Reservation::handleAddForm($input);

Html::back();
