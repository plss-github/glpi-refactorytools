<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Quem pode ver a agenda de quem. Ponto único de decisão do plugin.
 *
 * Existe porque o core não tem um: no GLPI 11 a permissão de ver a agenda de
 * outra pessoa é aplicada uma única vez, ao ADICIONAR a pessoa ao painel
 * (`Planning::showAddUserForm()` restringe o dropdown conforme
 * `Planning::READALL` / `READGROUP`). Depois disso a lista de agendas fica
 * gravada em `glpi_users.plannings` e `Planning::constructEventsArray()` a
 * percorre sem reconferir nada — conferido lendo o fonte da 11.0.9. Ou seja:
 * uma linha que entrou no painel quando o usuário tinha o direito continua
 * rendendo eventos depois que o direito é retirado.
 *
 * Aqui a decisão é recalculada a cada requisição de eventos, a partir do
 * estado atual (perfil, supervisor, grupos, compartilhamentos). Nada que o
 * cliente mande — nem a lista de agendas selecionadas — amplia o que ele vê.
 *
 * Quatro origens de acesso, todas cumulativas:
 *
 *   self    sempre, para a própria agenda.
 *   all     direito READ_ALL no perfil (papel administrativo).
 *   team    direito READ_TEAM + `glpi_users.users_id_supervisor` apontando
 *           para o observador. Opcionalmente toda a árvore abaixo.
 *   group   direito READ_GROUP + "sou do mesmo grupo" — sem distinguir
 *           gerente de membro comum (esse papel já é coberto por READ_TEAM,
 *           via responsável direto). Quando o observador está em mais de um
 *           grupo, a tela pede que ele escolha QUAL antes de mostrar
 *           colegas — ver `getGroupColleagues()` e o seletor de grupo na
 *           barra lateral.
 *   share   linha aceita e vigente em Share, independente de direito de
 *           leitura — é o dono da agenda quem autorizou.
 *
 * Quando mais de uma origem se aplica, vale a mais permissiva (details > busy).
 */

namespace GlpiPlugin\Refactorytools;

use Group;
use Group_User;
use Session;
use User;

final class AccessPolicy
{
    public const REASON_SELF  = 'self';
    public const REASON_ALL   = 'all';
    public const REASON_TEAM  = 'team';
    public const REASON_GROUP = 'group';
    public const REASON_SHARE = 'share';

    /**
     * Teto de profundidade ao subir a árvore de liderança com
     * `team_recursive` ligado. Guarda contra ciclos em dados ruins (A chefe de
     * B, B chefe de A) e contra hierarquias absurdamente profundas — o
     * conjunto `$seen` já quebra o ciclo, este limite evita o custo.
     */
    private const MAX_TEAM_DEPTH = 10;

