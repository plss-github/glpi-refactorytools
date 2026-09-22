<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Compartilhamento de agenda entre dois usuários.
 * Tabela: glpi_plugin_planner_shares
 *
 * Modela as duas direções de um mesmo acordo:
 *
 *  - `origin = grant`   — o dono da agenda concedeu acesso a alguém. Nasce já
 *                         `accepted`: ninguém precisa consentir em receber.
 *  - `origin = request` — alguém pediu acesso à agenda do dono. Nasce
 *                         `pending` e só vale depois que o dono aceita.
 *
 * É a peça que atende "desde que aceitem": nenhum evento de um terceiro é
 * exposto por esta via sem uma linha `accepted` cujo `users_id_owner` é o dono
 * da agenda. Quem decide é sempre o dono — o solicitante nunca muda o status
 * de um pedido para `accepted` (ver AccessPolicy e ajax/share.php).
 *
 * Não há coluna `entities_id`: um compartilhamento é um acordo entre duas
 * pessoas, e um usuário no GLPI não pertence a uma entidade única. O recorte
 * por entidade continua acontecendo nos eventos, que passam pelos filtros
 * nativos do core (ver EventProvider).
 */

namespace GlpiPlugin\Planner;

use CommonDBTM;
use Migration;
use Session;
use User;

class Share extends CommonDBTM
{
    public static $rightname = Right::NAME;

    /** Histórico na aba "Histórico" do item. */
    public $dohistory = true;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REFUSED  = 'refused';
    public const STATUS_REVOKED  = 'revoked';

    public const ORIGIN_GRANT   = 'grant';
    public const ORIGIN_REQUEST = 'request';

    public static function getTypeName($nb = 0)
    {
        return _n('Schedule share', 'Schedule shares', $nb, 'planner');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-share';
    }

    public static function canView(): bool
    {
        return Right::canUse();
    }

    public static function canCreate(): bool
    {
        return Right::has(Right::SHARE_OWN) || Right::has(Right::REQUEST_ACCESS);
    }

