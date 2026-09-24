<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Busca os compromissos e os traduz para o formato do FullCalendar.
 *
 * Não consulta as tabelas de tarefas diretamente. Para cada itemtype de
 * `$CFG_GLPI['planning_types']` (TicketTask, ProblemTask, ChangeTask,
 * ProjectTask, Reminder, PlanningExternalEvent) chama o `populatePlanning()`
 * do próprio core.
 *
 * A exceção são as RESERVAS: o core não as considera planejamento e elas não
 * têm `populatePlanning()`, então `ReservationProvider` devolve as linhas no
 * mesmo formato e entra na lista de tipos como qualquer outro.
 *
 * Isso é deliberado e tem uma consequência de segurança que vale registrar:
 * `populatePlanning()` filtra cada linha por `canViewItem()` do observador.
 * Ou seja, mesmo com acesso concedido à agenda de alguém, o observador só
 * enxerga os compromissos cujos itens ele já poderia abrir. O plugin amplia
 * QUAIS AGENDAS podem ser abertas; ele nunca amplia o que o usuário pode ler
 * de um chamado, problema, mudança ou projeto. Reescrever as consultas aqui
 * traria de brinde a responsabilidade de reimplementar esse filtro — e cada
 * erro nessa reimplementação seria um vazamento.
 *
 * O efeito colateral aceito é que um supervisor sem direito de leitura nos
 * chamados da equipe vê lacunas na agenda dela. É o mesmo comportamento do
 * planejamento nativo com `Planning::READALL`, e está documentado no README.
 * O nível "livre/ocupado" NÃO contorna isso: ele apaga o conteúdo de eventos
 * que o observador já podia ver, em vez de revelar eventos que ele não podia.
 *
 * TIPOS VIRTUAIS: `PlanningExternalEvent` sozinho vira quatro entradas na
 * interface (Evento Externo/Interno/Viagem/Reunião), diferenciadas pela
 * categoria do evento. `EventTypes` define essas chaves; esta classe resolve,
 * linha a linha, qual chave uma linha do banco tem (`getVirtualKey()`) — a
 * consulta ao core continua sendo UMA por itemtype real, nunca uma por
 * variante, e o filtro por variante é aplicado depois, em memória.
 */

namespace GlpiPlugin\Refactorytools;

use Planning;
use PlanningExternalEvent;
use Session;
use User;

final class EventProvider
{
    /**
     * Tons de pessoa. Escolhidos com luminosidade próxima entre si para que
     * nenhuma raia pareça mais importante que outra, e distinguíveis nas
     * formas mais comuns de daltonismo (nenhum par vermelho/verde puro).
     *
     * @var array<int, string>
     */
    private const ACTOR_COLORS = [
        '#2f6df6', '#12a594', '#e8833a', '#8b5cf6',
        '#0ea5e9', '#d6336c', '#65a30d', '#f59e0b',
        '#0891b2', '#7c3aed', '#c2410c', '#059669',
    ];

