<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Taxonomia de tipos de compromisso exibida na interface.
 *
 * O GLPI só tem seis tipos reais de planejamento (mais a Reserva, que o
 * plugin acrescenta): TicketTask, ChangeTask, ProblemTask, ProjectTask,
 * Reminder, PlanningExternalEvent. "Evento Interno", "Viagem" e "Reunião" não
 * são tipos novos no banco — são o MESMO `PlanningExternalEvent`, diferenciado
 * pela categoria (`PlanningEventCategory`) que o core já usa para isso. Criar
 * um itemtype de verdade para cada um seria reimplementar uma tabela e um
 * formulário que o GLPI já resolve com uma coluna.
 *
 * Por isso um "tipo" nesta tela é uma CHAVE VIRTUAL, não necessariamente um
 * itemtype: para a maioria é a mesma string (`TicketTask`, `Reservation`...),
 * mas para as três variantes de evento externo é `PlanningExternalEvent:sub`.
 * `EventProvider::getVirtualKey()` decide qual chave uma linha do banco tem,
 * a partir do itemtype real e, quando for o caso, da categoria.
 */

namespace GlpiPlugin\Planner;

use PlanningExternalEvent;

final class EventTypes
{
    public const TICKET_TASK  = 'TicketTask';
    public const CHANGE_TASK  = 'ChangeTask';
    public const PROBLEM_TASK = 'ProblemTask';
    public const PROJECT_TASK = 'ProjectTask';
    public const RESERVATION  = 'Reservation';
    public const REMINDER     = 'Reminder';

    /**
     * Chamado em que a pessoa é REQUERENTE, não técnico — virtual porque o
     * itemtype real (`Ticket`) não implementa `populatePlanning()`; é
     * `TicketRequesterProvider` quem monta as linhas (ver lá o porquê da
     * âncora de data). Chave própria, separada de `TICKET_TASK`: são
     * perguntas diferentes ("o que EU preciso fazer" vs. "o que EU pedi"),
     * cada uma com sua cor e seu filtro na barra lateral.
     */
    public const TICKET_REQUESTED = 'TicketRequested';

    /** Evento externo "puro": sem categoria, ou com uma categoria que não é nenhuma das três abaixo. */
    public const EVENT_EXTERNAL = 'PlanningExternalEvent';
    public const EVENT_INTERNAL = 'PlanningExternalEvent:internal';
    public const EVENT_TRAVEL   = 'PlanningExternalEvent:travel';
    public const EVENT_MEETING  = 'PlanningExternalEvent:meeting';

    /**
     * Tipos que o usuário pode criar pela tela ("+ Novo compromisso").
     *
     * Chamado, Mudança, Problema e Projeto ficam de fora: no GLPI, uma tarefa
     * desses tipos só existe presa a um chamado/mudança/problema/projeto já
     * aberto — não dá para criar uma "do nada" a partir do calendário, nem no
     * planejamento nativo. Reserva também fica de fora: tem fluxo próprio (a
     * tela de Reservas), com checagem de disponibilidade que não faz sentido
     * duplicar aqui.
     *
     * @var array<int, string>
     */
    public const CREATABLE = [
        self::REMINDER,
        self::EVENT_EXTERNAL,
        self::EVENT_INTERNAL,
        self::EVENT_TRAVEL,
        self::EVENT_MEETING,
    ];

    /**
     * @return array<int, string> todas as chaves virtuais, na ordem em que
     *                            devem aparecer na interface
     */
    public static function getAll(): array
    {
        return [
            self::TICKET_TASK,
            self::TICKET_REQUESTED,
            self::CHANGE_TASK,
            self::PROBLEM_TASK,
            self::PROJECT_TASK,
            self::RESERVATION,
            self::EVENT_EXTERNAL,
            self::EVENT_INTERNAL,
            self::EVENT_TRAVEL,
            self::EVENT_MEETING,
            self::REMINDER,
        ];
    }

