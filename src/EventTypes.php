<?php

/**
 * RefactoryTools
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

namespace GlpiPlugin\Refactorytools;

use PlanningExternalEvent;

final class EventTypes
{
    public const TICKET_TASK  = 'TicketTask';
    public const CHANGE_TASK  = 'ChangeTask';
    public const PROBLEM_TASK = 'ProblemTask';
    public const PROJECT_TASK = 'ProjectTask';
    public const RESERVATION  = 'Reservation';
    public const REMINDER     = 'Reminder';

    /** Evento externo "puro": sem categoria, ou com uma categoria que não é nenhuma das quatro abaixo. */
    public const EVENT_EXTERNAL = 'PlanningExternalEvent';
    public const EVENT_INTERNAL = 'PlanningExternalEvent:internal';
    public const EVENT_TRAVEL   = 'PlanningExternalEvent:travel';
    public const EVENT_MEETING  = 'PlanningExternalEvent:meeting';
    /** Visita e Viagem podem se ligar a uma Reserva (ver `EventTypes::LINKABLE_WITH_RESERVATION`, `VisitLink`). */
    public const EVENT_VISIT    = 'PlanningExternalEvent:visit';

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
        self::EVENT_VISIT,
    ];

    /**
     * Podem se ligar a uma Reserva, nos dois sentidos: linkar uma já
     * existente, ou criar uma nova reserva junto (ver `VisitLink`,
     * `ajax/create_event.php`, `ajax/create_reservation.php`).
     *
     * @var array<int, string>
     */
    public const LINKABLE_WITH_RESERVATION = [
        self::EVENT_VISIT,
        self::EVENT_TRAVEL,
    ];

    /**
     * Podem ser compartilhados com outros usuários — o compromisso passa a
     * aparecer na agenda deles também (ver `MeetingGuest`, campo nativo
     * `users_id_guests`).
     *
     * @var array<int, string>
     */
    public const SHAREABLE = [
        self::EVENT_MEETING,
        self::EVENT_TRAVEL,
        self::EVENT_VISIT,
    ];

    /**
     * @return array<int, string> todas as chaves virtuais, na ordem em que
     *                            devem aparecer na interface
     */
    public static function getAll(): array
    {
        return [
            // Chamado, Mudança e Problema ficaram de fora da lista de tipos
            // (filtro da barra lateral + legenda): a informação equivalente
            // já existe no painel "Meus chamados como técnico"
            // (TechnicianStats), que não depende desta lista — continua
            // mostrando normalmente.
            self::PROJECT_TASK,
            self::RESERVATION,
            self::EVENT_EXTERNAL,
            self::EVENT_INTERNAL,
            self::EVENT_TRAVEL,
            self::EVENT_MEETING,
            self::EVENT_VISIT,
            self::REMINDER,
        ];
    }

    public static function getLabel(string $key): string
    {
        return match ($key) {
            self::TICKET_TASK  => __('Ticket', 'refactorytools'),
            self::CHANGE_TASK  => __('Change', 'refactorytools'),
            self::PROBLEM_TASK => __('Problem', 'refactorytools'),
            self::PROJECT_TASK => __('Project', 'refactorytools'),
            self::RESERVATION  => __('Reservation', 'refactorytools'),
            self::REMINDER     => __('Reminder', 'refactorytools'),
            self::EVENT_EXTERNAL => __('External event', 'refactorytools'),
            self::EVENT_INTERNAL => __('Internal event', 'refactorytools'),
            self::EVENT_TRAVEL   => __('Travel', 'refactorytools'),
            self::EVENT_MEETING  => __('Meeting', 'refactorytools'),
            self::EVENT_VISIT    => __('Visit', 'refactorytools'),
            default => $key,
        };
    }

    public static function getIcon(string $key): string
    {
        return match ($key) {
            self::TICKET_TASK  => 'ti ti-headset',
            self::CHANGE_TASK  => 'ti ti-replace',
            self::PROBLEM_TASK => 'ti ti-alert-triangle',
            self::PROJECT_TASK => 'ti ti-briefcase',
            self::RESERVATION  => 'ti ti-calendar-time',
            self::REMINDER     => 'ti ti-note',
            self::EVENT_EXTERNAL => 'ti ti-calendar-event',
            self::EVENT_INTERNAL => 'ti ti-building',
            self::EVENT_TRAVEL   => 'ti ti-plane',
            self::EVENT_MEETING  => 'ti ti-users',
            self::EVENT_VISIT    => 'ti ti-door-enter',
            default => 'ti ti-calendar',
        };
    }

    /**
     * Paleta de fábrica. Nove tons espalhados pela roda de cor (mais o cinza
     * neutro do Lembrete), escolhidos por matiz — não só "parecem
     * diferentes" lado a lado, tinham matizes vizinhos que colidiam de
     * relance num calendário cheio (Mudança e Evento Interno, dois roxos;
     * Problema e Viagem, dois laranjas; Projeto e Reunião, dois
     * verde-água). Chamado volta a ser vermelho, como era antes da
     * reorganização anterior — é o tipo mais comum na tela, e "vermelho =
     * chamado" já tinha virado a associação de quem usa.
     */
    public static function getDefaultColor(string $key): string
    {
        return match ($key) {
            self::TICKET_TASK    => '#dc2626', // vermelho
            self::PROBLEM_TASK   => '#f97316', // laranja
            self::EVENT_TRAVEL   => '#ca8a04', // âmbar
            self::EVENT_MEETING  => '#16a34a', // verde
            self::PROJECT_TASK   => '#0d9488', // verde-água
            self::RESERVATION    => '#0891b2', // ciano
            self::EVENT_INTERNAL => '#2563eb', // azul
            self::CHANGE_TASK    => '#7c3aed', // roxo
            self::EVENT_EXTERNAL => '#db2777', // rosa
            self::REMINDER       => '#64748b', // cinza neutro
            self::EVENT_VISIT    => '#be185d', // magenta
            default => '#64748b',
        };
    }

    public static function isExternalEventVariant(string $key): bool
    {
        return $key === self::EVENT_EXTERNAL
            || $key === self::EVENT_INTERNAL
            || $key === self::EVENT_TRAVEL
            || $key === self::EVENT_MEETING
            || $key === self::EVENT_VISIT;
    }

    /**
     * Itemtype REAL por trás de uma chave virtual. É o que vale para
     * permissão, consulta e link de edição — a chave virtual só existe para
     * filtro e cor.
     */
    public static function realItemtype(string $key): string
    {
        return self::isExternalEventVariant($key) ? PlanningExternalEvent::class : $key;
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
            self::EVENT_VISIT    => 'category_visit_id',
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
            self::EVENT_INTERNAL => __('Internal event', 'refactorytools'),
            self::EVENT_TRAVEL   => __('Travel', 'refactorytools'),
            self::EVENT_MEETING  => __('Meeting', 'refactorytools'),
            self::EVENT_VISIT    => __('Visit', 'refactorytools'),
            default => null,
        };
    }
}
