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

    public static function show(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $items = self::getReservableItems();

        TemplateRenderer::getInstance()->display('@planner/reservations.html.twig', [
            'root_doc'     => $CFG_GLPI['root_doc'],
            'item_groups'  => $items,
            'items_count'  => array_sum(array_map(static fn(array $g) => count($g['items']), $items)),
            'can_reserve'  => Session::haveRight(Reservation::$rightname, ReservationItem::RESERVEANITEM),
            'me'           => (int) Session::getLoginUserID(),
            'today'        => date('Y-m-d'),
            'default_mode' => Settings::get('default_mode'),
            'state_columns' => self::getStateColumns(),
        ]);
    }

    /**
     * Colunas do Kanban desta tela.
     *
     * @return array<int, array{state: int, title: string, color: string}>
     */
    public static function getStateColumns(): array
    {
        return [
            ['state' => self::STATE_ONGOING,  'title' => __('In progress', 'planner'), 'color' => '#12a594'],
            ['state' => self::STATE_UPCOMING, 'title' => __('Upcoming', 'planner'),    'color' => '#2f6df6'],
            ['state' => self::STATE_FINISHED, 'title' => __('Finished', 'planner'),    'color' => '#6b7a90'],
        ];
    }

    /**
     * Rótulo da situação de UMA reserva.
     *
     * Deliberadamente no singular, ao contrário dos títulos das colunas do
     * Kanban: a coluna agrupa várias ("Encerradas"), a linha da lista fala de
     * uma só ("Encerrada").
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
     * Itens reserváveis visíveis, agrupados pelo tipo de ativo.
     *
     * Agrupar por tipo é o que torna a lista navegável: uma instalação com
     * trinta itens reserváveis misturaria sala, veículo e notebook numa lista
     * plana sem nenhuma pista de onde procurar.
     *
     * @return array<int, array{itemtype: string, label: string, items: array<int, array<string, mixed>>}>
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

        $groups = [];

        foreach ($rows as $row) {
            $itemtype = (string) $row['itemtype'];
            $item     = getItemForItemtype($itemtype);

            // Item apagado sem limpar a linha de reservável: não há o que
            // mostrar, e exibir "#42" só confundiria.
            if ($item === false || !$item->getFromDB((int) $row['items_id'])) {
                continue;
            }

            if (!isset($groups[$itemtype])) {
                $groups[$itemtype] = [
                    'itemtype' => $itemtype,
                    'label'    => $item::getTypeName(2),
                    'items'    => [],
                ];
            }

            $id = (int) $row['id'];
            $groups[$itemtype]['items'][] = [
                'id'       => $id,
                'name'     => $item->getName(),
                'color'    => EventProvider::getActorColor($id),
                'initials' => self::getInitials($item->getName()),
            ];
        }

        foreach ($groups as &$group) {
            usort(
                $group['items'],
                static fn(array $a, array $b) => strcasecmp((string) $a['name'], (string) $b['name'])
            );
        }
        unset($group);

        uasort($groups, static fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return array_values($groups);
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
