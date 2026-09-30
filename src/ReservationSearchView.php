<?php

namespace GlpiPlugin\Refactorytools;

use CommonDBTM;
use Reservation;

/**
 * Itemtype read-only para usar com Search::show() no relatório de reservas.
 *
 * Mapeia glpi_reservations e expõe rawSearchOptions() com joins para
 * usuários e itens reserváveis — interface de busca nativa do GLPI.
 */
final class ReservationSearchView extends CommonDBTM
{
    public static $rightname = 'reservation';

    public static function getTable($classname = null): string
    {
        return Reservation::getTable();
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Reservation report', 'refactorytools');
    }

    public static function canView(): bool
    {
        return Settings::canViewReservationReport();
    }

    public static function canCreate(): bool { return false; }
    public static function canUpdate(): bool { return false; }
    public static function canDelete(): bool { return false; }
    public static function canPurge(): bool  { return false; }

    public function canViewItem(): bool { return self::canView(); }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }

    public static function getSearchURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '')
            . '/plugins/refactorytools/front/reservation_report.php';
    }

    public static function getFormURL(bool $full = true): string
    {
        return '#';
    }

    public function rawSearchOptions(): array
    {
        $opts = [];

        $opts[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'begin',
            'name'          => __('Start', 'refactorytools'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'end',
            'name'          => __('End', 'refactorytools'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 4,
            'table'         => self::getTable(),
            'field'         => 'comment',
            'name'          => __('Justification', 'refactorytools'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 5,
            'table'         => 'glpi_users',
            'field'         => 'name',
            'name'          => __('Reserved by', 'refactorytools'),
            'datatype'      => 'dropdown',
            'joinparams'    => [
                'jointype'  => 'standard',
                'linkfield' => 'users_id',
            ],
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 6,
            'table'         => 'glpi_reservationitems',
            'field'         => 'itemtype',
            'name'          => __('Asset type', 'refactorytools'),
            'datatype'      => 'specific',
            'joinparams'    => [
                'jointype'  => 'standard',
                'linkfield' => 'reservationitems_id',
            ],
            'massiveaction' => false,
        ];

        return $opts;
    }

    public static function getSpecificValueToDisplay(string $field, $values, array $options = []): string
    {
        if ($field === 'itemtype') {
            $itemtype = is_array($values) ? ($values['itemtype'] ?? '') : $values;
            if ($itemtype !== '' && class_exists($itemtype)) {
                return htmlescape($itemtype::getTypeName(1));
            }
            return htmlescape((string) $itemtype);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect(string $field, string $name = '', $values = '', array $options = []): string
    {
        if ($field === 'itemtype') {
            // Busca os tipos únicos da tabela de itens reserváveis
            global $DB;
            $elements = [];
            foreach ($DB->request(['SELECT' => 'itemtype', 'DISTINCT' => true, 'FROM' => 'glpi_reservationitems']) as $row) {
                $it = $row['itemtype'];
                $elements[$it] = class_exists($it) ? $it::getTypeName(2) : $it;
            }
            asort($elements);

            return \Dropdown::showFromArray($name, $elements, [
                'value'               => $values,
                'display'             => false,
                'display_emptychoice' => true,
            ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
