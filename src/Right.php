<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Direito único do plugin e seus bits.
 *
 * Por que um direito próprio em vez de reaproveitar o `planning` nativo:
 * o direito nativo tem só três níveis (READMY=1, READGROUP=1024, READALL=2048)
 * e é consumido pelo core em vários pontos (ical, central, disponibilidade).
 * Acrescentar bits nele mudaria o significado de um direito do core para
 * quem lê a matriz de perfis — e um `uninstall` do plugin deixaria bits
 * órfãos num direito que não é nosso. Um direito próprio é isolado e
 * removível.
 *
 * Os valores 1 / 1024 / 2048 são deliberadamente os mesmos de
 * `Planning::READMY` / `READGROUP` / `READALL`: quem já conhece a matriz
 * nativa lê esta com o mesmo vocabulário. Os bits novos (4096+) continuam
 * a sequência sem colidir com os direitos CRUD padrão do GLPI
 * (READ=1, UPDATE=2, CREATE=4, DELETE=8, PURGE=32…), que este plugin não usa.
 */

namespace GlpiPlugin\Refactorytools;

use Session;

final class Right
{
    /** Nome do direito na tabela glpi_profilerights. */
    public const NAME = 'plugin_refactorytools_planning';

    /** Abrir o RefactoryTools e ver a própria agenda. */
    public const USE_REFACTORYTOOLS = 1;

    /** Ver a agenda de quem está nos mesmos grupos que eu. */
    public const READ_GROUP = 1024;

    /** Ver a agenda de qualquer usuário (perfil administrativo). */
    public const READ_ALL = 2048;

    /** Ver a agenda de quem me tem como responsável (`users_id_supervisor`). */
    public const READ_TEAM = 4096;

    /** Compartilhar a própria agenda e responder pedidos recebidos. */
    public const SHARE_OWN = 8192;

    /** Pedir acesso à agenda de outra pessoa (depende do aceite dela). */
    public const REQUEST_ACCESS = 16384;

    /**
     * Ativar o "modo gerente de grupo" em Minha Agenda quando o usuário é
     * `is_manager=1` de pelo menos um grupo (ver `glpi_groups_users`).
     *
     * Bit próprio em vez de automático: mesmo quem é gerente de grupo no
     * GLPI pode não dever enxergar a agenda do grupo pelo RefactoryTools — quem
     * administra o plugin decide isso na matriz de perfis, como qualquer
     * outro nível de visibilidade aqui.
     */
    public const READ_MANAGED_GROUP = 32768;

    /**
     * Rótulos exibidos na matriz de direitos do perfil.
     *
     * @return array<int, string>
     */
    public static function getAll(): array
    {
        return [
            self::USE_REFACTORYTOOLS       => __('Use Pellissari RefactoryTools', 'refactorytools'),
            self::READ_TEAM         => __('See my team schedules', 'refactorytools'),
            self::READ_GROUP        => __('See my group schedules', 'refactorytools'),
            self::READ_MANAGED_GROUP => __('See the schedules of groups I manage', 'refactorytools'),
            self::READ_ALL          => __('See all schedules', 'refactorytools'),
            self::SHARE_OWN         => __('Share own schedule', 'refactorytools'),
            self::REQUEST_ACCESS    => __('Request access to a schedule', 'refactorytools'),
        ];
    }

    /**
     * Conjunto concedido ao(s) perfil(is) Super-Admin na instalação, para que
     * quem instalou o plugin consiga usá-lo sem passar antes por
     * Administração > Perfis.
     */
    public static function all(): int
    {
        return self::USE_REFACTORYTOOLS
            | self::READ_TEAM
            | self::READ_GROUP
            | self::READ_MANAGED_GROUP
            | self::READ_ALL
            | self::SHARE_OWN
            | self::REQUEST_ACCESS;
    }

    public static function has(int $right): bool
    {
        return Session::haveRight(self::NAME, $right);
    }

    /** Porta de entrada: sem isto, nenhuma tela do plugin abre. */
    public static function canUse(): bool
    {
        return self::has(self::USE_REFACTORYTOOLS);
    }
}
