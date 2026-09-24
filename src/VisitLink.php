<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Vínculo entre um compromisso do tipo Visita (`PlanningExternalEvent` com a
 * categoria "Visita") e uma Reserva já feita pelo mesmo usuário.
 *
 * Uma linha por visita (`items_id` é o id do `PlanningExternalEvent`) — uma
 * visita liga no máximo a uma reserva, não faz sentido ligar a mais de uma.
 * A reserva em si continua sendo criada/gerida pela tela de Reservas de
 * sempre; esta tabela só guarda QUAL reserva um compromisso de Visita
 * referencia, para o popover mostrar o item reservado junto do compromisso.
 */

namespace GlpiPlugin\Refactorytools;

use Html;
use Migration;
use Reservation;
use ReservationItem;
use Session;

final class VisitLink
{
    public static function getTable(): string
    {
        return 'glpi_plugin_refactorytools_visit_links';
    }

    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $DB->doQuery("
                CREATE TABLE `{$table}` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `items_id` INT UNSIGNED NOT NULL,
                    `reservations_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`items_id`),
                    KEY `reservations_id` (`reservations_id`)
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

    /**
     * Liga (ou troca o vínculo de) uma visita a uma reserva. Só grava se a
     * reserva pertencer a quem está logado — a mesma regra usada na tela de
     * Reservas: não existe "vincular a reserva de outra pessoa".
     */
    public static function link(int $items_id, int $reservations_id): bool
    {
        if ($items_id <= 0 || $reservations_id <= 0) {
            return false;
        }

        $reservation = new Reservation();
        if (!$reservation->getFromDB($reservations_id)) {
            return false;
        }
        if ((int) $reservation->fields['users_id'] !== (int) Session::getLoginUserID()) {
            return false;
        }

        /** @var \DBmysql $DB */
        global $DB;

        $table    = self::getTable();
        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['items_id' => $items_id]])->current();

        if ($existing) {
            return (bool) $DB->update($table, ['reservations_id' => $reservations_id], ['id' => $existing['id']]);
        }

        return (bool) $DB->insert($table, ['items_id' => $items_id, 'reservations_id' => $reservations_id]);
    }

    /**
     * Reservas ligadas, para os `items_id` (de Visita) já presentes numa
     * resposta — uma consulta só, nunca uma por evento.
     *
     * @param array<int, int> $items_ids
     * @return array<int, array{reservations_id: int, label: string}> items_id => reserva
     */
    public static function getForItems(array $items_ids): array
    {
        if ($items_ids === []) {
            return [];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $links = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['items_id' => $items_ids]]) as $row) {
            $links[(int) $row['items_id']] = (int) $row['reservations_id'];
        }

        if ($links === []) {
            return [];
        }

        $reservation_ids = array_values(array_unique($links));
        $item_names      = [];

        foreach ($DB->request([
            'SELECT'     => ['glpi_reservations.id', 'glpi_reservationitems.itemtype', 'glpi_reservationitems.items_id AS asset_id'],
            'FROM'       => Reservation::getTable(),
            'INNER JOIN' => [
                ReservationItem::getTable() => [
                    'ON' => [
                        Reservation::getTable()     => 'reservationitems_id',
                        ReservationItem::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => ['glpi_reservations.id' => $reservation_ids],
        ]) as $row) {
            $itemtype = (string) $row['itemtype'];
            $asset_id = (int) $row['asset_id'];
            $asset    = class_exists($itemtype) ? new $itemtype() : null;
            $name     = ($asset !== null && $asset->getFromDB($asset_id)) ? $asset->getName() : sprintf('#%d', $asset_id);

            $item_names[(int) $row['id']] = $name;
        }

        $out = [];
        foreach ($links as $items_id => $reservations_id) {
            if (isset($item_names[$reservations_id])) {
                $out[$items_id] = [
                    'reservations_id' => $reservations_id,
                    'label'           => $item_names[$reservations_id],
                ];
            }
        }

        return $out;
    }

    /**
     * Reservas do usuário logado, para o seletor "Vincular reserva" do modal
     * de Visita — só as que ainda não terminaram, mais recentes primeiro.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public static function getMyReservationChoices(): array
    {
        $me = (int) Session::getLoginUserID();
        if ($me <= 0) {
            return [];
        }

        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_reservations.id',
                'glpi_reservations.begin',
                'glpi_reservationitems.itemtype',
                'glpi_reservationitems.items_id AS asset_id',
            ],
            'FROM'       => Reservation::getTable(),
            'INNER JOIN' => [
                ReservationItem::getTable() => [
                    'ON' => [
                        Reservation::getTable()     => 'reservationitems_id',
                        ReservationItem::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_reservations.users_id' => $me,
                'glpi_reservations.end'      => ['>=', date('Y-m-d H:i:s')],
            ],
            'ORDER' => 'glpi_reservations.begin',
        ]) as $row) {
            $itemtype = (string) $row['itemtype'];
            $asset_id = (int) $row['asset_id'];
            $asset    = class_exists($itemtype) ? new $itemtype() : null;
            $name     = ($asset !== null && $asset->getFromDB($asset_id)) ? $asset->getName() : sprintf('#%d', $asset_id);

            $out[] = [
                'id'    => (int) $row['id'],
                'label' => sprintf('%s — %s', $name, Html::convDateTime($row['begin'])),
            ];
        }

        return $out;
    }
}
