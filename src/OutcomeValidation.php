<?php

namespace GlpiPlugin\Itilflow;

/** Полный набор собственных адресных виз дочерней работы. */
class OutcomeValidation extends \CommonDBChild
{
    use RuntimeRead;

    public static $itemtype = Step::class;
    public static $items_id = 'plugin_itilflow_steps_id';
    public static $rightname = 'plugin_itilflow_process';

    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }
    public function canViewItem(): bool { return false; }

    public static function stepFor(string $type, int $id): ?Step
    {
        $rows = (new self())->find(['validation_itemtype' => $type, 'validations_id' => $id], [], 2);
        if (count($rows) !== 1) {
            return null;
        }
        $row = reset($rows);
        $step = new Step();
        return $step->getFromDB((int) $row['plugin_itilflow_steps_id']) ? $step : null;
    }
}
