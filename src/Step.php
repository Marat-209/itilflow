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

    // Строки исполнения изменяются только командами Engine, не generic CRUD.
    // Native add/update внутри движка не вызывают эти permission callbacks.
    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }

    public function canViewItem(): bool
    {
        $instance = $this->getInstance();
        return $instance !== null && Authorizer::instanceError($instance, 'read') === null;
    }

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
            2
        );
        if (count($found) !== 1) {
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
    /** Объекты согласования этого шага. Их может быть несколько. */
    public function validationIds(): array
    {
        $raw = trim((string) ($this->fields['approver_items_ids'] ?? ''));
        if ($raw === '') {
            // Шаги, созданные до 1.5.0, знают только один объект.
            $one = (int) ($this->fields['artifact_items_id'] ?? 0);
            return $one > 0 ? [$one] : [];
        }
        if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $raw));
        return count(array_unique($ids)) === count($ids) && min($ids) > 0 ? $ids : [];
    }

    /**
     * Шаг, которому принадлежит объект согласования.
     *
     * Ищем по списку: на одном этапе может быть несколько отдельных
     * согласований, и artifact_items_id указывает лишь на первое из них.
     */
    public static function validationBindings(string $itemtype, int $items_id): array
    {
        global $DB;
        if (!in_array($itemtype, ['TicketValidation', 'ChangeValidation'], true) || $items_id <= 0) {
            return [];
        }
        $matches = [];
        foreach ($DB->request([
            'SELECT' => ['id'], 'FROM' => self::getTable(),
            'WHERE' => ['execution_mode' => Stage::MODE_APPROVAL,
                'artifact_itemtype' => $itemtype,
                'OR' => ['artifact_items_id' => $items_id, new \QueryExpression(
                    'FIND_IN_SET(' . $items_id . ', ' . $DB->quoteName('approver_items_ids') . ') > 0')]],
        ]) as $row) {
            $step = new self();
            if ($step->getFromDB((int) $row['id'])) {
                $matches[] = $step;
            }
        }
        return $matches;
    }

    public static function getByValidation(string $itemtype, int $items_id): ?self
    {
        $matches = self::validationBindings($itemtype, $items_id);
        return count($matches) === 1 && $matches[0]->fields['state'] === self::RUNNING
            && in_array($items_id, $matches[0]->validationIds(), true) ? $matches[0] : null;
    }

    /**
     * Этап-согласование, который ждёт, чтобы ему указали согласующего.
     *
     * Признак вычисляемый, без отдельного поля: этап в режиме согласования
     * идёт, объекта согласования ещё нет, а этап настроен так, что согласующего
     * указывают при прохождении.
     */
    public function awaitsApprover(): bool
    {
        if ($this->fields['execution_mode'] !== Stage::MODE_APPROVAL) {
            return false;
        }
        if ($this->fields['state'] !== self::RUNNING) {
            return false;
        }
        // Назначение допустимо лишь до появления какой-либо связи.
        // Неполный прежний состав нельзя молча заменить новым.
        $percent = (int) ($this->fields['approval_percent_snapshot'] ?? 0);
        if ((int) $this->fields['artifact_items_id'] !== 0
            || (string) ($this->fields['artifact_itemtype'] ?? '') !== ''
            || (string) ($this->fields['approver_items_ids'] ?? '') !== ''
            || ($this->fields['approval_expected_count'] ?? null) !== null
            || $percent < 1 || $percent > 100) {
            return false;
        }
        $stage = $this->getStage();
        return $stage !== null
            && ($stage->fields['approver_source'] ?? Stage::APPROVER_FIXED)
               === Stage::APPROVER_RUNTIME;
    }

    public function isOwnedBy(int $users_id, array $groups_ids): bool
    {
        if ((int) $this->fields['users_id'] > 0 && (int) $this->fields['users_id'] === $users_id) {
            return true;
        }
        if ((int) $this->fields['groups_id'] > 0 && in_array((int) $this->fields['groups_id'], $groups_ids, true)) {
            return true;
        }
        // Отсутствие владельца не является полномочием. Назначение проверяется отдельно.
        return false;
    }

    /** Полный состав по сохранённой связи; отсутствие записи не уменьшает знаменатель. */
    public function validationRoster(bool $require_snapshot = true): ?array
    {
        global $DB;
        $instance = $this->getInstance();
        $item = $instance?->getItilItem();
        $class = $item?->getValidationClassInstance();
        $ids = $this->validationIds();
        if ($this->fields['execution_mode'] !== Stage::MODE_APPROVAL || $item === null
            || $class === null || $this->fields['artifact_itemtype'] !== get_class($class)
            || count($ids) === 0 || (int) $this->fields['artifact_items_id'] !== $ids[0]
            || ($require_snapshot && (int) ($this->fields['approval_expected_count'] ?? 0) !== count($ids))) {
            return null;
        }
        $type = get_class($class);
        $rows = [];
        foreach ($DB->request(['FROM' => $type::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
            if ((int) ($row[$item::getForeignKeyField()] ?? 0) !== (int) $item->getID()
                || (int) ($row['entities_id'] ?? -1) !== (int) $item->fields['entities_id']
                || !in_array((int) $row['status'], [\CommonITILValidation::WAITING,
                    \CommonITILValidation::ACCEPTED, \CommonITILValidation::REFUSED], true)) {
                return null;
            }
            $rows[(int) $row['id']] = $row;
        }
        if (count($rows) !== count($ids)) {
            return null;
        }
        // Один запрос согласования не может принадлежать двум попыткам/объектам.
        foreach ((new self())->find(['artifact_itemtype' => $type]) as $sid => $other) {
            if ((int) $sid === (int) $this->getID()) {
                continue;
            }
            $other_ids = array_map('intval', explode(',', (string) ($other['approver_items_ids'] ?? '')));
            $other_ids[] = (int) ($other['artifact_items_id'] ?? 0);
            if (array_intersect($ids, $other_ids)) {
                return null;
            }
        }
        return $rows;
    }
}
