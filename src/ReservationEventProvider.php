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
use ReservationItem;
use Session;
use User;

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

        $items_ids = array_map(
            static fn(array $item) => (int) $item['id'],
            ReservationView::getItemsForTypes($itemtypes)
        );

        if ($items_ids === []) {
            return [];
        }

        $res_table  = Reservation::getTable();
        $item_table = ReservationItem::getTable();
        $me         = (int) Session::getLoginUserID();

        $where = [
            $res_table . '.reservationitems_id' => $items_ids,
            $res_table . '.begin'               => ['<=', $end],
            $res_table . '.end'                 => ['>=', $begin],
        ];

        if ($only_mine) {
            $where[$res_table . '.users_id'] = $me;
        }

        // O recorte por entidade é do ITEM, não da reserva: `glpi_reservations`
        // não tem `entities_id`. Sem este critério, um id de item de outra
        // entidade montado na requisição devolveria as reservas dele.
        $where += getEntitiesRestrictCriteria($item_table, '', '', true);

        $rows = $DB->request([
            'SELECT' => [
                $res_table . '.id',
                $res_table . '.begin',
                $res_table . '.end',
                $res_table . '.comment',
                $res_table . '.users_id',
                $res_table . '.reservationitems_id',
                $item_table . '.itemtype AS reserved_itemtype',
                $item_table . '.items_id AS reserved_items_id',
            ],
            'FROM'       => $res_table,
            'INNER JOIN' => [
                $item_table => [
                    'ON' => [
                        $item_table => 'id',
                        $res_table  => 'reservationitems_id',
                    ],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => $res_table . '.begin',
        ]);

        $now    = date('Y-m-d H:i:s');
        $events = [];

        foreach ($rows as $row) {
            $res_item_id = (int) $row['reservationitems_id'];
            $users_id    = (int) $row['users_id'];
            $begin_at    = (string) $row['begin'];
            $end_at      = (string) $row['end'];

            $state = self::getState($begin_at, $end_at, $now);
            $color = EventProvider::getActorColor($res_item_id);
            $mine  = $users_id === $me;

            [$item_label, $item_name] = self::describeItem(
                (string) $row['reserved_itemtype'],
                (int) $row['reserved_items_id']
            );

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

    /**
     * @return array{0: string, 1: string} rótulo completo ("Computador - X")
     *                                     e o nome cru do ativo ("X")
     */
    private static function describeItem(string $itemtype, int $items_id): array
    {
        $item = getItemForItemtype($itemtype);

        if ($item === false || !$item->getFromDB($items_id)) {
            $fallback = sprintf(__('Reserved item #%d', 'planner'), $items_id);

            return [$fallback, $fallback];
        }

        $name = $item->getName();

        return [sprintf('%s - %s', $item::getTypeName(1), $name), $name];
    }
}
