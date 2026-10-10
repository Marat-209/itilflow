<?php

namespace GlpiPlugin\Itilflow;

/** Квитанция команды фиксируется в одной транзакции с её результатом. */
class CommandReceipt extends \CommonDBChild
{
    use RuntimeRead;

    public static $itemtype = Instance::class;
    public static $items_id = 'plugin_itilflow_instances_id';
    public static $rightname = 'plugin_itilflow_process';

    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }
    public function canViewItem(): bool { return false; }

    public static function findCommand(int $instance_id, string $key): ?self
    {
        $rows = (new self())->find(['plugin_itilflow_instances_id' => $instance_id,
            'command_key' => $key], [], 2);
        if (count($rows) !== 1) {
            return null;
        }
        $receipt = new self();
        return $receipt->getFromDB((int) array_key_first($rows)) ? $receipt : null;
    }
}
