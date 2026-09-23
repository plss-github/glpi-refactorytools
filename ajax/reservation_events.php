<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Reservas dos itens pedidos, em JSON, para a tela de Reservas.
 *
 * Endpoint separado do de agendas (`ajax/events.php`) porque o eixo é outro:
 * lá o recorte é por PESSOA e passa por `AccessPolicy`; aqui é por ITEM
 * RESERVÁVEL, e a autorização é a do core — o direito `reservation` mais o
 * recorte por entidade do item. Misturar os dois no mesmo arquivo obrigaria
 * cada leitura futura a descobrir qual dos dois modelos de permissão está em
 * vigor naquela linha.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Refactorytools\ReservationEventProvider;
use GlpiPlugin\Refactorytools\ReservationView;

if (!ReservationView::canView()) {
    Session::redirectIfNotLoggedIn();
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

$begin = $parse_date($_GET['start'] ?? null);
$end   = $parse_date($_GET['end'] ?? null);

if ($begin === null || $end === null || $begin >= $end) {
    echo json_encode(['events' => [], 'stats' => []]);
    return;
}

// A barra lateral marca TIPOS de ativo. Os aparelhos de cada tipo são
// resolvidos no servidor (ver ReservationView::getItemsForTypes()), que
// também é onde o recorte por entidade acontece — um itemtype inventado na
// requisição não casa com nada.
$requested = $_GET['itemtypes'] ?? [];
if (!is_array($requested)) {
    $requested = [$requested];
}
$requested = array_values(array_unique(array_filter(array_map('strval', $requested))));

// "Somente as minhas reservas": filtro da barra lateral, não uma permissão.
$only_mine = ($_GET['only_mine'] ?? '0') === '1';

$events = ReservationEventProvider::getEvents($requested, $begin, $end, $only_mine);

echo json_encode([
    'events' => $events,
    'stats'  => ReservationEventProvider::getStats($events),
    // As faixas acompanham os eventos: a barra lateral não tem como montá-las,
    // porque nela um item é um TIPO e cada faixa é um APARELHO.
    'resources' => ReservationEventProvider::getResources($requested),
]);
