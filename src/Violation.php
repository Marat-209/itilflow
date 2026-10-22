<?php

namespace GlpiPlugin\Itilflow;

use CommonDBTM;
use Session;

/**
 * Журнал маршрута: действия не по порядку этапов, остановки, просрочки,
 * записи о согласовании из формы и о заявках формы без маршрута.
 */
class Violation extends CommonDBTM
{
    public static $rightname = 'plugin_itilflow_process';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? Terms::ROUTE_LOG : Terms::ROUTE_LOG_ENTRY;
    }

    public static function getIcon(): string
    {
        return 'ti ti-alert-triangle';
    }

    public function isEntityAssign()
    {
        return true;
    }

    public static function record(
        ?Instance $instance,
        string $itemtype,
        int $items_id,
        string $action,
        bool $was_blocked,
        string $message,
        ?int $entities_id = null
    ): bool {
        // 1.13: запись без запуска (заявка с формы без маршрута) — в организации заявки,
        // чтобы её видел администратор маршрутов этой организации, а не только корня.
        $v = new self();
        return (int) $v->add([
            'plugin_itilflow_instances_id' => $instance ? $instance->getID() : 0,
            'entities_id'                  => $instance ? (int) $instance->fields['entities_id'] : max(0, (int) $entities_id),
            'users_id'                     => (int) (Session::getLoginUserID() ?: 0),
            'itemtype'                     => $itemtype,
            'items_id'                     => $items_id,
            'action'                       => $action,
            'was_blocked'                  => $was_blocked ? 1 : 0,
            'message'                      => $message,
            'date'                         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]) > 0;
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $tab[] = [
            'id' => '1', 'table' => self::getTable(), 'field' => 'date',
            'name' => __('Date'), 'datatype' => 'datetime',
        ];
        $tab[] = [
            'id' => '2', 'table' => self::getTable(), 'field' => 'message',
            'name' => __('Description'), 'datatype' => 'text',
        ];
        $tab[] = [
            'id' => '3', 'table' => self::getTable(), 'field' => 'was_blocked',
            'name' => 'Запрещено', 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => '4', 'table' => 'glpi_users', 'field' => 'name',
            'name' => \User::getTypeName(1), 'datatype' => 'dropdown',
        ];
        return $tab;
    }
}
