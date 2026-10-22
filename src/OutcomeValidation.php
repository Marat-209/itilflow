<?php

namespace GlpiPlugin\Itilflow;

/**
 * Привязка запроса на согласование к шагу маршрута — одна строка на запрос (уникальный ключ
 * по запросу): запрос не может принадлежать двум шагам.
 *
 * До 1.11 — только запросы в заявке отдела («Согласованием в заявке отдела»); запросы
 * в самой заявке шаг хранил строкой номеров approver_items_ids. С 1.11 новые привязки
 * всех согласований — строками этой таблицы; идущие запуски по-прежнему читают свою
 * строку номеров (Step::validationIds).
 *
 * Вид привязки (binding_kind) решает, снимает ли маршрут неотвеченный запрос:
 * - created — создан маршрутом: снимается при отказе, возврате, прерывании, принудительном
 *   закрытии — механизмом 1.10.1 (StructuredFlow::dropValidations);
 * - form — отправлен формой при подаче заявки и это доказано в той же операции: снимается
 *   только по квитанции подачи и после повторной проверки исключительности шага формы;
 *   привязку и снятие делает «Виза из формы» (FormSource), здесь — только вид;
 * - manual — принят вручную («Принять запрос из заявки»): маршрут его не снимает.
 * Отвеченные запросы не снимаются никогда.
 */
class OutcomeValidation extends \CommonDBChild
{
    use RuntimeRead;

    public static $itemtype = Step::class;
    public static $items_id = 'plugin_itilflow_steps_id';
    public static $rightname = 'plugin_itilflow_process';

    public const KIND_CREATED = 'created';
    public const KIND_FORM = 'form';
    public const KIND_MANUAL = 'manual';

    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }
    public function canViewItem(): bool { return false; }

    /** Виды привязки словами — для строки этапа и листа согласования. */
    public static function kindLabels(): array
    {
        return [
            self::KIND_CREATED => 'создан маршрутом',
            self::KIND_FORM    => 'запрошен формой',
            self::KIND_MANUAL  => 'засчитан вручную',
        ];
    }

    /** Вид привязки строки; строки до 1.11 (без поля) — созданы маршрутом. */
    public static function kindOf(array $row): string
    {
        $kind = array_key_exists('binding_kind', $row) ? $row['binding_kind'] : self::KIND_CREATED;
        return is_string($kind) && isset(self::kindLabels()[$kind]) ? $kind : 'unknown';
    }

    /**
     * Привязать запрос к шагу. Объект, к которому относится запрос (artifact), — заявка
     * маршрута или заявка отдела. Уникальный ключ по запросу не даёт привязать его второй раз:
     * повтор — исключение, команда откатывается.
     */
    public static function bind(Step $step, \CommonITILValidation $validation, \CommonITILObject $artifact, string $kind): int
    {
        if (!isset(self::kindLabels()[$kind]) || $validation->isNewItem() || $artifact->isNewItem()
            || (int) ($validation->fields[$artifact::getForeignKeyField()] ?? 0) !== (int) $artifact->getID()) {
            throw new \RuntimeException('Привязка запроса на согласование не подтверждена.');
        }
        $id = (int) (new self())->add([
            'plugin_itilflow_steps_id' => (int) $step->getID(),
            // У шагов прежнего порядка попытки нет: 0.
            'attempt_id'          => (int) ($step->fields['attempt_id'] ?? 0),
            'validation_itemtype' => $validation->getType(),
            'validations_id'      => (int) $validation->getID(),
            'artifact_itemtype'   => $artifact->getType(),
            'artifact_items_id'   => (int) $artifact->getID(),
            'entities_id'         => (int) $validation->fields['entities_id'],
            'itemtype_target'     => (string) $validation->fields['itemtype_target'],
            'items_id_target'     => (int) $validation->fields['items_id_target'],
            'validation_step_id'  => (int) $validation->fields['itils_validationsteps_id'],
            'binding_kind'        => $kind,
        ]);
        if ($id <= 0) {
            throw new \RuntimeException('Привязка запроса на согласование не сохранена.');
        }
        return $id;
    }

    /**
     * Привязки шага: номер запроса => строка, по возрастанию номера.
     *
     * @return array<int,array>
     */
    public static function forStep(Step $step): array
    {
        $out = [];
        foreach ((new self())->find(['plugin_itilflow_steps_id' => (int) $step->getID()], ['validations_id ASC']) as $row) {
            $out[(int) $row['validations_id']] = $row;
        }
        return $out;
    }

    /** Чистый барьер для принятия целой внешней ступени; не предоставляет полномочий. */
    public static function adoptionPlan(array $rows, array $context): ?array
    {
        $ids = []; $step = null; $answered = false;
        if (!$rows || count($rows) > 50 || !is_int($context['ticket_id'] ?? null)
            || !is_int($context['entity_id'] ?? null)) { return null; }
        foreach ($rows as $r) {
            if (!is_array($r) || self::number($r['id'] ?? null) === null
                || isset($ids[(int) $r['id']])
                || self::number($r['tickets_id'] ?? null) !== ($context['ticket_id'] ?? null)
                || self::number($r['entities_id'] ?? null, true) !== ($context['entity_id'] ?? null)
                || !in_array($r['itemtype_target'] ?? '', ['User', 'Group'], true)
                || self::number($r['items_id_target'] ?? null) === null
                || !in_array(self::number($r['status'] ?? null), [2, 3, 4], true)
                || self::number($r['itils_validationsteps_id'] ?? null) === null) { return null; }
            $native = (int) $r['itils_validationsteps_id'];
            if ($step !== null && $step !== $native) { return null; }
            $step = $native; $ids[(int) $r['id']] = true;
            $answered = $answered || (int) $r['status'] !== 2;
        }
        $ids = array_keys($ids); sort($ids);
        return ['ids' => $ids, 'step_id' => $step, 'answered' => $answered];
    }
    private static function number(mixed $value, bool $zero = false): ?int
    {
        if (is_string($value)) {
            if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || (string) (int) $value !== $value) { return null; }
            $value = (int) $value;
        }
        return is_int($value) && $value >= ($zero ? 0 : 1) ? $value : null;
    }
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
