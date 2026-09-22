<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Painel "Chamados como técnico", na barra lateral da agenda.
 *
 * Diferente do resto do plugin, aqui não há questão de PERMISSÃO — é sempre
 * a própria participação de quem está logado (`Ticket_User.type = ASSIGN`),
 * o mesmo recorte que "Meus chamados" na Central já mostra. Não precisa de
 * `AccessPolicy`: não é a agenda de outra pessoa.
 *
 * Duas contagens de tempo por chamado, somadas das tarefas (`TicketTask`)
 * que ESTE técnico registrou nele:
 *   planejado   janela `begin`/`end` da tarefa (quando ela tem hora marcada).
 *   realizado   `actiontime`, o campo que o core já usa para "tempo passado".
 * "Total" é a soma das duas — não é uma terceira medição independente.
 */

namespace GlpiPlugin\Planner;

use CommonITILActor;
use Ticket;
use TicketTask;
use Ticket_User;

final class TechnicianStats
{
    /**
     * Teto de chamados listados: o painel é um resumo na barra lateral, não
     * uma segunda tela de "Meus chamados" — sem limite, uma carreira inteira
     * de chamados fechados apareceria numa lista que ninguém rola até o fim.
     */
    private const TICKET_LIMIT = 25;

    /**
     * @return array{
     *     tickets: array<int, array{id: int, name: string, status_label: string, url: string, duration_label: string}>,
     *     planned_hours: float,
     *     realized_hours: float,
     *     total_hours: float
     * }
     */
    public static function getPanelForUser(int $users_id): array
    {
        $empty = ['tickets' => [], 'planned_hours' => 0.0, 'realized_hours' => 0.0, 'total_hours' => 0.0];

        if ($users_id <= 0) {
            return $empty;
        }

        $ticket_ids = self::getAssignedTicketIds($users_id);
        if ($ticket_ids === []) {
            return $empty;
        }

        [$planned_seconds, $realized_seconds, $seconds_by_ticket] = self::getTaskDurations($ticket_ids, $users_id);

        $tickets = [];
        foreach (self::getTicketRows($ticket_ids) as $row) {
            $id   = (int) $row['id'];
            $name = (string) $row['name'];

            $tickets[] = [
                'id'             => $id,
                'name'           => $name !== '' ? $name : sprintf(__('Ticket #%d', 'planner'), $id),
                'status_label'   => Ticket::getStatus((int) $row['status']),
                'url'            => Ticket::getFormURLWithID($id),
                'duration_label' => self::formatDuration($seconds_by_ticket[$id] ?? 0),
            ];
        }

        return [
            'tickets'        => $tickets,
            'planned_hours'  => round($planned_seconds / 3600, 1),
            'realized_hours' => round($realized_seconds / 3600, 1),
            'total_hours'    => round(($planned_seconds + $realized_seconds) / 3600, 1),
        ];
    }

    /** @return array<int, int> */
    private static function getAssignedTicketIds(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT'   => 'tickets_id',
            'DISTINCT' => true,
            'FROM'     => Ticket_User::getTable(),
            'WHERE'    => [
                'users_id' => $users_id,
                'type'     => CommonITILActor::ASSIGN,
            ],
        ]) as $row) {
            $ids[] = (int) $row['tickets_id'];
        }

        return $ids;
    }

    /**
     * @param array<int, int> $ticket_ids
     * @return array<int, array<string, mixed>>
     */
    private static function getTicketRows(array $ticket_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        return iterator_to_array($DB->request([
            'SELECT' => ['id', 'name', 'status'],
            'FROM'   => Ticket::getTable(),
            'WHERE'  => [
                'id'         => $ticket_ids,
                'is_deleted' => 0,
            ],
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => self::TICKET_LIMIT,
        ]));
    }

    /**
     * Uma consulta só nas tarefas de TODOS os chamados da lista, filtrada
     * pelo próprio técnico — não pelas tarefas do chamado inteiro, que podem
     * ter sido registradas por outros técnicos também designados nele.
     *
     * @param array<int, int> $ticket_ids
     * @return array{0: int, 1: int, 2: array<int, int>} [planejado total, realizado total, segundos por chamado]
     */
    private static function getTaskDurations(array $ticket_ids, int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $planned_total  = 0;
        $realized_total = 0;
        $by_ticket      = [];

        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'begin', 'end', 'actiontime'],
            'FROM'   => TicketTask::getTable(),
            'WHERE'  => [
                'tickets_id'    => $ticket_ids,
                'users_id_tech' => $users_id,
            ],
        ]) as $row) {
            $tickets_id = (int) $row['tickets_id'];
            $realized   = (int) $row['actiontime'];

            $planned = 0;
            if (!empty($row['begin']) && !empty($row['end'])) {
                $planned = max(0, strtotime((string) $row['end']) - strtotime((string) $row['begin']));
            }

            $planned_total  += $planned;
            $realized_total += $realized;

            $by_ticket[$tickets_id] = ($by_ticket[$tickets_id] ?? 0) + $planned + $realized;
        }

        return [$planned_total, $realized_total, $by_ticket];
    }

    private static function formatDuration(int $seconds): string
    {
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf(__('%1$dh%2$02dmin', 'planner'), $hours, $minutes);
    }
}