    public static function getLabel(string $key): string
    {
        return match ($key) {
            self::TICKET_TASK      => __('Ticket', 'planner'),
            self::TICKET_REQUESTED => __('My requested ticket', 'planner'),
            self::CHANGE_TASK  => __('Change', 'planner'),
            self::PROBLEM_TASK => __('Problem', 'planner'),
            self::PROJECT_TASK => __('Project', 'planner'),
            self::RESERVATION  => __('Reservation', 'planner'),
            self::REMINDER     => __('Reminder', 'planner'),
            self::EVENT_EXTERNAL => __('External event', 'planner'),
            self::EVENT_INTERNAL => __('Internal event', 'planner'),
            self::EVENT_TRAVEL   => __('Travel', 'planner'),
            self::EVENT_MEETING  => __('Meeting', 'planner'),
            default => $key,
        };
    }

    public static function getIcon(string $key): string
    {
        return match ($key) {
            self::TICKET_TASK      => 'ti ti-headset',
            self::TICKET_REQUESTED => 'ti ti-user-question',
            self::CHANGE_TASK  => 'ti ti-replace',
            self::PROBLEM_TASK => 'ti ti-alert-triangle',
            self::PROJECT_TASK => 'ti ti-briefcase',
            self::RESERVATION  => 'ti ti-calendar-time',
            self::REMINDER     => 'ti ti-note',
            self::EVENT_EXTERNAL => 'ti ti-calendar-event',
            self::EVENT_INTERNAL => 'ti ti-building',
            self::EVENT_TRAVEL   => 'ti ti-plane',
            self::EVENT_MEETING  => 'ti ti-users',
            default => 'ti ti-calendar',
        };
    }

    /**
     * Paleta de fábrica. Dez tons distintos, um por tipo — o botão "voltar ao
     * padrão" da configuração de cores (de administrador ou pessoal) usa isto.
     */
    public static function getDefaultColor(string $key): string
    {
        return match ($key) {
            self::TICKET_TASK      => '#2f6df6',
            self::TICKET_REQUESTED => '#0d9488',
            self::CHANGE_TASK  => '#8b5cf6',
            self::PROBLEM_TASK => '#e8833a',
            self::PROJECT_TASK => '#12a594',
            self::RESERVATION  => '#0891b2',
            self::REMINDER     => '#6b7a90',
            self::EVENT_EXTERNAL => '#d6336c',
            self::EVENT_INTERNAL => '#7c3aed',
            self::EVENT_TRAVEL   => '#f59e0b',
            self::EVENT_MEETING  => '#059669',
            default => '#6b7a90',
        };
    }

    public static function isExternalEventVariant(string $key): bool
    {
        return $key === self::EVENT_EXTERNAL
            || $key === self::EVENT_INTERNAL
            || $key === self::EVENT_TRAVEL
            || $key === self::EVENT_MEETING;
    }

    /**
     * Itemtype REAL por trás de uma chave virtual. É o que vale para
     * permissão, consulta e link de edição — a chave virtual só existe para
     * filtro e cor.
     */
    public static function realItemtype(string $key): string
    {
        if (self::isExternalEventVariant($key)) {
            return PlanningExternalEvent::class;
        }
        if ($key === self::TICKET_REQUESTED) {
            return 'Ticket';
        }

        return $key;
    }

    /**
     * Chave de configuração onde fica guardado o ID da categoria que
     * distingue esta variante, ou null para quem não é uma variante de evento
     * externo (nada a resolver) ou é o "Externo" genérico (nenhuma categoria
     * específica — é o que sobra depois de descontar as outras três).
     */
    public static function categorySettingKey(string $key): ?string
    {
        return match ($key) {
            self::EVENT_INTERNAL => 'category_internal_id',
            self::EVENT_TRAVEL   => 'category_travel_id',
            self::EVENT_MEETING  => 'category_meeting_id',
            default => null,
        };
    }

    /**
     * Nome da categoria semeada na instalação. Só usado na instalação em si
     * (para criar a linha) — depois disso a referência é sempre pelo ID
     * guardado na configuração, nunca pelo nome, porque o administrador pode
     * renomear a categoria livremente sem quebrar o filtro.
     */
    public static function seedCategoryName(string $key): ?string
    {
        return match ($key) {
            self::EVENT_INTERNAL => __('Internal event', 'planner'),
            self::EVENT_TRAVEL   => __('Travel', 'planner'),
            self::EVENT_MEETING  => __('Meeting', 'planner'),
            default => null,
        };
    }
}
