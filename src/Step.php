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
    /** Объекты согласования этого шага. Их может быть несколько. */
    public function validationIds(): array
    {
        $raw = trim((string) ($this->fields['approver_items_ids'] ?? ''));
        if ($raw === '') {
            // Шаги, созданные до 1.5.0, знают только один объект.
            $one = (int) ($this->fields['artifact_items_id'] ?? 0);
            return $one > 0 ? [$one] : [];
        }
        return array_values(array_filter(array_map(
            static fn($x) => (int) trim((string) $x),
            explode(',', $raw)
        )));
    }

    /**
     * Шаг, которому принадлежит объект согласования.
     *
     * Ищем по списку: на одном этапе может быть несколько отдельных
     * согласований, и artifact_items_id указывает лишь на первое из них.
     */
    public static function getByValidation(string $itemtype, int $items_id): ?self
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'execution_mode' => Stage::MODE_APPROVAL,
                'state'          => self::RUNNING,
                new \QueryExpression(
                    'FIND_IN_SET(' . (int) $items_id . ', '
                    . $DB->quoteName('approver_items_ids') . ') > 0'
                ),
            ],
            'ORDER'  => 'id DESC',
        ]) as $row) {
            $s = new self();
            if ($s->getFromDB((int) $row['id'])) {
                return $s;
            }
        }
        // Совместимость со шагами до 1.5.0.
        return self::getByArtifact($itemtype, $items_id);
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
        if ((int) $this->fields['artifact_items_id'] > 0) {
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
        // Ответственный не задан вовсе — шаг открыт всем участникам заявки.
        return (int) $this->fields['users_id'] === 0 && (int) $this->fields['groups_id'] === 0;
    }
}
