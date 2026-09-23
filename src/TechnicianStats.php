<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Painel "Chamados como técnico", na barra lateral da agenda.
 *
 * Diferente do resto do plugin, aqui não há questão de PERMISSÃO — é sempre
 * a própria participação de quem está logado (`Ticket_User.type = ASSIGN`),
 * o mesmo recorte que "Meus chamados" na Central já mostra. Não precisa de
 * `AccessPolicy`: não é a agenda de outra pessoa.
 *
 * Duas contagens de tempo, somadas das tarefas (`TicketTask`) que ESTE
 * técnico registrou nos chamados em que está designado, dentro de um
 * intervalo:
 *   planejado   janela `begin`/`end` da tarefa (quando ela tem hora marcada).
 *   realizado   `actiontime`, o campo que o core já usa para "tempo passado".
 * "Total" é a soma das duas — não é uma terceira medição independente.
 *
 * O intervalo acompanha o período visível no calendário (Dia/Semana/Mês) —
 * `ajax/events.php` chama `getPanelForUser()` com o MESMO `begin`/`end` que
 * usa para buscar os eventos daquela view, então trocar de período também
 * atualiza este painel (ver `GlpiRefactoryTools.fetch()` em refactorytools.js). Sem
 * intervalo (o render inicial da página, em Twig), o padrão é o mês
 * corrente.
 *
 * Só os totais: nenhuma lista de chamados individual (essa lista já existe
 * na Central, via o link de `search_url`).
 */

namespace GlpiPlugin\Refactorytools;

use CommonITILActor;
use Ticket;
use TicketTask;
use Ticket_User;
use Toolbox;

final class TechnicianStats
{
    /**
     * @return array{
     *     planned_hours: float,
     *     realized_hours: float,
     *     total_hours: float,
     *     search_url: string
     * }
     */
    public static function getPanelForUser(int $users_id, ?string $begin = null, ?string $end = null): array
    {
        $empty = [
            'planned_hours'  => 0.0,
            'realized_hours' => 0.0,
            'total_hours'    => 0.0,
            'search_url'     => self::getAssignedSearchUrl(),
        ];

        if ($users_id <= 0) {
            return $empty;
        }

        $ticket_ids = self::getAssignedTicketIds($users_id);
        if ($ticket_ids === []) {
            return $empty;
        }

        $begin ??= date('Y-m-01 00:00:00');
        $end   ??= date('Y-m-t 23:59:59');

        [$planned_seconds, $realized_seconds] = self::getTaskDurations($ticket_ids, $users_id, $begin, $end);

        return [
            'planned_hours'  => round($planned_seconds / 3600, 1),
            'realized_hours' => round($realized_seconds / 3600, 1),
            'total_hours'    => round(($planned_seconds + $realized_seconds) / 3600, 1),
            'search_url'     => self::getAssignedSearchUrl(),
        ];
    }

    /**
     * Link para a busca NATIVA de chamados, já filtrada por "Técnico
     * designado = eu" — o campo 5 (`users_id_assign`) é o mesmo que o
     * próprio core usa para montar o link "Meus chamados em andamento" da
     * Central (conferido lendo `Ticket::getDefaultSearchRequest()`), e o
     * valor especial `'myself'` deixa o link correto para QUALQUER pessoa
     * que o abrir, sem cravar um id de usuário na URL.
     */
    private static function getAssignedSearchUrl(): string
    {
        $params = [
            'criteria' => [
                [
                    'field'      => 5,
                    'searchtype' => 'equals',
                    'value'      => 'myself',
                    'link'       => 'AND',
                ],
            ],
        ];

        return Ticket::getSearchURL() . '?' . Toolbox::append_params($params);
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
     * Só as tarefas dentro de [$begin, $end] (pelo campo `date` da tarefa, o
     * mesmo que o core usa para os relatórios de tempo passado).
     *
     * @param array<int, int> $ticket_ids
     * @return array{0: int, 1: int} [planejado total, realizado total]
     */
    private static function getTaskDurations(array $ticket_ids, int $users_id, string $begin, string $end): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $planned_total  = 0;
        $realized_total = 0;

        foreach ($DB->request([
            'SELECT' => ['begin', 'end', 'actiontime'],
            'FROM'   => TicketTask::getTable(),
            'WHERE'  => [
                'tickets_id'    => $ticket_ids,
                'users_id_tech' => $users_id,
                ['date' => ['>=', $begin]],
                ['date' => ['<=', $end]],
            ],
        ]) as $row) {
            $realized_total += (int) $row['actiontime'];

            if (!empty($row['begin']) && !empty($row['end'])) {
                $planned_total += max(0, strtotime((string) $row['end']) - strtotime((string) $row['begin']));
            }
        }

        return [$planned_total, $realized_total];
    }
}
