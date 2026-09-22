<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Tipos e itens reserváveis LIVRES num intervalo, para o formulário de nova
 * reserva. É por isso que o formulário pede a data ANTES do tipo e do item:
 * sem data não há como saber o que está disponível, e mostrar tudo (livre ou
 * não) só para descobrir o conflito depois de preencher tudo é o que esta
 * tela existe para evitar.
 *
 * Só uma conveniência de interface — a checagem que realmente vale é repetida
 * no servidor em `ajax/create_reservation.php` no momento de gravar.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\ReservationView;

if (!ReservationView::canReserve()) {
    http_response_code(403);
    return;
}

header('Content-Type: application/json; charset=UTF-8');

$parse_date = static function (mixed $value): ?string {
    if (!is_string($value) || $value === '') {
        return null;
    }
    $date = date_create($value);

    return $date === false ? null : $date->format('Y-m-d H:i:s');
};

$begin = $parse_date($_GET['begin'] ?? null);
$end   = $parse_date($_GET['end'] ?? null);

if ($begin === null || $end === null || $begin >= $end) {
    echo json_encode(['types' => [], 'items' => []]);
    return;
}

echo json_encode([
    'types' => ReservationView::getAvailableTypes($begin, $end),
    'items' => ReservationView::getAvailableItems($begin, $end),
]);
