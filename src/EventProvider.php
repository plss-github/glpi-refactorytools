<?php

/**
 * Planner
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
 */

namespace GlpiPlugin\Planner;

use Planning;
use User;

final class EventProvider
{
    /**
     * Cor por tipo de compromisso. Fixa e legendada na tela — cor por tipo
     * informa mais que cor por pessoa, porque na visão de equipe a pessoa já
     * é a raia. O tom de cada pessoa entra como barra lateral do evento.
     *
     * @var array<string, string>
     */
    private const TYPE_COLORS = [
        'TicketTask'            => '#2f6df6',
        'ProblemTask'           => '#e8833a',
        'ChangeTask'            => '#8b5cf6',
        'ProjectTask'           => '#12a594',
        'Reminder'              => '#6b7a90',
        'Reservation'           => '#0891b2',
        'PlanningExternalEvent' => '#d6336c',
    ];

    private const DEFAULT_TYPE_COLOR = '#6b7a90';

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
     * @param array<int, string>      $users_levels users_id => nível, já filtrado
     *                                              por AccessPolicy::filterRequested()
     * @param array<int, string>|null $only_types   itemtypes a incluir.
     *        `null` = sem filtro (todos os tipos). Array VAZIO = nenhum tipo,
     *        e o resultado é vazio. A distinção existe porque a tela permite
     *        desmarcar todos os tipos na barra lateral: tratar vazio como
     *        "todos" fazia desmarcar tudo devolver a agenda inteira, o oposto
     *        do que o usuário pediu.
     * @return array<int, array<string, mixed>> eventos no formato FullCalendar
     */
    public static function getEvents(
        array $users_levels,
        string $begin,
        string $end,
        ?array $only_types = null,
        bool $include_done = true
    ): array {
        if ($only_types === []) {
            return [];
        }

        $events = [];

        foreach ($users_levels as $users_id => $level) {
            $actor_color = self::getActorColor((int) $users_id);

            foreach (self::getProviderTypes() as $itemtype) {
                if ($only_types !== null && !in_array($itemtype, $only_types, true)) {
                    continue;
                }

                $raw = self::fetchRows($itemtype, [
                    'who'              => (int) $users_id,
                    'whogroup'         => 0,
                    'begin'            => $begin,
                    'end'              => $end,
                    'color'            => $actor_color,
                    'event_type_color' => self::getTypeColor($itemtype),
                    'state_done'       => $include_done,
                    'display'          => true,
                    'genical'          => false,
                ]);

                if (!is_array($raw)) {
                    continue;
                }

                foreach ($raw as $row) {
                    $event = self::normalize($row, (int) $users_id, $level, $actor_color);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
            }
        }

        return $events;
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
    private static function normalize(array $row, int $users_id, string $level, string $actor_color): ?array
    {
        $begin = (string) ($row['begin'] ?? '');
        $end   = (string) ($row['end'] ?? '');
        if ($begin === '' || $end === '') {
            return null;
        }

        $itemtype   = (string) ($row['itemtype'] ?? '');
        $items_id   = (int) ($row['id'] ?? 0);
        $is_details = $level === Settings::LEVEL_DETAILS;

        $title   = (string) ($row['name'] ?? '');
        $content = (string) ($row['content'] ?? $row['text'] ?? '');

        $type_color = self::getTypeColor((string) ($row['itemtype'] ?? ''));
        $type_label = self::getTypeLabel((string) ($row['itemtype'] ?? ''));
        $state      = isset($row['state']) ? (int) $row['state'] : null;
        $state_label = $state !== null ? self::getStateLabel($state) : '';
        $priority   = $row['priority'] ?? null;
        $url        = $row['url'] ?? null;

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
            $title       = __('Busy', 'planner');
            $content     = '';
            $itemtype    = '';
            $items_id    = 0;
            $type_color  = self::DEFAULT_TYPE_COLOR;
            $type_label  = '';
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
                'items_id'    => $items_id,
                'users_id'    => $users_id,
                'typeLabel'   => $type_label,
                'typeColor'   => $type_color,
                'actorColor'  => $actor_color,
                'actorName'   => self::getUserName($users_id),
                'content'     => $content,
                'url'         => $url,
                'state'       => $state,
                'stateLabel'  => $state_label,
                'priority'    => $priority,
                'level'       => $level,
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
            } elseif ($state === Planning::TODO) {
                $todo++;
            }
        }

        return [
            'events_count'  => count($events),
            'total_hours'   => round($total_seconds / 3600, 1),
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
     * Tipos que alimentam a agenda: os do core mais as reservas.
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

        return is_array($raw) ? $raw : [];
    }

    /**
     * Cor de um tipo de compromisso.
     *
     * A cor definida pelo administrador em Configuração tem prioridade; a
     * paleta embutida é só o ponto de partida. Guardar apenas o que foi
     * customizado deixa os demais tipos acompanharem a paleta do plugin.
     */
    public static function getTypeColor(string $itemtype): string
    {
        $custom = Settings::getTypeColors();
        $short  = self::shortName($itemtype);

        return $custom[$itemtype]
            ?? $custom[$short]
            ?? self::TYPE_COLORS[$short]
            ?? self::DEFAULT_TYPE_COLOR;
    }

    /**
     * Cor de fábrica de um tipo, ignorando a customização. A tela de
     * configuração usa isto para o botão "voltar ao padrão".
     */
    public static function getDefaultTypeColor(string $itemtype): string
    {
        return self::TYPE_COLORS[self::shortName($itemtype)] ?? self::DEFAULT_TYPE_COLOR;
    }

    /**
     * Cor de uma pessoa, derivada do ID DELA — nunca da posição na lista.
     *
     * A versão anterior indexava a paleta pela ordem de iteração, e cada lado
     * iterava numa ordem diferente: a barra lateral pela ordem de
     * `AccessPolicy::getVisibleUsers()`, os eventos pela ordem em que os ids
     * chegaram na requisição (que o navegador ordena por id, por serem chaves
     * numéricas de objeto). O resultado é que o avatar na lateral e a borda do
     * evento quase nunca combinavam, e desmarcar uma pessoa trocava a cor das
     * outras no calendário mas não na lateral.
     *
     * Derivar do id torna a cor estável: a mesma pessoa tem o mesmo tom em
     * toda a tela, em qualquer ordem, e entre recarregamentos. Duas pessoas
     * podem cair no mesmo tom quando os ids são congruentes módulo o tamanho
     * da paleta; é um empate visual ocasional, muito melhor que uma
     * divergência sistemática. O JS repete esta conta em `pickColor()`.
     */
    public static function getActorColor(int $users_id): string
    {
        return self::ACTOR_COLORS[abs($users_id) % count(self::ACTOR_COLORS)];
    }

    public static function getTypeLabel(string $itemtype): string
    {
        if ($itemtype === '' || !is_a($itemtype, \CommonGLPI::class, true)) {
            return '';
        }

        return $itemtype::getTypeName(1);
    }

    /**
     * Tipos disponíveis para a legenda e para os filtros da barra lateral.
     *
     * @return array<int, array{itemtype: string, label: string, color: string}>
     */
    public static function getAvailableTypes(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $out = [];
        foreach (self::getProviderTypes() as $itemtype) {
            $out[] = [
                'itemtype' => $itemtype,
                'label'    => $itemtype::getTypeName(1),
                'color'    => self::getTypeColor($itemtype),
                'default'  => self::getDefaultTypeColor($itemtype),
            ];
        }

        return $out;
    }

    private static function getStateLabel(int $state): string
    {
        return match ($state) {
            Planning::TODO => __('To do', 'planner'),
            Planning::DONE => __('Done', 'planner'),
            default        => __('Information', 'planner'),
        };
    }

    private static function shortName(string $itemtype): string
    {
        $pos = strrpos($itemtype, '\\');

        return $pos === false ? $itemtype : substr($itemtype, $pos + 1);
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
