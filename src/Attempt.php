<?php

namespace GlpiPlugin\Itilflow;

/** Проход маршрута. Закрытая история не заменяется при новом проходе. */
class Attempt extends \CommonDBChild
{
    use RuntimeRead;

    public static $itemtype = Instance::class;
    public static $items_id = 'plugin_itilflow_instances_id';
    public static $rightname = 'plugin_itilflow_process';

    public const RUNNING = 'running';
    public const WAITING_INPUT = 'waiting_input';
    public const RETURNED = 'returned';
    public const REFUSED = 'refused';
    public const DONE = 'done';
    public const ABORTED = 'aborted';

    // Generic API не должен раскрывать закрытую причину или предмет.
    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }
    public function canViewItem(): bool { return false; }

    public static function current(Instance $instance): ?self
    {
        $attempt = new self();
        $id = (int) ($instance->fields['current_attempt_id'] ?? 0);
        return $id > 0 && $attempt->getFromDB($id)
            && (int) $attempt->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()
            ? $attempt : null;
    }
}
