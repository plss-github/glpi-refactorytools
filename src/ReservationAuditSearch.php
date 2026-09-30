<?php

namespace GlpiPlugin\Refactorytools;

use CommonDBTM;
use Session;

/**
 * Itemtype read-only para usar com Search::show() no histórico de reservas.
 *
 * Mapeia a tabela de histórico e expõe rawSearchOptions() para que a
 * interface de busca nativa do GLPI (igual à de chamados/mudanças) seja
 * renderizada sem código extra.
 */
final class ReservationAuditSearch extends CommonDBTM
{
    public static $rightname = 'reservation';

    public static function getTable($classname = null): string
    {
        return ReservationHistory::getTable();
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Reservation history', 'refactorytools');
    }

    public static function canView(): bool
    {
        return Session::haveRightsOr(
            \Reservation::$rightname,
            [READ, CREATE, \ReservationItem::RESERVEANITEM]
        );
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
            . '/plugins/refactorytools/front/reservation_history.php';
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
            'field'         => 'date',
            'name'          => __('When', 'refactorytools'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'action',
            'name'          => __('Action', 'refactorytools'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 4,
            'table'         => 'glpi_users',
            'field'         => 'name',
            'name'          => __('Done by', 'refactorytools'),
            'datatype'      => 'dropdown',
            'joinparams'    => [
                'jointype'  => 'standard',
                'linkfield' => 'users_id_author',
            ],
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 5,
            'table'         => 'glpi_users',
            'field'         => 'name',
            'name'          => __('Reservation owner', 'refactorytools'),
            'datatype'      => 'dropdown',
            'joinparams'    => [
                'jointype'       => 'standard',
                'linkfield'      => 'users_id_owner',
                'specific_items' => [],
            ],
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 6,
            'table'         => self::getTable(),
            'field'         => 'item_label',
            'name'          => __('Asset', 'refactorytools'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 7,
            'table'         => self::getTable(),
            'field'         => 'begin',
            'name'          => __('Reservation start', 'refactorytools'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'            => 8,
            'table'         => self::getTable(),
            'field'         => 'end',
            'name'          => __('Reservation end', 'refactorytools'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $opts;
    }

    public static function getSpecificValueToDisplay(string $field, $values, array $options = []): string
    {
        if ($field === 'action') {
            $action = is_array($values) ? ($values['action'] ?? '') : $values;

            return match ($action) {
                ReservationHistory::ACTION_ADD    => __('Created', 'refactorytools'),
                ReservationHistory::ACTION_UPDATE => __('Edited', 'refactorytools'),
                ReservationHistory::ACTION_PURGE  => __('Cancelled', 'refactorytools'),
                default                           => htmlescape($action),
            };
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect(string $field, string $name = '', $values = '', array $options = []): string
    {
        if ($field === 'action') {
            $elements = [
                ReservationHistory::ACTION_ADD    => __('Created', 'refactorytools'),
                ReservationHistory::ACTION_UPDATE => __('Edited', 'refactorytools'),
                ReservationHistory::ACTION_PURGE  => __('Cancelled', 'refactorytools'),
            ];

            return \Dropdown::showFromArray($name, $elements, [
                'value'               => $values,
                'display'             => false,
                'display_emptychoice' => true,
            ]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
