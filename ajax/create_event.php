<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Cria um novo compromisso na agenda ("+ Novo compromisso").
 *
 * Endpoint PRÓPRIO em vez de postar para `front/planningexternalevent.form.php`
 * ou `front/reminder.form.php` do core: o primeiro tem
 * `Session::checkRight("planning", READ)` incondicional no topo do arquivo,
 * antes de qualquer ação — um usuário que só tenha o direito DESTE plugin
 * (sem o direito nativo `planning`) seria barrado ali mesmo para criar um
 * evento que ele tem todo o direito itemtype-a-itemtype de criar. Aqui a
 * checagem é a do itemtype (`canCreate()`), que é a mesma que vale no
 * planejamento nativo — só o gate extra e redundante é removido.
 *
 * Cobre exatamente os tipos que o planejamento nativo também deixa criar
 * "do nada", sem um chamado/mudança/problema/projeto já aberto por trás:
 * Lembrete e as quatro variantes de Evento (Externo/Interno/Viagem/Reunião).
 * Ver `EventTypes::CREATABLE`.
 */

use GlpiPlugin\Refactorytools\EventTypes;
use GlpiPlugin\Refactorytools\MeetingGuest;
use GlpiPlugin\Refactorytools\ReservationView;
use GlpiPlugin\Refactorytools\Right;
use GlpiPlugin\Refactorytools\Settings;
use GlpiPlugin\Refactorytools\VisitLink;

Session::checkRight(Right::NAME, Right::USE_REFACTORYTOOLS);

$kind = (string) ($_POST['kind'] ?? '');

if (!in_array($kind, EventTypes::CREATABLE, true)) {
    Session::addMessageAfterRedirect(htmlescape(__('Unknown event type.', 'refactorytools')), false, ERROR);
    Html::back();
}

$name  = trim((string) ($_POST['name'] ?? ''));
$text  = trim((string) ($_POST['text'] ?? ''));
$begin = (string) ($_POST['begin'] ?? '');
$end   = (string) ($_POST['end'] ?? '');

if ($begin === '' || $end === '' || $begin >= $end) {
    Session::addMessageAfterRedirect(
        htmlescape(__('Error in entering dates. The starting date is later than the ending date', 'refactorytools')),
        false,
        ERROR
    );
    Html::back();
}

// `required` no HTML não impede um POST direto sem o campo — a validação
// que decide de verdade é sempre a do servidor.
if ($text === '') {
    Session::addMessageAfterRedirect(htmlescape(__('Description is required.', 'refactorytools')), false, ERROR);
    Html::back();
}

$itemtype = EventTypes::realItemtype($kind);
$item     = new $itemtype();

if (!$item::canCreate()) {
    Session::addMessageAfterRedirect(
        htmlescape(__('You are not allowed to create this type of event.', 'refactorytools')),
        false,
        ERROR
    );
    Html::back();
}

$input = [
    'name' => $name,
    'text' => $text,
    'plan' => ['begin' => $begin, 'end' => $end],
];

if ($itemtype === PlanningExternalEvent::class) {
    // Categoria: é o que diferencia Evento Interno/Viagem/Reunião do Evento
    // Externo genérico (ver EventTypes). Sem categoria semeada (instalação
    // antiga, categoria apagada à mão), o evento é gravado como Externo —
    // continua existindo e aparecendo na agenda, só perde a etiqueta fina.
    $category_id = Settings::getCategoryId($kind);
    if ($category_id > 0) {
        $input['planningeventcategories_id'] = $category_id;
    }

    // Recorrência: os campos `rrule[freq|interval|until|byday|bymonth]` vêm
    // prontos do widget nativo `PlanningExternalEvent::showRepetitionForm()`,
    // embutido no formulário. `prepareInputForAdd()` do próprio core
    // converte esse array para o JSON gravado na coluna — não há nada para
    // este endpoint fazer além de repassar.
    if (isset($_POST['rrule']) && is_array($_POST['rrule']) && !empty($_POST['rrule']['freq'])) {
        $input['rrule'] = $_POST['rrule'];
    }
}

// Convidados: Reunião/Viagem/Visita (`EventTypes::SHAREABLE`).
// `users_id_guests` é um campo NATIVO de `PlanningExternalEvent` — o core já
// espelha o compromisso na agenda de cada convidado sozinho (ver
// `Glpi\Features\PlanningEvent::populatePlanning()`, que inclui o convidado
// no filtro `who`). O que o plugin acrescenta é só o controle de
// obrigatório/opcional e a resposta de cada um — ver `MeetingGuest`.
$guests_mandatory = [];
$guests_optional  = [];
if (in_array($kind, EventTypes::SHAREABLE, true)) {
    $guests_mandatory = array_map('intval', (array) ($_POST['guests_mandatory'] ?? []));
    $guests_optional  = array_map('intval', (array) ($_POST['guests_optional'] ?? []));
    $all_guests       = array_values(array_unique(array_filter(
        array_merge($guests_mandatory, $guests_optional),
        static fn($id) => $id > 0
    )));

    if ($all_guests !== []) {
        $input['users_id_guests'] = $all_guests;
    }
}

$new_id = $item->add($input);

if ($new_id && in_array($kind, EventTypes::SHAREABLE, true)) {
    MeetingGuest::setGuests((int) $new_id, $guests_mandatory, $guests_optional);
}

// Visita/Viagem: liga o compromisso recém-criado a uma reserva — já
// existente (escolhida no select), OU nova (criada aqui mesmo, no mesmo
// envio). As duas são opcionais e mutuamente exclusivas: um item novo
// escolhido vale sobre uma reserva existente marcada por engano.
if ($new_id && in_array($kind, EventTypes::LINKABLE_WITH_RESERVATION, true)) {
    $new_reservation_item_id = (int) ($_POST['new_reservation_item_id'] ?? 0);

    if ($new_reservation_item_id > 0) {
        // Reconfere no servidor que o item ainda está livre — a mesma
        // checagem que `ajax/create_reservation.php` já faz, aqui só para
        // UM item (o par de selects deste modal não permite escolher mais
        // de um).
        $available_ids = array_column(ReservationView::getAvailableItems($begin, $end), 'id');
        if (in_array($new_reservation_item_id, $available_ids, true)) {
            $reservation = new Reservation();
            $reservations_id = $reservation->add([
                'begin'               => $begin,
                'end'                 => $end,
                'reservationitems_id' => $new_reservation_item_id,
                'comment'             => $text,
                'users_id'            => (int) Session::getLoginUserID(),
            ]);

            if ($reservations_id) {
                VisitLink::link((int) $new_id, (int) $reservations_id);
            }
        }
    } else {
        $reservations_id = (int) ($_POST['reservations_id'] ?? 0);
        if ($reservations_id > 0) {
            VisitLink::link((int) $new_id, $reservations_id);
        }
    }
}

if ($new_id) {
    Session::addMessageAfterRedirect(
        htmlescape(sprintf(__('"%s" was added to the schedule.', 'refactorytools'), $name !== '' ? $name : __('Without title', 'refactorytools'))),
        false,
        INFO
    );
} else {
    Session::addMessageAfterRedirect(htmlescape(__('Could not create the event.', 'refactorytools')), false, ERROR);
}

Html::back();
