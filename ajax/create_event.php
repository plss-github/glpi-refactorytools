<?php

/**
 * Planner
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

include('../../../inc/includes.php');

use GlpiPlugin\Planner\EventTypes;
use GlpiPlugin\Planner\Right;
use GlpiPlugin\Planner\Settings;

Session::checkRight(Right::NAME, Right::USE_PLANNER);

$kind = (string) ($_POST['kind'] ?? '');

if (!in_array($kind, EventTypes::CREATABLE, true)) {
    Session::addMessageAfterRedirect(htmlescape(__('Unknown event type.', 'planner')), false, ERROR);
    Html::back();
}

$name  = trim((string) ($_POST['name'] ?? ''));
$text  = (string) ($_POST['text'] ?? '');
$begin = (string) ($_POST['begin'] ?? '');
$end   = (string) ($_POST['end'] ?? '');

if ($begin === '' || $end === '' || $begin >= $end) {
    Session::addMessageAfterRedirect(
        htmlescape(__('Error in entering dates. The starting date is later than the ending date', 'planner')),
        false,
        ERROR
    );
    Html::back();
}

$itemtype = EventTypes::realItemtype($kind);
$item     = new $itemtype();

if (!$item::canCreate()) {
    Session::addMessageAfterRedirect(
        htmlescape(__('You are not allowed to create this type of event.', 'planner')),
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

$new_id = $item->add($input);

if ($new_id) {
    Session::addMessageAfterRedirect(
        htmlescape(sprintf(__('"%s" was added to the schedule.', 'planner'), $name !== '' ? $name : __('Without title', 'planner'))),
        false,
        INFO
    );
} else {
    Session::addMessageAfterRedirect(htmlescape(__('Could not create the event.', 'planner')), false, ERROR);
}

Html::back();
