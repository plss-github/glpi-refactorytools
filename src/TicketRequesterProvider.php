<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Chamados em que a pessoa é REQUERENTE, como compromissos da agenda.
 *
 * `Ticket` não implementa `populatePlanning()` — o planejamento nativo só
 * conhece TAREFAS (`TicketTask`, atribuídas a um técnico), nunca o chamado
 * inteiro. Um requerente puro, sem tarefa nenhuma em seu nome, nunca tinha o
 * que mostrar aqui, mesmo depois de o chamado ser resolvido.
 *
 * A âncora de data é o que muda de figura para figura:
 *   - resolvido/fechado: `begin` = abertura, `end` = solução (ou fechamento,
 *     se não houver data de solução) — o evento é uma barra mostrando quanto
 *     tempo o chamado ficou aberto, terminando no dia em que foi resolvido.
 *     É a resposta a "isso não apareceu depois de finalizado".
 *   - ainda aberto: `begin` = abertura, `end` = abertura + 2 horas — só um
 *     marcador visível de "eu abri isso", sem crescer a cada dia.
 *
 * Confiança de visibilidade: diferente de `EventProvider` (que reaproveita
 * `populatePlanning()` do core e herda o filtro `canViewItem()` de graça),
 * aqui a consulta já é por `Ticket_User.type = REQUESTER` — um requerente
 * sempre pode ver o próprio chamado no modelo de permissão do GLPI, então não
 * há necessidade de reconferir `canViewItem()` linha a linha.
 */

namespace GlpiPlugin\Planner;

use CommonITILActor;
use Planning;
use Ticket;
use Ticket_User;

final class TicketRequesterProvider
{
    public static function canView(): bool
    {
        return Ticket::canView();
    }

    /**
     * @param array<string, mixed> $options who, begin, end, color, event_type_color
     * @return array<string, array<string, mixed>>
     */
    public static function populatePlanning(array $options = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!self::canView()) {
            return [];
        }

        $who   = (int) ($options['who'] ?? 0);
        $begin = (string) ($options['begin'] ?? '');
        $end   = (string) ($options['end'] ?? '');

        if ($who <= 0 || $begin === '' || $end === '') {
            return [];
        }

        $ticket_table = Ticket::getTable();
        $tu_table     = Ticket_User::getTable();

        // A "duração" do chamado é abertura -> solução/fechamento (ou
        // abertura -> agora, se ainda aberto). `glpi_tickets` não tem
        // `begin`/`end`, então a sobreposição com a janela pedida é
        // calculada linha a linha em PHP, depois da consulta, em vez de no
        // WHERE — são poucas linhas por pessoa (os chamados que ela abriu),
        // não vale complicar a consulta por isso.
        $rows = $DB->request([
            'SELECT' => [
                $ticket_table . '.id',
                $ticket_table . '.name',
                $ticket_table . '.date',
                $ticket_table . '.solvedate',
                $ticket_table . '.closedate',
                $ticket_table . '.status',
                $ticket_table . '.priority',
            ],
            'FROM'       => $ticket_table,
            'INNER JOIN' => [
                $tu_table => [
                    'ON' => [
                        $tu_table     => 'tickets_id',
                        $ticket_table => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                $tu_table . '.users_id' => $who,
                $tu_table . '.type'     => CommonITILActor::REQUESTER,
                $ticket_table . '.is_deleted' => 0,
            ],
            'ORDER' => $ticket_table . '.date',
        ]);

        $events = [];

        foreach ($rows as $row) {
            $ticket_begin = (string) $row['date'];
            if ($ticket_begin === '') {
                continue;
            }

            $solved = (string) ($row['solvedate'] ?? '');
            $closed = (string) ($row['closedate'] ?? '');
            $is_done = $solved !== '' || $closed !== '';

            $ticket_end = $is_done
                ? ($solved !== '' ? $solved : $closed)
                : date('Y-m-d H:i:s', strtotime($ticket_begin) + 2 * 3600);

            // `$ticket_end` pode ser anterior a `$ticket_begin` em dados
            // inconsistentes (fechamento manual com data retroativa) — sem
            // isto o FullCalendar recebe um evento de duração negativa.
            if ($ticket_end < $ticket_begin) {
                $ticket_end = $ticket_begin;
            }

            // Sobreposição com a janela pedida.
            if ($ticket_begin > $end || $ticket_end < $begin) {
                continue;
            }

            $events[$ticket_table . '$$' . $row['id']] = [
                'state'            => $is_done ? Planning::DONE : Planning::TODO,
                'itemtype'         => 'Ticket',
                'id'               => (int) $row['id'],
                'name'             => (string) $row['name'],
                'content'          => '',
                'begin'            => $ticket_begin,
                'end'              => $ticket_end,
                'url'              => Ticket::getFormURLWithID((int) $row['id']),
                'users_id'         => $who,
                'priority'         => (int) $row['priority'],
                'color'            => $options['color'] ?? '',
                'event_type_color' => $options['event_type_color'] ?? '',
                // Sem arrastar: mudar as datas aqui não significa nada — não
                // há campo `begin`/`end` de chamado para gravar, só datas de
                // ciclo de vida que o core já controla (abertura, solução).
                'editable'         => false,
            ];
        }

        return $events;
    }
}
