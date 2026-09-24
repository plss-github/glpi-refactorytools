<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Monta os dados das telas e delega a renderização ao Twig do core.
 *
 * Fica separado dos arquivos de `front/` porque as duas telas precisam do
 * mesmo recorte de dados (quem eu posso ver, com que nível e por quê) e
 * porque assim a lógica de apresentação fica testável sem passar por uma
 * requisição HTTP.
 */

namespace GlpiPlugin\Refactorytools;

use Glpi\Application\View\TemplateRenderer;
use Session;
use User;

final class View
{
    /**
     * Tela principal: a agenda.
     */
    public static function showRefactoryTools(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $me = (int) Session::getLoginUserID();

        // Grupo escolhido no seletor da barra lateral, só relevante quando o
        // observador está em mais de um (ver `AccessPolicy::getGroupColleagues()`).
        // Validado contra os grupos de quem está logado — um id de grupo que
        // não é dele é tratado como se não tivesse escolhido nenhum.
        $group_choices = AccessPolicy::getGroupChoices($me);
        $group_id      = (int) ($_GET['group_id'] ?? 0);
        if ($group_id <= 0 || !isset($group_choices[$group_id])) {
            $group_id = null;
        }

        TemplateRenderer::getInstance()->display('@refactorytools/refactorytools.html.twig', [
            'root_doc'       => $CFG_GLPI['root_doc'],
            'actor_groups'   => self::getActorGroups($me, $group_id),
            'types'          => EventProvider::getAvailableTypes($me),
            'can_pick_any'   => Right::has(Right::READ_ALL),
            // Exibido como aviso na barra lateral: sem isso, quem tem
            // READ_ALL vê "todas as agendas" e não entende de onde vem o
            // acesso — foi a primeira dúvida levantada ao usar a tela.
            'reads_all'      => Right::has(Right::READ_ALL),
            'can_share'      => Right::has(Right::SHARE_OWN),
            'can_request'    => Right::has(Right::REQUEST_ACCESS),
            'default_mode'   => Settings::get('default_mode'),
            'pending_count'  => self::countPendingForOwner($me),
            'auto_load_team' => Settings::isTrue('auto_load_team'),
            'group_choices'  => $group_choices,
            'selected_group_id' => $group_id,
            // Nome mantido por compatibilidade com o CSS/JS existente
            // (`#refactorytools-group-manager-toggle`) — o gate deixou de
            // ser "é gerente de algum grupo" (removido, redundante com
            // `READ_TEAM`) e passou a ser simplesmente "tem colegas de grupo
            // visíveis agora", ver `AccessPolicy::getGroupColleagues()`.
            'can_group_manager_mode' => Right::has(Right::READ_GROUP)
                && AccessPolicy::getGroupColleagues($me, $group_id) !== [],
            'technician_panel' => TechnicianStats::getPanelForUser($me),
            'kanban_state_order' => KanbanPrefs::getStateOrder($me),
            'me'             => $me,
            'today'          => date('Y-m-d'),
            'level_busy'     => Settings::LEVEL_BUSY,
            'creatable_kinds'    => self::getCreatableKinds(),
            // Para o seletor "Reserva vinculada" do modal de Visita (ver
            // `VisitLink`) — sempre calculado, mesmo para quem não vai criar
            // uma Visita: é uma consulta pequena (só as reservas da própria
            // pessoa), mais simples que buscar sob demanda via ajax.
            'my_reservation_choices' => VisitLink::getMyReservationChoices(),
            'csrf'           => Session::getNewCSRFToken(),
        ]);
    }

    /**
     * Tela de compartilhamentos: quem vê minha agenda, quais agendas eu vejo,
     * e os pedidos aguardando minha decisão.
     */
    public static function showShares(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $me = (int) Session::getLoginUserID();

        $as_owner   = Share::getForOwner($me);
        $as_grantee = Share::getForGrantee($me);

        TemplateRenderer::getInstance()->display('@refactorytools/shares.html.twig', [
            'root_doc'    => $CFG_GLPI['root_doc'],
            'pending'     => self::decorate(array_filter(
                $as_owner,
                static fn(array $r) => $r['status'] === Share::STATUS_PENDING
            ), 'users_id_grantee'),
            'granted'     => self::decorate(array_filter(
                $as_owner,
                static fn(array $r) => $r['status'] !== Share::STATUS_PENDING
            ), 'users_id_grantee'),
            'received'    => self::decorate($as_grantee, 'users_id_owner'),
            'status_labels' => Share::getStatusLabels(),
            'level_labels'  => Share::getLevelLabels(),
            'can_share'     => Right::has(Right::SHARE_OWN),
            'can_request'   => Right::has(Right::REQUEST_ACCESS)
                               && Settings::isTrue('allow_self_request'),
            'default_level' => Settings::get('default_share_level'),
            'csrf'          => Session::getNewCSRFToken(),
        ]);
    }