    /**
     * Agendas que o observador pode abrir, prontas para a barra lateral.
     *
     * Não inclui "todo mundo" quando o observador tem READ_ALL: seriam todos
     * os usuários da base. Nesse caso a tela oferece um seletor de usuário, e
     * a autorização de cada um é resolvida por getLevelFor().
     *
     * ATENÇÃO ao `$viewer_id`: os DIREITOS consultados aqui (`Right::has()`)
     * são sempre os do usuário da SESSÃO, porque é assim que o GLPI guarda o
     * perfil ativo. Passar o id de outra pessoa muda de quem são a equipe, os
     * grupos e os compartilhamentos, mas NÃO de quem são os direitos — o
     * resultado seria um híbrido sem sentido. Por isso o parâmetro só deve
     * receber o próprio usuário logado; ele existe para tornar a dependência
     * explícita e testável, não para simular outra pessoa.
     *
     * @param int|null $group_id grupo escolhido no seletor da barra lateral
     *        (ver `getGroupColleagues()`) — irrelevante quando o observador
     *        está em 0 ou 1 grupo.
     * @return array<int, array{level: string, reason: string}> users_id => acesso
     */
    public static function getVisibleUsers(?int $viewer_id = null, ?int $group_id = null): array
    {
        $viewer_id ??= (int) Session::getLoginUserID();
        if ($viewer_id <= 0) {
            return [];
        }

        $found = [];

        $found[$viewer_id] = ['level' => Settings::LEVEL_DETAILS, 'reason' => self::REASON_SELF];

        if (Right::has(Right::READ_TEAM)) {
            $level = self::normalizeLevel(Settings::get('team_level'));
            foreach (self::getTeamMembers($viewer_id) as $users_id) {
                self::keepBest($found, $users_id, $level, self::REASON_TEAM);
            }
        }

        if (Right::has(Right::READ_GROUP)) {
            $level = self::normalizeLevel(Settings::get('group_level'));
            foreach (self::getGroupColleagues($viewer_id, $group_id) as $users_id) {
                self::keepBest($found, $users_id, $level, self::REASON_GROUP);
            }
        }

        foreach (Share::getAccessibleOwners($viewer_id) as $users_id => $level) {
            self::keepBest($found, (int) $users_id, self::normalizeLevel($level), self::REASON_SHARE);
        }

        unset($found[0]);

        return $found;
    }

    /**
     * Nível de acesso do observador à agenda de $target, ou null se nenhum.
     *
     * Esta é a função que os endpoints de evento devem chamar. Ela cobre o
     * caso READ_ALL, que getVisibleUsers() deliberadamente não enumera.
     */
    public static function getLevelFor(int $target, ?int $viewer_id = null): ?string
    {
        $viewer_id ??= (int) Session::getLoginUserID();

        if ($target <= 0 || $viewer_id <= 0) {
            return null;
        }
        if (!Right::canUse()) {
            return null;
        }
        if ($target === $viewer_id) {
            return Settings::LEVEL_DETAILS;
        }
        if (Right::has(Right::READ_ALL)) {
            return Settings::LEVEL_DETAILS;
        }

        $visible = self::getVisibleUsers($viewer_id);

        return $visible[$target]['level'] ?? null;
    }

    public static function canView(int $target, ?int $viewer_id = null): bool
    {
        return self::getLevelFor($target, $viewer_id) !== null;
    }

    /**
     * Se o observador pode escrever a nota num compromisso de $owner_id (dono
     * da agenda onde o compromisso aparece).
     *
     * A própria agenda é sempre permitida: a nota também serve como
     * lembrete/instrução pessoal ("nota de instrução"), não só como recado de
     * um gestor — sem isso o popover de um compromisso próprio ficava
     * meramente informativo, sem nada para interagir.
     *
     * Para a agenda de OUTRA pessoa, é mais estrito que "pode ver a agenda":
     * um colega do mesmo grupo (REASON_GROUP) ou alguém que recebeu
     * compartilhamento (REASON_SHARE) não é gestor de ninguém, só tem visão.
     * Só quem realmente ocupa uma posição de liderança sobre o dono —
     * responsável direto (REASON_TEAM) ou acesso administrativo — pode
     * deixar um recado que o dono vê destacado.
     */
    public static function canManageNoteFor(int $owner_id, ?int $viewer_id = null): bool
    {
        $viewer_id ??= (int) Session::getLoginUserID();

        if ($owner_id <= 0 || $viewer_id <= 0) {
            return false;
        }
        if (!Right::canUse()) {
            return false;
        }
        if ($owner_id === $viewer_id) {
            return true;
        }
        if (Right::has(Right::READ_ALL)) {
            return true;
        }

        $reason = self::getVisibleUsers($viewer_id)[$owner_id]['reason'] ?? null;

        return $reason === self::REASON_TEAM;
    }

