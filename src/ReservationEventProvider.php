<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Reservas no formato do FullCalendar, para a tela de Reservas.
 *
 * Complementa `ReservationProvider`, que resolve a outra direção: lá a pergunta
 * é "o que ESTA PESSOA reservou" (a reserva entra na agenda dela); aqui é
 * "quem reservou ESTE ITEM" (o item é a faixa). São a mesma tabela lida por
 * eixos diferentes, e cada eixo tem sua própria autorização — por isso duas
 * classes em vez de uma com um parâmetro de modo.
 */

namespace GlpiPlugin\Planner;

use Reservation;
use Session;

final class ReservationEventProvider
{
    /**
     * Faixas (uma por aparelho) dos tipos pedidos.
     *
     * Vêm do servidor, e não das caixas da barra lateral, porque a barra
     * lateral lista TIPOS enquanto as faixas são APARELHOS. Mandar as faixas
     * junto com os eventos também faz aparecer o aparelho que não tem nenhuma
     * reserva no período — que é justamente o que está livre.
     *
     * @param array<int, string> $itemtypes
     * @return array<int, array<string, mixed>>
     */
    public static function getResources(array $itemtypes): array
    {
        $out = [];

        foreach (ReservationView::getItemsForTypes($itemtypes) as $item) {
            $out[] = [
                'id'    => ReservationView::RESOURCE_PREFIX . $item['id'],
                'title' => $item['name'],
                'color' => $item['color'],
            ];
        }

        return $out;
    }

    /**
     * Reservas dos itens dos tipos pedidos, no intervalo.
     *
     * @param array<int, string> $itemtypes itemtypes marcados na barra lateral
     * @return array<int, array<string, mixed>>
     */
    public static function getEvents(array $itemtypes, string $begin, string $end, bool $only_mine = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!ReservationProvider::canView()) {
            return [];
        }

        // Mesma rede de segurança do EventProvider: a consulta nunca varre
        // mais que 31 dias, mesmo que o cliente peça mais.
        $begin_ts = strtotime($begin);
        $end_ts   = strtotime($end);
        if ($begin_ts !== false && $end_ts !== false) {
            $max_ts = $begin_ts + (31 * 86400);
            if ($end_ts > $max_ts) {
                $end = date('Y-m-d H:i:s', $max_ts);
            }
        }

        // `getItemsForTypes()` já buscou nome, tipo e rótulo de cada aparelho
        // reservável — uma consulta POR TIPO de ativo, não uma por aparelho
        // (ver `ReservationView::getReservableItems()`). Indexar pelo id da
        // linha de `ReservationItem` (o mesmo valor de `reservationitems_id`
        // que cada reserva grava) reaproveita esse resultado no laço abaixo,
        // em vez de reconsultar o aparelho reserva por reserva.
        $items = ReservationView::getItemsForTypes($itemtypes);
        if ($items === []) {
            return [];
        }

        $items_by_resid = [];
        foreach ($items as $item) {
            $items_by_resid[(int) $item['id']] = $item;
        }
        $items_ids = array_keys($items_by_resid);

        $res_table = Reservation::getTable();
        $me        = (int) Session::getLoginUserID();

        $where = [
            'reservationitems_id' => $items_ids,
            'begin'               => ['<=', $end],
            'end'                 => ['>=', $begin],
        ];

        if ($only_mine) {
            $where['users_id'] = $me;
        }

        // Sem JOIN com `ReservationItem` aqui: o recorte por entidade já
        // aconteceu dentro de `getItemsForTypes()` (que só devolve itens da
        // entidade ativa), então `$items_ids` já vem filtrado — refazer o
        // JOIN só para reconferir a mesma coisa seria uma junção a mais por
        // busca, sem checar nada de novo.
        $rows = $DB->request([
            'SELECT' => ['id', 'begin', 'end', 'comment', 'users_id', 'reservationitems_id'],
            'FROM'   => $res_table,
            'WHERE'  => $where,
            'ORDER'  => 'begin',
        ]);

        $now    = date('Y-m-d H:i:s');
        $events = [];

        // Cor por TIPO de ativo — sempre, mesmo sem o administrador ter
        // definido uma em `Settings::getReservationTypeColors()`
        // (Configuração do Plugin): "se Computador é azul, toda reserva de
        // computador aparece azul", nunca uma cor por aparelho individual —
        // é a cor calculada por hash do TIPO (`crc32($itemtype)`, a mesma
        // usada em `ReservationView::reduceItemsToTypes()`) que serve de
        // padrão até o administrador escolher outra. A distinção por
        // aparelho continua existindo, só que noutro lugar: a visão Por
        // item, cujas faixas (`getResources()`) usam a cor por dispositivo.
        $type_colors = Settings::getReservationTypeColors();

