<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Muda a situação (A fazer / Concluído) de UM compromisso — o que o arrastar
 * entre colunas do Kanban precisa gravar.
 *
 * Só faz sentido quando o Kanban está agrupado por SITUAÇÃO. Agrupado por
 * pessoa, arrastar um cartão significaria reatribuir o compromisso a outra
 * pessoa — um gesto mais parecido com o de arrastar entre faixas no
 * calendário (que já existe e passa pelas validações do core via
 * `update_event_times`) do que com uma simples troca de estado. Por ora o
 * Kanban só é arrastável na visão por situação; o JavaScript não desenha o
 * atributo `draggable` nos cartões quando agrupado por pessoa.
 *
 * Endpoint próprio pelo mesmo motivo do `create_event.php`: os formulários
 * nativos dos itemtypes envolvidos (`front/planningexternalevent.form.php`)
 * têm um gate de direito NATIVO incondicional que um usuário só com o direito
 * do plugin não tem. Reservas ficam de fora: não têm campo de situação
 * gravado (é calculado do relógio, ver `ReservationProvider`), então uma
 * reserva simplesmente não aparece nesta variante do Kanban.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Refactorytools\Right;

Session::checkRight(Right::NAME, Right::USE_REFACTORYTOOLS);

header('Content-Type: application/json; charset=UTF-8');

/** Itemtypes cujo estado pode ser trocado por este endpoint. */
const ALLOWED_STATE_ITEMTYPES = [
    'TicketTask', 'ChangeTask', 'ProblemTask', 'ProjectTask',
    'Reminder', 'PlanningExternalEvent',
];

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);
$state    = (int) ($_POST['state'] ?? -1);

$ok = false;

if (
    in_array($itemtype, ALLOWED_STATE_ITEMTYPES, true)
    && $items_id > 0
    && in_array($state, [Planning::INFO, Planning::TODO, Planning::DONE], true)
) {
    $item = getItemForItemtype($itemtype);

    // `getItemForItemtype()` só devolve `false` para um nome de classe que
    // não existe — impossível aqui, já que `$itemtype` veio da lista fixa
    // acima, mas a checagem custa nada e documenta a garantia.
    if ($item !== false && $item->getFromDB($items_id) && $item->canUpdateItem()) {
        $update = ['id' => $items_id, 'state' => $state];

        // Tarefa de ITIL (Chamado/Mudança/Problema/Projeto): o core espera a
        // chave estrangeira do item pai no input — é o mesmo campo extra que
        // o arrastar no CALENDÁRIO já precisa mandar (ver
        // `Planning::updateEventTimes()` no core, que este plugin não chama
        // aqui mas cujo padrão de input replica).
        if (is_subclass_of($item, 'CommonITILTask')) {
            $parent = getItemForItemtype($item::getItilObjectItemType());
            if ($parent !== false) {
                $fk_field = $parent->getForeignKeyField();
                $update[$fk_field] = $item->fields[$fk_field];
            }
        }

        $ok = (bool) $item->update($update);
    }
}

echo json_encode(['ok' => $ok]);
