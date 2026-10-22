<?php

namespace GlpiPlugin\Itilflow;

/** Структурированный исход; публичная проекция отделена от внутренней причины. */
class Outcome extends \CommonDBChild
{
    use RuntimeRead;

    public static $itemtype = Step::class;
    public static $items_id = 'plugin_itilflow_steps_id';
    public static $rightname = 'plugin_itilflow_process';

    public const COMPLETED = 'completed';
    public const REFUSED = 'refused';
    public const CLARIFICATION = 'clarification';

    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }
    public function canViewItem(): bool { return false; }

    public static function forStep(int $step_id): ?self
    {
        $rows = (new self())->find(['plugin_itilflow_steps_id' => $step_id], [], 2);
        if (count($rows) !== 1) {
            return null;
        }
        $outcome = new self();
        return $outcome->getFromDB((int) array_key_first($rows)) ? $outcome : null;
    }

    public static function labels(): array
    {
        // Те же слова, что на кнопках отдела и в строке этапа.
        return [self::COMPLETED => Terms::DONE, self::REFUSED => Terms::REFUSAL,
            self::CLARIFICATION => Terms::ON_CLARIFICATION];
    }
}
