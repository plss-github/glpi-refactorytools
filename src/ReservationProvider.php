<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Reservas de itens como compromissos da agenda.
 *
 * O GLPI não considera reserva um tipo de planejamento: `Reservation` não está
 * em `$CFG_GLPI['planning_types']` e não implementa `populatePlanning()`, então
 * o que a pessoa reservou nunca aparece na agenda dela — nem na nativa, nem na
 * do plugin. Na prática, um recurso reservado ocupa o tempo de quem reservou
 * tanto quanto uma tarefa, e não vê-lo é o que faz alguém marcar uma reunião
 * em cima de uma sala já reservada.
 *
 * Esta classe preenche essa lacuna devolvendo linhas no MESMO formato que
 * `populatePlanning()` do core produz, para `EventProvider` tratar reserva
 * como trata qualquer outro tipo, sem caso especial na montagem do evento.
 *
 * Visibilidade: exige o direito `reservation` (READ ou "fazer reserva") e
 * respeita o recorte por entidade do item reservado. Quais AGENDAS podem ser
 * abertas continua sendo decidido por `AccessPolicy`, antes de chegar aqui.
 */

namespace GlpiPlugin\Planner;

use Glpi\DBAL\QueryFunction;
use Planning;
use Reservation;
use ReservationItem;
use Session;

final class ReservationProvider
{
    /**
     * Itemtype usado pelo plugin para representar reservas na lista de tipos.
     * É o nome real da classe do core, então o rótulo e o ícone saem
     * traduzidos de graça.
     */
    public const ITEMTYPE = Reservation::class;

    public static function canView(): bool
    {
        return Session::haveRightsOr(
            Reservation::$rightname,
            [READ, ReservationItem::RESERVEANITEM]
        );
    }

    /**
     * Reservas de um usuário no intervalo, no formato de `populatePlanning()`.
     *
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

        $res_table  = Reservation::getTable();
        $item_table = ReservationItem::getTable();

        $where = [
            $res_table . '.users_id' => $who,
            // Sobreposição com a janela exibida: começa antes do fim dela
            // e termina depois do começo dela.
            $res_table . '.begin'    => ['<=', $end],
            $res_table . '.end'      => ['>=', $begin],
        ] + getEntitiesRestrictCriteria($item_table, '', '', true);

        // "Mostrar concluídos" desmarcado: some com o que já terminou. Filtrar
        // na consulta, e não depois, evita trazer do banco linhas que seriam
        // descartadas — numa agenda de mês inteiro isso é a maioria delas.
        if (($options['state_done'] ?? true) === false) {
            $where[] = [$res_table . '.end' => ['>=', QueryFunction::now()]];
        }

        $rows = iterator_to_array($DB->request([
            'SELECT' => [
                $res_table . '.id',
                $res_table . '.begin',
                $res_table . '.end',
                $res_table . '.comment',
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
        ]));

        // Nomes dos itens reservados em lote — uma consulta por ITEMTYPE
        // presente nas linhas, não uma por reserva (ver `describeReservedItems()`).
        $names = self::describeReservedItems($rows);

        $events = [];
        $now    = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            // Reserva não tem campo de estado no banco; aqui ele vem do
            // relógio. Sem isso a reserva caía na coluna "Informação" do Kanban
            // e "Mostrar concluídos" não escondia as já encerradas, que é
            // justamente o que se quer esconder ao limpar a agenda.
            $state = ((string) $row['end'] < $now) ? Planning::DONE : Planning::TODO;

            $name_key = $row['reserved_itemtype'] . '|' . $row['reserved_items_id'];

            $events[self::ITEMTYPE . '$$' . $row['id']] = [
                'state'            => $state,
                'itemtype'         => self::ITEMTYPE,
                'id'               => (int) $row['id'],
                'name'             => $names[$name_key]
                    ?? sprintf(__('Reserved item #%d', 'planner'), (int) $row['reserved_items_id']),
                'content'          => (string) ($row['comment'] ?? ''),
                'begin'            => (string) $row['begin'],
                'end'              => (string) $row['end'],
                'url'              => Reservation::getFormURLWithID((int) $row['id']),
                'users_id'         => $who,
                'color'            => $options['color'] ?? '',
                'event_type_color' => $options['event_type_color'] ?? '',
                // Arrastar fica desligado para reservas. O endpoint do core que
                // o plugin usa para gravar um arrasto
                // (`update_event_times`) monta um array `plan`, que é a forma
                // das TAREFAS; `Reservation` guarda `begin`/`end` direto e
                // ignoraria esse array. O gesto salvaria nada, em silêncio.
                // Reagendar uma reserva continua sendo pelo formulário dela,
                // onde o core ainda checa conflito com outras reservas do
                // mesmo item.
                'editable'         => false,
            ];
        }

        return $events;
    }

    /**
     * Título de cada linha: o nome do item reservado, com o tipo na frente
     * ("Computador - NOTE-014"). Sem o tipo, uma agenda com sala, veículo e
     * notebook vira uma lista de nomes soltos sem contexto.
     *
     * Reaproveita `ReservationView::getReservableItems()` — já buscado (e
     * cacheado por requisição) com `getName()` de verdade, não a coluna crua
     * — indexado aqui por itemtype+id em vez de pelo id da linha de
     * `ReservationItem`. A versão anterior chamava `getFromDB()` uma vez POR
     * RESERVA; numa agenda com muitas reservas no período, isso sozinho já
     * bastava para deixar a tela lenta (o mesmo problema, e a mesma correção,
     * de `ReservationEventProvider::getEvents()`).
     *
     * Um item reservado que não está mais na lista de reserváveis (alguém
     * desmarcou o aparelho como reservável depois desta reserva existir) cai
     * na consulta direta de reserva — caso raro, não vale otimizar.
     *
     * @param array<int, array<string, mixed>> $rows linhas com `reserved_itemtype`/`reserved_items_id`
     * @return array<string, string> chave "itemtype|items_id" => nome já formatado
     */
    private static function describeReservedItems(array $rows): array
    {
        $names = [];
        foreach (ReservationView::getReservableItems() as $item) {
            $names[$item['itemtype'] . '|' . $item['items_id']] = sprintf('%s - %s', $item['type_name'], $item['name']);
        }

        $missing_ids_by_itemtype = [];
        foreach ($rows as $row) {
            $key = $row['reserved_itemtype'] . '|' . $row['reserved_items_id'];
            if (!isset($names[$key])) {
                $missing_ids_by_itemtype[(string) $row['reserved_itemtype']][(int) $row['reserved_items_id']] = true;
            }
        }

        foreach ($missing_ids_by_itemtype as $itemtype => $ids) {
            $item = getItemForItemtype($itemtype);
            if ($item === false) {
                continue;
            }

            foreach ($ids as $items_id => $_) {
                if ($item->getFromDB($items_id)) {
                    $names[$itemtype . '|' . $items_id] = sprintf('%s - %s', $item::getTypeName(1), $item->getName());
                }
            }
        }

        return $names;
    }
}