    /**
     * Compromissos das agendas pedidas, no intervalo pedido.
     *
     * O intervalo é limitado a 31 dias como rede de segurança: mesmo que uma
     * versão futura da tela permita escolher um período maior, a consulta
     * nunca varre mais que um mês de cada vez. Sem isso, um `end` manipulado
     * na requisição (ou um bug de UI) poderia pedir um ano inteiro de tarefas
     * de todo mundo de uma vez.
     *
     * @param array<int, string>      $users_levels users_id => nível, já filtrado
     *                                              por AccessPolicy::filterRequested()
     * @param array<int, string>|null $only_types   chaves virtuais a incluir
     *        (ver `EventTypes`). `null` = sem filtro (todos os tipos). Array
     *        VAZIO = nenhum tipo, e o resultado é vazio. A distinção existe
     *        porque a tela permite desmarcar todos os tipos na barra lateral:
     *        tratar vazio como "todos" fazia desmarcar tudo devolver a agenda
     *        inteira, o oposto do que o usuário pediu.
     * @return array<int, array<string, mixed>> eventos no formato FullCalendar
     */
    public static function getEvents(
        array $users_levels,
        string $begin,
        string $end,
        ?array $only_types = null,
        bool $include_done = true,
        ?int $viewer_id = null
    ): array {
        if ($only_types === []) {
            return [];
        }

        $end = self::clampRange($begin, $end);

        $events = [];

        foreach ($users_levels as $users_id => $level) {
            $actor_color = self::getActorColor((int) $users_id);

            foreach (self::getProviderTypes() as $itemtype) {
                // PlanningExternalEvent nunca é pulado aqui pelo filtro de
                // tipo: ele guarda 4 variantes virtuais, e só depois de ler
                // a categoria de cada linha dá para saber qual delas é. Pular
                // a consulta inteira só quando NENHUMA das 4 foi pedida.
                if ($itemtype === PlanningExternalEvent::class) {
                    if ($only_types !== null && array_intersect($only_types, self::externalEventKeys()) === []) {
                        continue;
                    }
                } elseif ($only_types !== null && !in_array($itemtype, $only_types, true)) {
                    continue;
                }

                $raw = self::fetchRows($itemtype, [
                    'who'              => (int) $users_id,
                    'whogroup'         => 0,
                    'begin'            => $begin,
                    'end'              => $end,
                    'color'            => $actor_color,
                    'event_type_color' => '',
                    'state_done'       => $include_done,
                    'display'          => true,
                    'genical'          => false,
                ]);

                if (!is_array($raw)) {
                    continue;
                }

                foreach ($raw as $row) {
                    $virtual_key = self::getVirtualKey($itemtype, $row);

                    if ($only_types !== null && !in_array($virtual_key, $only_types, true)) {
                        continue;
                    }

                    $event = self::normalize($row, $virtual_key, (int) $users_id, $level, $actor_color, $viewer_id);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
            }
        }

        self::attachNotes($events, $viewer_id);
        self::attachTicketDurations($events);
        self::attachVisitReservations($events);

        return $events;
    }

    /**
     * Duração total já registrada no CHAMADO por trás de um compromisso de
     * tipo Chamado — é o que aparece ao passar o mouse no evento, a
     * pergunta original era "quanto tempo esse chamado já tomou", não
     * "quanto tempo esta tarefa específica levou".
     *
     * `glpi_tickets.actiontime` já é a soma mantida pelo próprio core sobre
     * TODAS as tarefas do chamado (qualquer técnico) — não há necessidade de
     * somar `glpi_tickettasks` aqui. Duas consultas em lote (tarefa -> chamado,
     * chamado -> actiontime), nunca uma por evento.
     *
     * @param array<int, array<string, mixed>> $events
     */
    /**
     * Anexa, em lote, a reserva ligada a cada compromisso de Visita (ver
     * `VisitLink`) — o que o popover mostra como "Reserva vinculada".
     */
    private static function attachVisitReservations(array &$events): void
    {
        $items_ids = [];
        foreach ($events as $event) {
            if (($event['extendedProps']['virtualType'] ?? '') === EventTypes::EVENT_VISIT) {
                $items_ids[] = (int) $event['extendedProps']['items_id'];
            }
        }

        if ($items_ids === []) {
            return;
        }

        $links = VisitLink::getForItems(array_values(array_unique($items_ids)));
        if ($links === []) {
            return;
        }

        foreach ($events as $key => $event) {
            $items_id = (int) $event['extendedProps']['items_id'];
            if (isset($links[$items_id])) {
                $events[$key]['extendedProps']['visitReservation'] = $links[$items_id]['label'];
            }
        }
    }

    private static function attachTicketDurations(array &$events): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $task_ids = [];
        foreach ($events as $event) {
            if ($event['extendedProps']['itemtype'] === EventTypes::TICKET_TASK) {
                $task_ids[(int) $event['extendedProps']['items_id']] = true;
            }
        }

        if ($task_ids === []) {
            return;
        }

        $tickets_by_task = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'tickets_id'],
            'FROM'   => 'glpi_tickettasks',
            'WHERE'  => ['id' => array_keys($task_ids)],
        ]) as $row) {
            $tickets_by_task[(int) $row['id']] = (int) $row['tickets_id'];
        }

        if ($tickets_by_task === []) {
            return;
        }

        $duration_by_ticket = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'actiontime'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => array_values(array_unique($tickets_by_task))],
        ]) as $row) {
            $duration_by_ticket[(int) $row['id']] = (int) $row['actiontime'];
        }

        foreach ($events as $key => $event) {
            if ($event['extendedProps']['itemtype'] !== EventTypes::TICKET_TASK) {
                continue;
            }

            $tickets_id = $tickets_by_task[(int) $event['extendedProps']['items_id']] ?? null;
            if ($tickets_id === null || !isset($duration_by_ticket[$tickets_id])) {
                continue;
            }

            $events[$key]['extendedProps']['ticketDuration'] = self::formatHoursMinutes($duration_by_ticket[$tickets_id]);
        }
    }

    /** "3h05min" — mesmo formato usado por `TechnicianStats::formatDuration()`. */
    private static function formatHoursMinutes(int $seconds): string
    {
        return sprintf(
            __('%1$dh%2$02dmin', 'refactorytools'),
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60)
        );
    }

    /**
     * Anexa a nota de gestor de cada compromisso, numa consulta só (ver
     * `EventNotes::getForPairs()`) em vez de uma consulta por evento. Decide
     * aqui, e não em `EventNotes`, quem PODE VER a nota: o dono do
     * compromisso e quem tem `canManageNoteFor()` sobre ele — mais ninguém,
     * nem quem só enxerga a agenda em nível "livre/ocupado" (que já não tem
     * itemtype/items_id no evento, então nunca casa com uma nota).
     *
     * @param array<int, array<string, mixed>> $events
     */
    private static function attachNotes(array &$events, ?int $viewer_id): void
    {
        if ($events === []) {
            return;
        }

        $viewer_id ??= (int) Session::getLoginUserID();

        $itemtypes = [];
        $items_ids = [];
        foreach ($events as $event) {
            $props = $event['extendedProps'];
            if ($props['itemtype'] === '' || $props['items_id'] <= 0) {
                continue;
            }
            $itemtypes[$props['itemtype']] = true;
            $items_ids[$props['items_id']] = true;
        }

        if ($itemtypes === [] || $items_ids === []) {
            return;
        }

        $notes = EventNotes::getForPairs(array_keys($itemtypes), array_keys($items_ids));
        if ($notes === []) {
            return;
        }

        foreach ($events as $key => $event) {
            $props = $event['extendedProps'];
            if ($props['itemtype'] === '' || $props['items_id'] <= 0) {
                continue;
            }

            $note_key = $props['itemtype'] . '|' . $props['items_id'];
            $item_notes = $notes[$note_key] ?? null;
            if ($item_notes === null) {
                continue;
            }

            $owner_id   = (int) $props['users_id'];
            $can_manage = AccessPolicy::canManageNoteFor($owner_id, $viewer_id);
            $can_see    = $can_manage || $owner_id === $viewer_id;

            if (!$can_see) {
                continue;
            }

            $events[$key]['extendedProps']['notes'] = array_map(
                static fn (array $n): array => [
                    'id'     => $n['id'],
                    'note'   => $n['note'],
                    'author' => $n['author_name'],
                ],
                $item_notes
            );
        }
    }

    /**
     * Nunca deixa a consulta varrer mais que 31 dias, mesmo que o cliente
     * peça mais. Uma margem de 3 dias sobre 4 semanas cobre a visão de mês
     * (que pode mostrar até 6 semanas na grade) sem abrir espaço para um
     * intervalo de ano.
     */
    private static function clampRange(string $begin, string $end): string
    {
        $begin_ts = strtotime($begin);
        $end_ts   = strtotime($end);

        if ($begin_ts === false || $end_ts === false) {
            return $end;
        }

        $max_ts = $begin_ts + (31 * 86400);

        return $end_ts > $max_ts ? date('Y-m-d H:i:s', $max_ts) : $end;
    }

    /**
     * Qual chave virtual (ver `EventTypes`) uma linha do banco representa.
     * Só `PlanningExternalEvent` tem mais de uma possibilidade — as outras
     * linhas usam o próprio itemtype como chave.
     */
    private static function getVirtualKey(string $itemtype, array $row): string
    {
        if ($itemtype !== PlanningExternalEvent::class) {
            return $itemtype;
        }

        $category_id = (int) ($row['planningeventcategories_id'] ?? 0);
        if ($category_id <= 0) {
            return EventTypes::EVENT_EXTERNAL;
        }

        foreach ([EventTypes::EVENT_INTERNAL, EventTypes::EVENT_TRAVEL, EventTypes::EVENT_MEETING, EventTypes::EVENT_VISIT] as $variant) {
            if (Settings::getCategoryId($variant) === $category_id) {
                return $variant;
            }
        }

        return EventTypes::EVENT_EXTERNAL;
    }

    /** @return array<int, string> */
    private static function externalEventKeys(): array
    {
        return [
            EventTypes::EVENT_EXTERNAL,
            EventTypes::EVENT_INTERNAL,
            EventTypes::EVENT_TRAVEL,
            EventTypes::EVENT_MEETING,
        ];
    }

    /**
     * Converte uma linha do core para um evento do FullCalendar v4 (a versão
     * embarcada no GLPI 11).
     *
     * Os dois formatos de origem diferem: as tarefas de ITIL devolvem a
     * descrição em `content`, enquanto Reminder e PlanningExternalEvent
     * (trait `Glpi\Features\PlanningEvent`) devolvem em `text` e ainda trazem
     * `rrule` para eventos recorrentes.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    /**
     * Dia útil (segunda a sexta) e horário entre 8h e 19h, pelo INÍCIO do
     * compromisso — sábado, domingo e qualquer coisa começando antes das 8h
     * ou às/depois das 19h contam como fora.
     */
    public static function isBusinessHours(string $begin): bool
    {
        $ts = strtotime($begin);
        if ($ts === false) {
            return false;
        }

        $weekday = (int) date('N', $ts); // 1 (segunda) .. 7 (domingo)
        $hour    = (int) date('H', $ts);

        return $weekday <= 5 && $hour >= 8 && $hour < 19;
    }

    private static function normalize(
        array $row,
        string $virtual_key,
        int $users_id,
        string $level,
        string $actor_color,
        ?int $viewer_id
    ): ?array {
        $begin = (string) ($row['begin'] ?? '');
        $end   = (string) ($row['end'] ?? '');
        if ($begin === '' || $end === '') {
            return null;
        }

        // Fora do horário comercial (dia útil, 8h-19h), um compromisso só é
        // visível para o próprio dono — quem está olhando a agenda de
        // outra pessoa (mesmo com "Ver todas as agendas") não vê nada ali,
        // nem no nível livre/ocupado. O dono sempre vê a própria agenda
        // normalmente, dentro ou fora do horário. Decidido pelo INÍCIO do
        // compromisso, não pelo fim: um evento que começa às 18h e vai até
        // as 20h ainda conta como dentro.
        $effective_viewer = $viewer_id ?? (int) Session::getLoginUserID();
        if ($users_id !== $effective_viewer && !self::isBusinessHours($begin)) {
            return null;
        }

        // Duração zero (tarefa registrada sem tempo, `begin` === `end`)
        // renderiza como uma linha quase invisível no calendário — sem
        // título legível nem área para passar o mouse. 15 minutos é a
        // mesma granularidade mínima que os slots do próprio calendário já
        // usam, então o bloco fica alinhado à grade.
        if (strtotime($end) <= strtotime($begin)) {
            $end = date('Y-m-d H:i:s', strtotime($begin) + 15 * 60);
        }

        $real_itemtype = EventTypes::realItemtype($virtual_key);
        $items_id      = (int) ($row['id'] ?? 0);
        $is_details    = $level === Settings::LEVEL_DETAILS;

        $title   = (string) ($row['name'] ?? '');
        $content = (string) ($row['content'] ?? $row['text'] ?? '');

        $type_color  = self::getTypeColor($virtual_key, $viewer_id);
        $type_label  = EventTypes::getLabel($virtual_key);
        $type_icon   = EventTypes::getIcon($virtual_key);
        $state       = isset($row['state']) ? (int) $row['state'] : null;
        $state_label = $state !== null ? self::getStateLabel($state) : '';
        $priority    = $row['priority'] ?? null;
        $url         = $row['url'] ?? null;
        $itemtype    = $real_itemtype;

        if (!$is_details) {
            // Livre/ocupado: some TUDO que diz algo sobre o compromisso, não só
            // o título. Feito aqui, e não no template, para que o payload que
            // sai do servidor já não contenha o dado — a aba de rede do
            // navegador é parte da interface.
            //
            // A lista é longa de propósito. Cada campo abaixo já foi, sozinho,
            // um vazamento: a COR DO TIPO pinta a borda do cartão no Kanban e
            // entrega se é chamado, problema ou projeto; o ESTADO distribui o
            // cartão entre as colunas "A fazer"/"Concluído" e aparece na coluna
            // Situação da Lista; a PRIORIDADE nem é usada na tela e ia junto no
            // JSON. Quem concedeu "apenas livre/ocupado" autorizou revelar que
            // o horário está tomado, nada além disso.
            $title       = __('Busy', 'refactorytools');
            $content     = '';
            $itemtype    = '';
            $items_id    = 0;
            $type_color  = EventTypes::getDefaultColor('');
            $type_label  = '';
            $type_icon   = '';
            $state       = null;
            $state_label = '';
            $priority    = null;
            $url         = null;
        }

        $event = [
            'id'              => sprintf('%s_%d_%d_%s', $itemtype !== '' ? $itemtype : 'busy', $items_id, $users_id, $begin),
            'title'           => $title,
            'start'           => str_replace(' ', 'T', $begin),
            'end'             => str_replace(' ', 'T', $end),
            'resourceId'      => 'user_' . $users_id,
            'backgroundColor' => $is_details ? $type_color : '#94a3b8',
            'borderColor'     => $actor_color,
            // Arrastar/redimensionar continua sendo decidido pelo core: a linha
            // já vem com `editable` calculado por canUpdateItem().
            //
            // Eventos recorrentes ficam de fora mesmo quando o usuário pode
            // editá-los. Arrastar uma ocorrência de uma série é ambíguo — move
            // só aquele dia ou a série inteira? — e o core resolve isso com um
            // diálogo próprio. Sem esse diálogo, qualquer das duas escolhas
            // surpreende alguém. Melhor não oferecer o gesto: o evento continua
            // editável pelo formulário dele, onde a recorrência aparece.
            'editable'        => $is_details
                                 && (bool) ($row['editable'] ?? false)
                                 && empty($row['rrule']),
            'extendedProps'   => [
                'itemtype'    => $itemtype,
                'virtualType' => $is_details ? $virtual_key : '',
                'items_id'    => $items_id,
                'users_id'    => $users_id,
                'typeLabel'   => $type_label,
                'typeColor'   => $type_color,
                'typeIcon'    => $type_icon,
                'actorColor'  => $actor_color,
                'actorName'   => self::getUserName($users_id),
                'content'     => $content,
                'url'         => $url,
                'state'       => $state,
                'stateLabel'  => $state_label,
                'priority'    => $priority,
                'level'       => $level,
                // Preenchidos depois, em lote, por `attachNotes()` — nunca
                // aqui, linha a linha, o que seria uma consulta por evento.
                // `canManageNote` é a exceção: não depende da nota existir,
                // só de quem observa ser gestor de quem é dono do
                // compromisso, então já é conhecido aqui.
                'notes'          => [],
                'ticketDuration' => '',
                'visitReservation' => '',
                'canManageNote' => $is_details && $itemtype !== ''
                                   ? AccessPolicy::canManageNoteFor($users_id, $viewer_id)
                                   : false,
            ],
        ];

        // Recorrência: só Reminder e PlanningExternalEvent trazem rrule. O
        // FullCalendar embarcado já vem com o plugin 'rrule' registrado (é o
        // que o planejamento nativo usa), então basta repassar.
        if (!empty($row['rrule']) && is_array($row['rrule'])) {
            $event['rrule'] = array_merge($row['rrule'], ['dtstart' => $event['start']]);
            $event['duration'] = self::getDuration($begin, $end);
            unset($event['start'], $event['end']);
        }

        return $event;
    }

    /**
     * Indicadores do topo da tela, calculados sobre os mesmos eventos já
     * carregados — não há segunda consulta ao banco.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public static function getStats(array $events): array
    {
        $seconds_by_user = [];
        $total_seconds   = 0;
        $todo_seconds    = 0;
        $done_seconds    = 0;
        $done            = 0;
        $todo            = 0;

        foreach ($events as $event) {
            // Eventos recorrentes não têm start/end fixos; entram na contagem
            // de compromissos mas não nas horas, porque o número de ocorrências
            // dentro da janela só é conhecido depois que o FullCalendar expande.
            if (!isset($event['start'], $event['end'])) {
                continue;
            }

            $duration = strtotime((string) $event['end']) - strtotime((string) $event['start']);
            if ($duration <= 0) {
                continue;
            }

            $users_id = (int) ($event['extendedProps']['users_id'] ?? 0);
            $seconds_by_user[$users_id] = ($seconds_by_user[$users_id] ?? 0) + $duration;
            $total_seconds += $duration;

            $state = $event['extendedProps']['state'] ?? null;
            if ($state === Planning::DONE) {
                $done++;
                $done_seconds += $duration;
            } elseif ($state === Planning::TODO) {
                $todo++;
                $todo_seconds += $duration;
            }
        }

        return [
            'events_count'  => count($events),
            'total_hours'   => round($total_seconds / 3600, 1),
            // Horas planejadas/realizadas: só os compromissos com estado A
            // FAZER/CONCLUÍDO entram nessas duas — um compromisso em
            // "Informação" (nem um nem outro) só conta nas horas TOTAIS. É o
            // par que a barra de indicadores mostra quando nada está
            // filtrado (ver `GlpiRefactoryTools.isEverythingSelected()`).
            'planned_hours' => round($todo_seconds / 3600, 1),
            'realised_hours' => round($done_seconds / 3600, 1),
            'done'          => $done,
            'todo'          => $todo,
            'people'        => count($seconds_by_user),
            'hours_by_user' => array_map(
                static fn(int $s) => round($s / 3600, 1),
                $seconds_by_user
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Rótulos e cores
    // -----------------------------------------------------------------------

    /**
     * Itemtypes REAIS que alimentam a agenda: os do core mais as reservas.
     * `PlanningExternalEvent` aparece uma vez só aqui, mesmo representando 4
     * chaves virtuais — a consulta ao banco é por itemtype real.
     *
     * A checagem de visibilidade fica aqui, num lugar só, para a lista de
     * filtros da barra lateral e a busca de eventos nunca discordarem sobre
     * quais tipos existem para este usuário.
     *
     * @return array<int, string>
     */
    private static function getProviderTypes(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $types = [];

        foreach ($CFG_GLPI['planning_types'] as $itemtype) {
            if (is_a($itemtype, \CommonGLPI::class, true) && $itemtype::canView()) {
                $types[] = $itemtype;
            }
        }

        if (ReservationProvider::canView()) {
            $types[] = ReservationProvider::ITEMTYPE;
        }

        return $types;
    }

    /**
     * Busca as linhas de um tipo. As reservas têm provedor próprio porque o
     * core não as trata como planejamento (ver ReservationProvider); todo o
     * resto usa o `populatePlanning()` do próprio itemtype.
     *
     * @param array<string, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    private static function fetchRows(string $itemtype, array $params): array
    {
        $raw = $itemtype === ReservationProvider::ITEMTYPE
            ? ReservationProvider::populatePlanning($params)
            : $itemtype::populatePlanning($params);

        $raw = is_array($raw) ? $raw : [];

        if ($itemtype === PlanningExternalEvent::class && $raw !== []) {
            $raw = self::attachCategoryIds($raw);
        }

        return $raw;
    }

    /**
     * `PlanningExternalEvent::populatePlanning()` (na verdade a implementação
     * compartilhada em `Glpi\Features\PlanningEvent`) monta o array de
     * retorno com uma lista FIXA de chaves — 'id', 'name', 'begin'... —
     * mesmo a consulta interna selecionando `$table.*`, a coluna
     * `planningeventcategories_id` nunca chega até aqui. Sem essa coluna,
     * `getVirtualKey()` não tem como saber se uma linha é Evento
     * Interno/Viagem/Reunião ou o Externo genérico — TUDO caía na variante
     * genérica, silenciosamente (confirmado testando contra a instância: um
     * evento criado como "Reunião" aparecia colorido e filtrado como "Evento
     * Externo").
     *
     * Uma única consulta complementar, pelos IDs que já vieram na resposta,
     * resolve isso sem precisar tocar no `populatePlanning()` do core.
     *
     * @param array<string, array<string, mixed>> $raw
     * @return array<string, array<string, mixed>>
     */
    private static function attachCategoryIds(array $raw): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($raw as $row) {
            if (isset($row['id'])) {
                $ids[(int) $row['id']] = true;
            }
        }

        if ($ids === []) {
            return $raw;
        }

        $categories = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'planningeventcategories_id'],
            'FROM'   => PlanningExternalEvent::getTable(),
            'WHERE'  => ['id' => array_keys($ids)],
        ]) as $db_row) {
            $categories[(int) $db_row['id']] = (int) $db_row['planningeventcategories_id'];
        }

        foreach ($raw as $key => $row) {
            $raw[$key]['planningeventcategories_id'] = $categories[(int) ($row['id'] ?? 0)] ?? 0;
        }

        return $raw;
    }

    /**
     * Cor de um tipo de compromisso — a mesma para todo mundo.
     *
     * Só duas camadas: a que o administrador definiu para a instância
     * inteira (`Settings`, editável em Configuração do Plugin — Super-Admin
     * apenas) e a paleta de fábrica do plugin (`EventTypes`), para o que o
     * administrador não customizou.
     *
     * Já existiu uma terceira camada, por USUÁRIO (`UserColors`): cada
     * pessoa podia escolher sua própria cor por tipo. Removida porque
     * contrariava o próprio propósito da cor — se "vermelho" pode significar
     * coisas diferentes para cada pessoa que abre a mesma agenda, a cor para
     * de comunicar nada de confiável para quem está olhando o compromisso de
     * OUTRA pessoa.
     *
     * `$viewer_id` continua sendo parâmetro (não é mais usado aqui) só para
     * não obrigar os chamadores a mudar de assinatura.
     */
    public static function getTypeColor(string $key, ?int $viewer_id = null): string
    {
        $admin = Settings::getTypeColors();

        return $admin[$key] ?? EventTypes::getDefaultColor($key);
    }

    public static function getActorColor(int $users_id): string
    {
        return self::ACTOR_COLORS[abs($users_id) % count(self::ACTOR_COLORS)];
    }

    /**
     * Tipos disponíveis para a legenda e para os filtros da barra lateral,
     * já com a cor deste observador.
     *
     * @return array<int, array{itemtype: string, label: string, icon: string, color: string, default: string}>
     */
    public static function getAvailableTypes(?int $viewer_id = null): array
    {
        $provider_types = self::getProviderTypes();
        $has_external    = in_array(PlanningExternalEvent::class, $provider_types, true);

        $out = [];
        foreach (EventTypes::getAll() as $key) {
            $real = EventTypes::realItemtype($key);

            if ($real === PlanningExternalEvent::class) {
                if (!$has_external) {
                    continue;
                }
            } elseif (!in_array($real, $provider_types, true)) {
                continue;
            }

            $out[] = [
                'itemtype' => $key,
                'label'    => EventTypes::getLabel($key),
                'icon'     => EventTypes::getIcon($key),
                'color'    => self::getTypeColor($key, $viewer_id),
                'default'  => EventTypes::getDefaultColor($key),
            ];
        }

        return $out;
    }

    /**
     * Tipos para a tela de CONFIGURAÇÃO do administrador — mostra a cor
     * padrão da instância, nunca a preferência pessoal de quem está olhando.
     * Misturar as duas faria um administrador que também personalizou a
     * própria tela editar, sem perceber, um valor que não é o que os outros
     * usuários realmente veem.
     *
     * @return array<int, array{itemtype: string, label: string, icon: string, color: string, default: string}>
     */
    public static function getAvailableTypesForAdmin(): array
    {
        $admin_colors = Settings::getTypeColors();
        $provider_types = self::getProviderTypes();
        $has_external   = in_array(PlanningExternalEvent::class, $provider_types, true);

        $out = [];
        foreach (EventTypes::getAll() as $key) {
            $real = EventTypes::realItemtype($key);

            if ($real === PlanningExternalEvent::class) {
                if (!$has_external) {
                    continue;
                }
            } elseif (!in_array($real, $provider_types, true)) {
                continue;
            }

            $out[] = [
                'itemtype' => $key,
                'label'    => EventTypes::getLabel($key),
                'icon'     => EventTypes::getIcon($key),
                'color'    => $admin_colors[$key] ?? EventTypes::getDefaultColor($key),
                'default'  => EventTypes::getDefaultColor($key),
            ];
        }

        return $out;
    }

    private static function getStateLabel(int $state): string
    {
        return match ($state) {
            Planning::TODO => __('To do', 'refactorytools'),
            Planning::DONE => __('Done', 'refactorytools'),
            default        => __('Information', 'refactorytools'),
        };
    }

    /**
     * Duração no formato HH:MM:SS aceito pelo FullCalendar para eventos com
     * regra de recorrência.
     */
    private static function getDuration(string $begin, string $end): string
    {
        $seconds = max(0, strtotime($end) - strtotime($begin));

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    /** @var array<int, string> */
    private static array $name_cache = [];

    public static function getUserName(int $users_id): string
    {
        if (isset(self::$name_cache[$users_id])) {
            return self::$name_cache[$users_id];
        }

        $user = new User();
        $name = $user->getFromDB($users_id) ? $user->getFriendlyName() : '';

        return self::$name_cache[$users_id] = $name;
    }
}
