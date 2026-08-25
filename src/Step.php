<?php

namespace GlpiPlugin\Itilflow;

use CommonDBChild;

/** Шаг — прохождение конкретного этапа конкретной заявкой. */
class Step extends CommonDBChild
{
    public static $rightname = 'ticket';
    public static $itemtype = Instance::class;
    public static $items_id = 'plugin_itilflow_instances_id';

    public const PENDING  = 'pending';   // ещё не начат
    public const RUNNING  = 'running';   // текущий
    public const DONE     = 'done';
    public const SKIPPED  = 'skipped';
    public const REJECTED = 'rejected';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Шаги маршрута' : 'Шаг маршрута';
    }

    public static function getStateLabels(): array
    {
        return [
            self::PENDING  => 'Ожидает очереди',
            self::RUNNING  => 'Выполняется',
            self::DONE     => 'Завершён',
            self::SKIPPED  => 'Пропущен',
            self::REJECTED => 'Отклонён',
        ];
    }

    /** Шаг, к которому относится объект (задача, дочерняя заявка, согласование). */
    public static function getByArtifact(string $itemtype, int $items_id): ?self
    {
        $found = (new self())->find(
            ['artifact_itemtype' => $itemtype, 'artifact_items_id' => $items_id],
            ['id DESC'],
            1
        );
        if (!count($found)) {
            return null;
        }
        $s = new self();
        $s->getFromDB((int) array_key_first($found));
        return $s;
    }

    public function getInstance(): ?Instance
    {
        $i = new Instance();
        return $i->getFromDB((int) $this->fields['plugin_itilflow_instances_id']) ? $i : null;
    }

    public function getStage(): ?Stage
    {
        $s = new Stage();
        return $s->getFromDB((int) $this->fields['plugin_itilflow_stages_id']) ? $s : null;
    }

    /** Может ли пользователь закрыть этот шаг: он в группе этапа или это его шаг. */
    public function isOwnedBy(int $users_id, array $groups_ids): bool
    {
        if ((int) $this->fields['users_id'] > 0 && (int) $this->fields['users_id'] === $users_id) {
            return true;
        }
        if ((int) $this->fields['groups_id'] > 0 && in_array((int) $this->fields['groups_id'], $groups_ids, true)) {
            return true;
        }
        // Ответственный не задан вовсе — шаг открыт всем участникам заявки.
        return (int) $this->fields['users_id'] === 0 && (int) $this->fields['groups_id'] === 0;
    }
}
