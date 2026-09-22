<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Eventos da agenda, em JSON, para o FullCalendar.
 *
 * Toda a autorização acontece aqui, não no cliente: a lista de agendas que
 * chega na requisição é apenas o RECORTE pedido. `AccessPolicy::filterRequested()`
 * descarta silenciosamente qualquer id que o usuário não possa ver e devolve,
 * para os que sobram, o nível de detalhe permitido. Um usuário que edite a
 * requisição para pedir a agenda de outra pessoa recebe um array vazio, não
 * um erro — não faz sentido confirmar a existência de uma agenda a quem não
 * pode vê-la.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Planner\AccessPolicy;
use GlpiPlugin\Planner\EventProvider;
use GlpiPlugin\Planner\Right;

Session::checkRight(Right::NAME, Right::USE_PLANNER);

header('Content-Type: application/json; charset=UTF-8');

/**
 * As datas vão para consultas do core como string. Normalizar por DateTime
 * garante o formato esperado e rejeita qualquer coisa que não seja data.
 */
$parse_date = static function (mixed $value): ?string {
    if (!is_string($value) || $value === '') {
        return null;
    }
    $date = date_create($value);

    return $date === false ? null : $date->format('Y-m-d H:i:s');
};

$begin = $parse_date($_GET['start'] ?? null);
$end   = $parse_date($_GET['end'] ?? null);

if ($begin === null || $end === null || $begin >= $end) {
    echo json_encode(['events' => [], 'stats' => []]);
    return;
}

$requested = $_GET['users_ids'] ?? [];
if (!is_array($requested)) {
    $requested = [$requested];
}

// `types_defined` distingue "o cliente não filtrou por tipo" de "o cliente
// desmarcou todos os tipos". Sem essa marca os dois casos chegariam aqui como
// um array vazio — jQuery não serializa array vazio — e desmarcar tudo na barra
// lateral devolveria a agenda inteira. É a mesma convenção que o GLPI usa nos
// dropdowns múltiplos (`_<campo>_defined`).
$types = null;

if (($_GET['types_defined'] ?? '') === '1') {
    $raw_types = $_GET['types'] ?? [];
    if (!is_array($raw_types)) {
        $raw_types = [$raw_types];
    }
    $types = array_values(array_filter(array_map('strval', $raw_types)));
}

$include_done = ($_GET['include_done'] ?? '1') !== '0';

$allowed = AccessPolicy::filterRequested($requested);
$events  = EventProvider::getEvents($allowed, $begin, $end, $types, $include_done);

echo json_encode([
    'events' => $events,
    'stats'  => EventProvider::getStats($events),
]);