    /**
     * Filtra uma lista de ids vinda do cliente, devolvendo só os autorizados
     * com o nível de cada um. Usada pelos endpoints para nunca confiar no
     * que chega na requisição.
     *
     * @param array<int, int|string> $requested
     * @param int|null $group_id grupo escolhido no seletor da barra lateral
     *        — precisa ser o MESMO que gerou os checkboxes marcados em
     *        `$requested`, senão um colega de grupo pedido cai fora do mapa
     *        e é descartado como qualquer id não autorizado.
     * @return array<int, string> users_id => nível
     */
    public static function filterRequested(array $requested, ?int $viewer_id = null, ?int $group_id = null): array
    {
        $viewer_id ??= (int) Session::getLoginUserID();

        if ($viewer_id <= 0 || !Right::canUse()) {
            return [];
        }

        // O mapa de agendas visíveis é calculado UMA vez e reaproveitado para
        // todos os ids pedidos. Resolver id a id chamaria getVisibleUsers()
        // uma vez por agenda marcada, e cada chamada refaz a leitura de
        // configuração, a árvore de liderança, os grupos e os
        // compartilhamentos — com oito agendas abertas eram oito vezes o mesmo
        // trabalho, a cada navegação no calendário.
        $visible   = self::getVisibleUsers($viewer_id, $group_id);
        $reads_all = Right::has(Right::READ_ALL);

        $out = [];
        foreach (array_unique(array_map('intval', $requested)) as $users_id) {
            if ($users_id <= 0) {
                continue;
            }

            if ($users_id === $viewer_id || $reads_all) {
                $out[$users_id] = Settings::LEVEL_DETAILS;
                continue;
            }

            if (isset($visible[$users_id])) {
                $out[$users_id] = $visible[$users_id]['level'];
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Origens
    // -----------------------------------------------------------------------

    /**
     * Usuários que têm $supervisor_id no campo "Responsável"
     * (`glpi_users.users_id_supervisor`), ativos e não excluídos.
     *
     * Com `team_recursive` ligado, desce a árvore inteira abaixo do
     * observador; senão, só os liderados diretos.
     *
     * @return array<int, int>
     */
    public static function getTeamMembers(int $supervisor_id): array
    {
        if (!self::supervisorFieldExists()) {
            return [];
        }

        $recursive = Settings::isTrue('team_recursive');

        $seen    = [$supervisor_id => true];
        $result  = [];
        $current = [$supervisor_id];
        $depth   = 0;

        do {
            $children = self::getDirectReports($current);
            $children = array_values(array_filter($children, static fn($id) => !isset($seen[$id])));

            foreach ($children as $id) {
                $seen[$id] = true;
                $result[]  = $id;
            }

            $current = $children;
            $depth++;
        } while ($recursive && $children !== [] && $depth < self::MAX_TEAM_DEPTH);

        return $result;
    }

    /**
     * @param array<int, int> $supervisor_ids
     * @return array<int, int>
     */
    private static function getDirectReports(array $supervisor_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($supervisor_ids === []) {
            return [];
        }

        $rows = $DB->request([
            'SELECT' => 'id',
            'FROM'   => User::getTable(),
            'WHERE'  => [
                'users_id_supervisor' => $supervisor_ids,
                'is_active'           => 1,
                'is_deleted'          => 0,
            ],
        ]);

        $out = [];
        foreach ($rows as $row) {
            $out[] = (int) $row['id'];
        }

        return $out;
    }

    /**
     * Ids dos grupos do observador. Usa os grupos da sessão quando o
     * observador é o usuário logado (já carregados pelo core, evita uma
     * consulta), e consulta o banco quando é outro usuário — o que acontece
     * nas checagens feitas fora de uma sessão interativa.
     *
     * @return array<int, int>
     */
    private static function getViewerGroupIds(int $users_id): array
    {
        $is_current = $users_id === (int) Session::getLoginUserID();

        if ($is_current && isset($_SESSION['glpigroups']) && is_array($_SESSION['glpigroups'])) {
            return array_map('intval', $_SESSION['glpigroups']);
        }

        return array_map(
            static fn(array $g) => (int) $g['id'],
            Group_User::getUserGroups($users_id)
        );
    }

    /**
     * Grupos do observador, para o seletor da barra lateral — só quando ele
     * está em MAIS de um (com 0 ou 1, não há o que escolher).
     *
     * @return array<int, string> group_id => nome
     */
    public static function getGroupChoices(int $users_id): array
    {
        $ids = self::getViewerGroupIds($users_id);
        if (count($ids) < 2) {
            return [];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => Group::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Colegas do grupo do observador.
     *
     * Sem distinguir gerente de membro comum: esse papel já é coberto por
     * `getTeamMembers()` (responsável direto). Quando o observador está em
     * MAIS de um grupo, exige um `$selected_group_id` válido — sem isso
     * (ainda não escolheu, ou mandou um grupo que não é dele) devolve vazio
     * em vez de misturar colegas de todos os grupos numa lista só.
     *
     * @return array<int, int>
     */
    public static function getGroupColleagues(int $users_id, ?int $selected_group_id = null): array
    {
        $groups = self::getViewerGroupIds($users_id);

        if ($groups === []) {
            return [];
        }

        if (count($groups) > 1) {
            if ($selected_group_id === null || !in_array($selected_group_id, $groups, true)) {
                return [];
            }
            $groups = [$selected_group_id];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $rows = $DB->request([
            'SELECT'    => 'glpi_users.id',
            'DISTINCT'  => true,
            'FROM'      => Group_User::getTable(),
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_users'            => 'id',
                        Group_User::getTable()  => 'users_id',
                    ],
                ],
            ],
            'WHERE' => [
                Group_User::getTable() . '.groups_id' => $groups,
                'glpi_users.is_active'                => 1,
                'glpi_users.is_deleted'               => 0,
            ],
        ]);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($id !== $users_id) {
                $out[] = $id;
            }
        }

        return $out;
    }


    // -----------------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------------

    /**
     * Checado na ativação do plugin (ver plugin_refactorytools_check_config): sem
     * este campo, o direito "ver a agenda da minha equipe" não tem de onde
     * derivar a equipe.
     */
    public static function supervisorFieldExists(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return $DB->fieldExists(User::getTable(), 'users_id_supervisor');
    }

    /**
     * Um valor de nível desconhecido (config editada à mão, linha de uma
     * versão futura) cai para o mais restritivo, nunca para o mais permissivo.
     */
    private static function normalizeLevel(string $level): string
    {
        return $level === Settings::LEVEL_DETAILS ? Settings::LEVEL_DETAILS : Settings::LEVEL_BUSY;
    }

    /**
     * @param array<int, array{level: string, reason: string}> $found
     */
    private static function keepBest(array &$found, int $users_id, string $level, string $reason): void
    {
        if ($users_id <= 0) {
            return;
        }

        $existing = $found[$users_id] ?? null;

        if ($existing === null) {
            $found[$users_id] = ['level' => $level, 'reason' => $reason];
            return;
        }

        // details ganha de busy; empatou, mantém a primeira origem encontrada
        // (a ordem em getVisibleUsers vai da mais forte para a mais fraca).
        if ($existing['level'] === Settings::LEVEL_BUSY && $level === Settings::LEVEL_DETAILS) {
            $found[$users_id] = ['level' => $level, 'reason' => $reason];
        }
    }

    /**
     * Rótulo da origem do acesso, exibido junto do nome na barra lateral para
     * o observador entender por que enxerga aquela agenda.
     */
    public static function getReasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_SELF  => __('My schedule', 'refactorytools'),
            self::REASON_ALL   => __('Administrative access', 'refactorytools'),
            self::REASON_TEAM  => __('My team', 'refactorytools'),
            self::REASON_GROUP => __('My group', 'refactorytools'),
            self::REASON_SHARE => __('Shared with me', 'refactorytools'),
            default            => '',
        };
    }
}
