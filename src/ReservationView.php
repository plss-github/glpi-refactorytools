<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * A tela de Reservas remodelada (Ferramentas > Reservas).
 *
 * Mesma ideia da agenda: três modos sobre os mesmos dados, período
 * compartilhado, barra lateral com o que exibir. O que muda é o eixo — no
 * planejamento cada faixa é uma PESSOA; aqui cada faixa é um ITEM RESERVÁVEL.
 * Foi por isso que valeu generalizar o JavaScript em vez de duplicá-lo: o
 * comportamento dos três modos é o mesmo, só o parâmetro que identifica a
 * faixa e o endpoint mudam.
 *
 * A tela nativa (`Reservation::showCalendar()`) mostra um calendário por vez,
 * de UM item, escolhido antes numa lista separada. Comparar dois recursos
 * exigia abrir duas abas. Aqui os itens são marcáveis lado a lado e a visão
 * "Por item" empilha todos no mesmo período.
 *
 * Visibilidade: exige o direito `reservation` (READ ou "fazer reserva") e usa
 * o recorte por entidade do core. Uma reserva não é privada — quem enxerga o
 * item enxerga quem o reservou, que é o ponto de uma agenda de recursos
 * compartilhados.
 */

namespace GlpiPlugin\Planner;

use Glpi\Application\View\TemplateRenderer;
use Profile;
use Reservation;
use ReservationItem;
use Session;

final class ReservationView
{
    /** Prefixo dos ids de faixa, casado com o `resourceId` dos eventos. */
    public const RESOURCE_PREFIX = 'resitem_';

    /**
     * Situação de uma reserva, derivada do tempo — reserva não tem campo de
     * estado no banco. Os valores reaproveitam os inteiros que o Kanban já
     * usa para as colunas, para não existir um segundo vocabulário de estado
     * no JavaScript.
     */
    public const STATE_UPCOMING = 0;
    public const STATE_ONGOING  = 1;
    public const STATE_FINISHED = 2;

    public static function canView(): bool
    {
        return ReservationProvider::canView();
    }

    /**
     * Quem pode gerenciar quais ativos são reserváveis.
     *
     * Restrito ao(s) perfil(is) Super-Admin por escolha explícita: marcar um
     * ativo como reservável muda o que a organização inteira enxerga nesta
     * tela, e não é uma tarefa de quem apenas usa as reservas. Não basta ter o
     * direito `reservation` — o técnico que reserva uma sala não deveria poder
     * transformar qualquer ativo em recurso compartilhado.
     */
    public static function canManageItems(): bool
    {
        $profile_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);