        foreach ($rows as $row) {
            $res_item_id = (int) $row['reservationitems_id'];
            $users_id    = (int) $row['users_id'];
            $begin_at    = (string) $row['begin'];
            $end_at      = (string) $row['end'];

            $state = self::getState($begin_at, $end_at, $now);
            $mine  = $users_id === $me;

            $item_info  = $items_by_resid[$res_item_id] ?? null;
            $itemtype   = $item_info['itemtype'] ?? null;
            $color      = ($itemtype !== null && isset($type_colors[$itemtype]))
                ? $type_colors[$itemtype]
                : EventProvider::getActorColor(crc32($itemtype ?? Reservation::class));
            $item_name  = $item_info['name'] ?? sprintf(__('Reserved item #%d', 'planner'), $res_item_id);
            $item_label = $item_info !== null
                ? sprintf('%s - %s', $item_info['type_name'], $item_info['name'])
                : $item_name;

            // Como os campos são preenchidos, e por quê:
            //
            //  title      quem reservou. Na visão por item a faixa já diz QUAL
            //             item é, então o que falta saber é de quem é a reserva.
            //  actorName  o ITEM. É o que alimenta o avatar e a cor, e assim o
            //             marcador do evento fica igual ao da barra lateral.
            //  users_id   o id do ITEM reservável, não o da pessoa. É a chave
            //             que o Kanban usa para agrupar "por item", casando com
            //             o valor das caixas da barra lateral.
            //  typeLabel  a observação da reserva, quando existe.
            $events[] = [
                'id'              => 'reservation_' . $row['id'],
                'title'           => EventProvider::getUserName($users_id),
                'start'           => str_replace(' ', 'T', $begin_at),
                'end'             => str_replace(' ', 'T', $end_at),
                'resourceId'      => ReservationView::RESOURCE_PREFIX . $res_item_id,
                'backgroundColor' => $color,
                'borderColor'     => $color,
                // Arrastar fica desligado: reagendar uma reserva precisa da
                // checagem de conflito com outras reservas do mesmo item, que
                // o core faz no formulário e que o endpoint de arrasto do
                // planejamento não executa (ver ReservationProvider).
                'editable'        => false,
                'extendedProps'   => [
                    'itemtype'   => Reservation::class,
                    'items_id'   => (int) $row['id'],
                    'users_id'   => $res_item_id,
                    'typeLabel'  => (string) ($row['comment'] ?? ''),
                    'typeColor'  => $color,
                    'actorColor' => $color,
                    'actorName'  => $item_label,
                    // Iniciais calculadas no servidor com a MESMA regra da
                    // barra lateral, para os dois marcadores do mesmo item
                    // baterem (ver ReservationView::getItemInitials()).
                    'actorInitials' => ReservationView::getItemInitials($item_name),
                    'content'    => (string) ($row['comment'] ?? ''),
                    'url'        => Reservation::getFormURLWithID((int) $row['id']),
                    'state'      => $state,
                    'stateLabel' => ReservationView::getStateLabel($state),
                    'priority'   => null,
                    'level'      => Settings::LEVEL_DETAILS,
                    'mine'       => $mine,
                ],
            ];
        }

        return $events;
    }

    /**
     * Indicador único: quantas reservas o período exibido contém.
     *
     * Havia também "em andamento" e "itens em uso". Saíram porque respondiam a
     * uma pergunta que ninguém faz olhando um calendário: quantas reservas
     * estão acontecendo NESTE INSTANTE não tem relação com o mês que está na
     * tela, e "itens em uso" mudava de significado conforme os filtros. A
     * contagem de reservas acompanha o que está sendo mostrado, que é o único
     * número que o período justifica.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public static function getStats(array $events): array
    {
        return ['events_count' => count($events)];
    }

    /**
     * Uma reserva não tem estado no banco — ele vem do relógio.
     */
    private static function getState(string $begin, string $end, string $now): int
    {
        if ($end < $now) {
            return ReservationView::STATE_FINISHED;
        }
        if ($begin <= $now) {
            return ReservationView::STATE_ONGOING;
        }

        return ReservationView::STATE_UPCOMING;
    }
}