    /**
     * Agendas visíveis, agrupadas pela origem do acesso, para a barra lateral.
     *
     * A ordem dos grupos é a da precedência do modelo de acesso: eu, minha
     * equipe, meus grupos, compartilhadas comigo. Um grupo vazio não é
     * devolvido — a barra lateral não mostra seções vazias.
     *
     * @return array<int, array{reason: string, label: string, actors: array<int, array<string, mixed>>}>
     */
    public static function getActorGroups(int $users_id, ?int $group_id = null): array
    {
        $visible = AccessPolicy::getVisibleUsers($users_id, $group_id);

        $order = [
            AccessPolicy::REASON_SELF,
            AccessPolicy::REASON_TEAM,
            AccessPolicy::REASON_GROUP,
            AccessPolicy::REASON_SHARE,
        ];

        $buckets = array_fill_keys($order, []);

        // Uma consulta só para todos os usuários visíveis, não uma por
        // pessoa: com equipe, grupo e compartilhamentos abertos ao mesmo
        // tempo, `describeActor()` linha a linha já chegou a rodar dezenas
        // de consultas na mesma barra lateral.
        $rows_by_id = self::getUserRows(array_map('intval', array_keys($visible)));

        foreach ($visible as $actor_id => $access) {
            $reason = $access['reason'];
            if (!isset($buckets[$reason])) {
                $buckets[$reason] = [];
            }
            $buckets[$reason][] = self::describeActor(
                (int) $actor_id,
                $access['level'],
                $reason,
                EventProvider::getActorColor((int) $actor_id),
                $rows_by_id[(int) $actor_id] ?? null
            );
        }

        $out = [];
        foreach ($order as $reason) {
            if ($buckets[$reason] === []) {
                continue;
            }

            usort(
                $buckets[$reason],
                static fn(array $a, array $b) => strcasecmp((string) $a['name'], (string) $b['name'])
            );

            $out[] = [
                'reason' => $reason,
                'label'  => AccessPolicy::getReasonLabel($reason),
                'actors' => $buckets[$reason],
            ];
        }

        return $out;
    }

    /**
     * Linhas de `glpi_users` para uma lista de ids, numa consulta só —
     * usado por `getActorGroups()` para não repetir `getFromDB()` por
     * pessoa.
     *
     * @param array<int, int> $users_ids
     * @return array<int, array<string, mixed>>
     */
    private static function getUserRows(array $users_ids): array
    {
        $users_ids = array_values(array_unique(array_filter($users_ids, static fn($id) => $id > 0)));
        if ($users_ids === []) {
            return [];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request([
            'FROM'  => User::getTable(),
            'WHERE' => ['id' => $users_ids],
        ]) as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $row linha já buscada de `glpi_users`
     *                                        (ver `getUserRows()`), ou null
     *                                        se a pessoa não existe mais
     * @return array<string, mixed>
     */
    private static function describeActor(int $users_id, string $level, string $reason, string $color, ?array $row): array
    {
        $name    = sprintf(__('User #%d', 'refactorytools'), $users_id);
        $picture = null;

        if ($row !== null) {
            // `getFriendlyName()` só lê `$this->fields` (formatação de nome
            // pela configuração ativa) — preenchê-los à mão a partir da linha
            // já buscada reaproveita a mesma lógica do core sem um
            // `getFromDB()` extra por pessoa.
            $user         = new User();
            $user->fields = $row;
            $name         = $user->getFriendlyName();
            $picture      = $row['picture'] ?? null;
        }

        return [
            'id'       => $users_id,
            'name'     => $name,
            'initials' => self::getInitials($name),
            'picture'  => !empty($picture) ? User::getThumbnailURLForPicture($picture) : null,
            'level'    => $level,
            'reason'   => $reason,
            'color'    => $color,
        ];
    }

    /**
     * Acrescenta o nome da contraparte a cada linha de compartilhamento — o
     * Twig não deve ir ao banco buscar usuário linha a linha.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function decorate(array $rows, string $other_field): array
    {
        $out = [];
        foreach ($rows as $row) {
            $other_id      = (int) $row[$other_field];
            $row['other_id']   = $other_id;
            $row['other_name'] = EventProvider::getUserName($other_id)
                ?: sprintf(__('User #%d', 'refactorytools'), $other_id);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Tipos que o "+ Novo compromisso" oferece de verdade, para ESTE
     * usuário: nem todo mundo tem o direito de criar as cinco variantes
     * (Lembrete usa `Reminder::canCreate()`, que aceita o direito PESSOAL, tão
     * comum quanto o de qualquer usuário mexer na própria agenda; os quatro
     * eventos usam `PlanningExternalEvent::canCreate()`, um direito à parte).
     * Uma lista vazia significa "não pode criar nada por aqui" — o botão
     * inteiro desaparece nesse caso (ver `can_create_event` no template).
     *
     * @return array<int, array{key: string, label: string, icon: string}>
     */
    private static function getCreatableKinds(): array
    {
        $out = [];

        foreach (EventTypes::CREATABLE as $key) {
            $itemtype = EventTypes::realItemtype($key);

            if ($itemtype::canCreate()) {
                $out[] = [
                    'key'   => $key,
                    'label' => EventTypes::getLabel($key),
                    'icon'  => EventTypes::getIcon($key),
                ];
            }
        }

        return $out;
    }

    private static function countPendingForOwner(int $users_id): int
    {
        return count(array_filter(
            Share::getForOwner($users_id),
            static fn(array $r) => $r['status'] === Share::STATUS_PENDING
        ));
    }

    /**
     * Iniciais para o avatar de quem não tem foto. Duas letras no máximo:
     * "Ana Maria Souza" vira "AS", não "AMS".
     */
    private static function getInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        if ($parts === []) {
            return '?';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
}