    /**
     * @return array<string, string>
     */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_PENDING  => __('Awaiting approval', 'planner'),
            self::STATUS_ACCEPTED => __('Active', 'planner'),
            self::STATUS_REFUSED  => __('Refused', 'planner'),
            self::STATUS_REVOKED  => __('Revoked', 'planner'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getLevelLabels(): array
    {
        return [
            Settings::LEVEL_DETAILS => __('Event details', 'planner'),
            Settings::LEVEL_BUSY    => __('Free/busy only', 'planner'),
        ];
    }

    // -----------------------------------------------------------------------
    // Consultas
    // -----------------------------------------------------------------------

    /**
     * Agendas que $users_id pode ver por compartilhamento aceito e vigente.
     *
     * @return array<int, string> users_id do dono => nível de detalhe
     */
    public static function getAccessibleOwners(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out  = [];
        $rows = $DB->request([
            'SELECT' => ['users_id_owner', 'level'],
            'FROM'   => self::getTable(),
            'WHERE'  => array_merge(
                [
                    'users_id_grantee' => $users_id,
                    'status'           => self::STATUS_ACCEPTED,
                ],
                self::getValidityCriteria()
            ),
        ]);

        foreach ($rows as $row) {
            $out[(int) $row['users_id_owner']] = (string) $row['level'];
        }

        return $out;
    }

    /**
     * Critério SQL de vigência: date_start/date_end são opcionais e uma linha
     * sem datas vale para sempre. Escrito como critério, e não filtrado em PHP,
     * porque getAccessibleOwners() roda em toda requisição de eventos.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getValidityCriteria(): array
    {
        $today = date('Y-m-d');

        return [
            [
                'OR' => [
                    ['date_start' => null],
                    ['date_start' => ['<=', $today]],
                ],
            ],
            [
                'OR' => [
                    ['date_end' => null],
                    ['date_end' => ['>=', $today]],
                ],
            ],
        ];
    }

    /**
     * Linhas em que $users_id é o dono da agenda (quem concede e decide).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getForOwner(int $users_id): array
    {
        return self::findBy(['users_id_owner' => $users_id]);
    }

    /**
     * Linhas em que $users_id é o destinatário do acesso.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getForGrantee(int $users_id): array
    {
        return self::findBy(['users_id_grantee' => $users_id]);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    private static function findBy(array $criteria): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $criteria, 'ORDER' => 'id DESC']) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Um par (dono, destinatário) tem no máximo uma linha — reabrir um acesso
     * revogado reaproveita a mesma linha em vez de acumular histórico morto.
     * A unicidade também está no índice da tabela.
     *
     * @return array<string, mixed>|null
     */
    public static function findPair(int $owner, int $grantee): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id_owner' => $owner, 'users_id_grantee' => $grantee],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            return $row;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Transições
    // -----------------------------------------------------------------------

    /**
     * O dono concede acesso à própria agenda. Já nasce ativo.
     */
    public static function grant(int $owner, int $grantee, string $level): bool
    {
        return self::upsert($owner, $grantee, $level, self::ORIGIN_GRANT, self::STATUS_ACCEPTED);
    }

    /**
     * Alguém pede acesso à agenda de $owner. Fica pendente até o dono decidir.
     */
    public static function request(int $owner, int $grantee, string $level): bool
    {
        return self::upsert($owner, $grantee, $level, self::ORIGIN_REQUEST, self::STATUS_PENDING);
    }

    private static function upsert(int $owner, int $grantee, string $level, string $origin, string $status): bool
    {
        if ($owner <= 0 || $grantee <= 0 || $owner === $grantee) {
            return false;
        }
        if (!array_key_exists($level, self::getLevelLabels())) {
            return false;
        }

        // Compartilhar com um usuário inexistente criaria uma linha que nunca
        // aparece em lugar nenhum e nunca é limpa.
        $user = new User();
        if (!$user->getFromDB($owner) || !$user->getFromDB($grantee)) {
            return false;
        }

        $share    = new self();
        $existing = self::findPair($owner, $grantee);

        if ($existing !== null) {
            return (bool) $share->update([
                'id'     => $existing['id'],
                'level'  => $level,
                'origin' => $origin,
                'status' => $status,
            ]);
        }

        return (bool) $share->add([
            'users_id_owner'   => $owner,
            'users_id_grantee' => $grantee,
            'level'            => $level,
            'origin'           => $origin,
            'status'           => $status,
        ]);
    }

    /**
     * Decisão do dono sobre um pedido, ou revogação de um acesso já ativo.
     *
     * A checagem de quem pode decidir está aqui, e não apenas no endpoint,
     * porque é a única garantia que não depende de lembrar de repeti-la em
     * cada chamador novo.
     */
    public static function decide(int $shares_id, string $new_status, ?int $actor_id = null): bool
    {
        $actor_id ??= (int) Session::getLoginUserID();

        $allowed = [self::STATUS_ACCEPTED, self::STATUS_REFUSED, self::STATUS_REVOKED];
        if (!in_array($new_status, $allowed, true)) {
            return false;
        }

        $share = new self();
        if (!$share->getFromDB($shares_id)) {
            return false;
        }

        $is_owner   = (int) $share->fields['users_id_owner'] === $actor_id;
        $is_grantee = (int) $share->fields['users_id_grantee'] === $actor_id;

        // O dono decide tudo sobre a própria agenda. O destinatário só pode
        // abrir mão de um acesso que recebeu — nunca conceder um a si mesmo.
        if (!$is_owner && !($is_grantee && $new_status === self::STATUS_REVOKED)) {
            return false;
        }

        return (bool) $share->update(['id' => $shares_id, 'status' => $new_status]);
    }

    public static function remove(int $shares_id, ?int $actor_id = null): bool
    {
        $actor_id ??= (int) Session::getLoginUserID();

        $share = new self();
        if (!$share->getFromDB($shares_id)) {
            return false;
        }

        if (
            (int) $share->fields['users_id_owner'] !== $actor_id
            && (int) $share->fields['users_id_grantee'] !== $actor_id
            && !Right::has(Right::READ_ALL)
        ) {
            return false;
        }

        return (bool) $share->delete(['id' => $shares_id], true);
    }

    // -----------------------------------------------------------------------
    // Instalação
    // -----------------------------------------------------------------------

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `users_id_owner` INT UNSIGNED NOT NULL DEFAULT 0,
                    `users_id_grantee` INT UNSIGNED NOT NULL DEFAULT 0,
                    `level` VARCHAR(20) NOT NULL DEFAULT 'details',
                    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                    `origin` VARCHAR(20) NOT NULL DEFAULT 'grant',
                    `date_start` DATE NULL DEFAULT NULL,
                    `date_end` DATE NULL DEFAULT NULL,
                    `comment` TEXT NULL,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`users_id_owner`, `users_id_grantee`),
                    KEY `users_id_grantee` (`users_id_grantee`),
                    KEY `status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public static function uninstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }
}