        return $profile_id > 0
            && in_array($profile_id, Profile::getSuperAdminProfilesId(), true);
    }

    /**
     * Pode criar reserva? É o direito do core, não do plugin.
     */
    public static function canReserve(): bool
    {
        return Session::haveRightsOr(
            Reservation::$rightname,
            [CREATE, ReservationItem::RESERVEANITEM]
        );
    }

    public static function show(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $items = self::getReservableItems();

        TemplateRenderer::getInstance()->display('@planner/reservations.html.twig', [
            'root_doc'     => $CFG_GLPI['root_doc'],
            // Tipos alimentam a barra lateral; a lista completa de itens
            // alimenta o seletor do formulário de nova reserva, onde a escolha
            // é de um aparelho específico.
            'types'        => self::getReservableTypes(),
            'items'        => $items,
            'items_count'  => count($items),
            'can_reserve'  => self::canReserve(),
            'can_manage'   => self::canManageItems(),
            'me'           => (int) Session::getLoginUserID(),
            'today'        => date('Y-m-d'),
            // O Kanban não é oferecido aqui: ele agrupa em colunas, e uma
            // reserva só tem duas perguntas — qual item e quando. Calendário e
            // Lista respondem as duas; uma terceira forma só dividiria a
            // atenção.
            'default_mode' => in_array(
                Settings::get('default_mode'),
                [Settings::MODE_CALENDAR, Settings::MODE_LIST],
                true
            ) ? Settings::get('default_mode') : Settings::MODE_CALENDAR,
            'default_begin' => date('Y-m-d\TH:00', strtotime('+1 hour')),
            'default_end'   => date('Y-m-d\TH:00', strtotime('+2 hours')),
            'csrf'          => Session::getNewCSRFToken(),
        ]);
    }

    /**
     * Rótulo da situação de UMA reserva, exibido na coluna Situação da lista
     * e no resumo do evento.
     */
    public static function getStateLabel(int $state): string
    {
        return match ($state) {
            self::STATE_ONGOING  => __('In progress', 'planner'),
            self::STATE_FINISHED => __('Finished reservation', 'planner'),
            default              => __('Upcoming reservation', 'planner'),
        };
    }

    /**
     * Iniciais de um item reservável, usadas no marcador.
     *
     * Exposta para `ReservationEventProvider` mandar o mesmo valor nos eventos:
     * se cada lado calculasse por conta própria, a barra lateral mostraria as
     * iniciais do nome do ativo e os cartões as do rótulo completo
     * ("Computador - NOTE-01" viraria "CN"), e os dois marcadores da mesma
     * coisa ficariam diferentes.
     */
    public static function getItemInitials(string $name): string
    {
        return self::getInitials($name);
    }

    /**
     * TIPOS de ativo que têm ao menos um item reservável nesta entidade.
     *
     * A barra lateral lista tipos, não aparelhos: marcar "Computador" traz
     * todos os computadores reserváveis de uma vez. Listar aparelho por
     * aparelho não escala — uma instalação com cinquenta notebooks
     * reserváveis vira uma lista de cinquenta linhas que ninguém percorre, e
     * o que a pessoa quer quase sempre é "ver os computadores", não "ver o
     * NOTE-014". Cada aparelho continua sendo uma faixa na visão Por item.
     *
     * Um tipo só aparece se houver item dele: a lista se adapta ao que a
     * instalação realmente marcou como reservável.
     *
     * @return array<int, array{itemtype: string, label: string, count: int, color: string, icon: string}>
     */
    public static function getReservableTypes(): array
    {
        $types = [];

        foreach (self::getReservableItems() as $item) {
            $itemtype = $item['itemtype'];

            if (!isset($types[$itemtype])) {
                $types[$itemtype] = [
                    'itemtype' => $itemtype,
                    'label'    => $item['type_label'],
                    'count'    => 0,
                    // A cor identifica o tipo na barra lateral; os eventos
                    // continuam coloridos por APARELHO, que é o que distingue
                    // uma faixa da outra na visão Por item.
                    'color'    => EventProvider::getActorColor(crc32($itemtype)),
                    'icon'     => is_a($itemtype, \CommonGLPI::class, true)
                        ? $itemtype::getIcon()
                        : 'ti ti-package',
                ];
            }

            $types[$itemtype]['count']++;
        }

        uasort($types, static fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return array_values($types);
    }

    /**
     * Itens reserváveis visíveis, em lista única.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getReservableItems(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!self::canView()) {
            return [];
        }

        $table = ReservationItem::getTable();

        $rows = $DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id'],
            'FROM'   => $table,
            'WHERE'  => [
                'is_active' => 1,
            ] + getEntitiesRestrictCriteria($table, '', '', true),
        ]);

        $items = [];

        foreach ($rows as $row) {
            $itemtype = (string) $row['itemtype'];
            $item     = getItemForItemtype($itemtype);

            // Item apagado sem limpar a linha de reservável: não há o que
            // mostrar, e exibir "#42" só confundiria.
            if ($item === false || !$item->getFromDB((int) $row['items_id'])) {
                continue;
            }

            $id   = (int) $row['id'];
            $name = $item->getName();

            $items[] = [
                'id'         => $id,
                'itemtype'   => $itemtype,
                'name'       => $name,
                'type_name'  => $item::getTypeName(1),
                'type_label' => $item::getTypeName(2),
                'color'      => EventProvider::getActorColor($id),
                'initials'   => self::getInitials($name),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return [$a['type_label'], $a['name']] <=> [$b['type_label'], $b['name']];
        });

        return $items;
    }

    /**
     * Itens reserváveis dos tipos pedidos, para montar as faixas e restringir
     * a consulta de reservas.
     *
     * A lista de tipos vem do cliente e é filtrada aqui contra o que ele
     * realmente pode ver: um itemtype inventado na requisição não casa com
     * nada e simplesmente não devolve item nenhum.
     *
     * @param array<int, string> $itemtypes
     * @return array<int, array<string, mixed>>
     */
    public static function getItemsForTypes(array $itemtypes): array
    {
        if ($itemtypes === []) {
            return [];
        }

        return array_values(array_filter(
            self::getReservableItems(),
            static fn(array $item) => in_array($item['itemtype'], $itemtypes, true)
        ));
    }

    /**
     * Iniciais para o marcador do item.
     *
     * Nome de ativo costuma ser um código com separadores ("SALA-REUNIAO-02",
     * "NOTE_014"), e muitas instalações usam um prefixo comum em todo o
     * parque. Pegar as duas primeiras letras do nome inteiro faria
     * "PLANNER-NOTE-01" e "PLANNER-PROJETOR" virarem os dois "PL" — marcadores
     * idênticos para itens diferentes. Por isso as iniciais saem da primeira
     * letra de cada PEDAÇO do nome, que é onde a diferença costuma estar.
     */
    private static function getInitials(string $name): string
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
}
