<?php

namespace GlpiPlugin\Itilflow;

use CommonDBChild;

/** Шаг — прохождение конкретного этапа конкретной заявкой. */
class Step extends CommonDBChild
{
    use RuntimeRead;

    public static $rightname = 'ticket';
    public static $itemtype = Instance::class;
    public static $items_id = 'plugin_itilflow_instances_id';

    public const PENDING  = 'pending';   // ещё не начат
    public const RUNNING  = 'running';   // текущий
    public const DONE     = 'done';
    public const SKIPPED  = 'skipped';
    public const NOT_NEEDED = 'not_needed';
    public const REJECTED = 'rejected';
    /** Время между сохранёнными датами GLPI; это не SLA и не вычет ожидания. */
    public static function elapsedSeconds(array $row): ?int
    {
        $dates = [];
        foreach (['date_start', 'date_end'] as $key) {
            $raw = $row[$key] ?? null;
            if (!is_string($raw) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $raw)) { return null; }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d H:i:s') !== $raw || substr($raw, 0, 4) === '0000') { return null; }
            $dates[] = $date->getTimestamp();
        }
        return $dates[1] >= $dates[0] ? $dates[1] - $dates[0] : null;
    }

    public const SUPERSEDED = 'superseded';

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
        return $nb > 1 ? 'Этапы заявки' : 'Этап заявки';
    }

    public static function getStateLabels(): array
    {
        return [
            self::PENDING  => Terms::AHEAD,
            self::RUNNING  => Terms::NOW,
            self::DONE     => Terms::DONE,
            self::SKIPPED  => Terms::SKIPPED,
            self::NOT_NEEDED => Terms::NOT_NEEDED,
            self::REJECTED => Terms::REJECTED,
            self::SUPERSEDED => Terms::UNFINISHED,
        ];
    }

    /**
     * Подпись состояния одной строки: брошенный этап различается по тому, начинался ли он
     * и начали ли маршрут заново («Прежняя попытка»).
     */
    public static function stateLabel(array $row): string
    {
        $state = (string) ($row['state'] ?? '');
        if ($state === self::SUPERSEDED) {
            if (empty($row['date_start'])) { return 'Не выполнялся'; }
            return empty($row['superseded_by_attempt_id']) ? Terms::UNFINISHED : Terms::PREVIOUS_ATTEMPT;
        }
        return self::getStateLabels()[$state] ?? $state;
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
        $instance = $this->getInstance();
        if ($instance !== null && DefinitionSnapshot::enabled($instance)) {
            return DefinitionSnapshot::stage($instance, (int) $this->fields['plugin_itilflow_stages_id']);
        }
        $s = new Stage();
        return $s->getFromDB((int) $this->fields['plugin_itilflow_stages_id']) ? $s : null;
    }

    /**
     * Прежняя связь шага-согласования с запросами (до 1.11): строка номеров approver_items_ids,
     * у шагов до 1.5.0 — один номер в artifact_items_id. Шаги, созданные с 1.8 (снимок порога
     * «definition»), без строки номеров прежней связи не имеют: их запросы — строки привязок.
     *
     * @return list<int>
     */
    private static function legacyIds(array $row): array
    {
        $raw = trim((string) ($row['approver_items_ids'] ?? ''));
        if ($raw === '') {
            // Шаги, созданные до 1.5.0, знают только один объект.
            $one = (int) ($row['artifact_items_id'] ?? 0);
            return $one > 0 && ($row['approval_snapshot_source'] ?? '') !== 'definition' ? [$one] : [];
        }
        if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $raw));
        return count(array_unique($ids)) === count($ids) && min($ids) > 0 ? $ids : [];
    }

    /** Связь записана прежней строкой номеров: так идут запуски, начатые до 1.11. */
    public function hasLegacyBinding(): bool
    {
        return trim((string) ($this->fields['approver_items_ids'] ?? '')) !== '' || self::legacyIds($this->fields) !== [];
    }

    /**
     * Объекты согласования этого шага. Их может быть несколько.
     *
     * Идущие запуски читают прежнюю строку номеров; новые привязки — строки OutcomeValidation
     * (у согласования в самой заявке; у заявки отдела привязки читает StructuredAccess::roster).
     */
    public function validationIds(): array
    {
        if ($this->hasLegacyBinding()) {
            return self::legacyIds($this->fields);
        }
        if (($this->fields['execution_mode'] ?? '') !== Stage::MODE_APPROVAL || $this->isNewItem()) {
            return [];
        }
        return array_keys(OutcomeValidation::forStep($this));
    }

    /** Вид привязки каждого запроса шага: номер => вид. Прежняя строка номеров — «создан маршрутом». */
    public function bindingKinds(): array
    {
        if ($this->hasLegacyBinding()) {
            return array_fill_keys(self::legacyIds($this->fields), OutcomeValidation::KIND_CREATED);
        }
        $out = [];
        foreach (OutcomeValidation::forStep($this) as $id => $row) {
            $out[$id] = OutcomeValidation::kindOf($row);
        }
        return $out;
    }

    /**
     * Все шаги, которым принадлежит запрос на согласование: по прежней строке номеров
     * и по строкам привязок. Больше одного — связь испорчена: такой запрос никто не трогает.
     * По этой функции работают и перенос ответа в маршрут, и защита от удаления.
     *
     * @return list<self>
     */
    public static function ownersOf(string $itemtype, int $items_id): array
    {
        $out = [];
        foreach (self::ownersMap($itemtype, [$items_id])[$items_id] ?? [] as $sid) {
            $step = new self();
            if ($step->getFromDB($sid)) {
                $out[] = $step;
            }
        }
        return $out;
    }

    /**
     * Владельцы сразу нескольких запросов одного типа: номер запроса => номера шагов.
     *
     * @param list<int> $items_ids
     * @return array<int,list<int>>
     */
    public static function ownersMap(string $itemtype, array $items_ids): array
    {
        $items_ids = array_values(array_filter(array_map('intval', $items_ids), static fn(int $id): bool => $id > 0));
        if (!in_array($itemtype, ['TicketValidation', 'ChangeValidation'], true) || $items_ids === []) {
            return [];
        }
        $wanted = array_flip($items_ids);
        $map = [];
        // Прежняя связь: на одном этапе может быть несколько отдельных согласований,
        // и artifact_items_id указывает лишь на первое из них.
        foreach (self::legacyCandidates($itemtype, $items_ids) as $sid => $row) {
            foreach (self::legacyIds($row) as $id) {
                if (isset($wanted[$id])) {
                    $map[$id][(int) $sid] = true;
                }
            }
        }
        foreach ((new OutcomeValidation())->find(['validation_itemtype' => $itemtype, 'validations_id' => $items_ids]) as $row) {
            $map[(int) $row['validations_id']][(int) $row['plugin_itilflow_steps_id']] = true;
        }
        return array_map(static fn(array $steps): array => array_keys($steps), $map);
    }

    /**
     * Этапы, у которых может быть прежняя связь с этими запросами. Раньше здесь был просмотр всех этапов
     * согласования с FOR UPDATE: две команды с общим согласующим блокировали строки друг друга и вставали
     * во взаимную блокировку (MySQL 8). Теперь:
     * - этапы запусков тех заявок, которым принадлежат запросы, — текущим чтением с блокировкой, по
     *   индексу `instance` (заявку запроса читаем без блокировки: она не меняется);
     * - остальные этапы согласования — обычным чтением, без блокировок: связь этапа чужой заявки с
     *   запросом бывает только при повреждении данных, но и её надо увидеть — один запрос не может
     *   принадлежать двум этапам. Снятый запрос (заявку не узнать) находится этим же просмотром.
     *
     * @param list<int> $items_ids
     * @return array<int,array> номер шага => строка
     */
    public static function legacyCandidates(string $itemtype, array $items_ids): array
    {
        global $DB;
        $itil = $itemtype::getItilObjectItemType();
        $fk = $itil::getForeignKeyField();
        $parents = [];
        foreach ($DB->request(['SELECT' => ['id', $fk], 'FROM' => $itemtype::getTable(), 'WHERE' => ['id' => $items_ids]]) as $r) {
            $parents[(int) $r[$fk]] = true;
        }
        $rows = [];
        $instances = $parents === [] ? [] : array_keys((new Instance())->find(['itemtype' => $itil, 'items_id' => array_keys($parents)]));
        if ($instances !== []) {
            $rows = (new self())->find(['plugin_itilflow_instances_id' => $instances,
                'execution_mode' => Stage::MODE_APPROVAL, 'artifact_itemtype' => $itemtype]);
        }
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['execution_mode' => Stage::MODE_APPROVAL,
            'artifact_itemtype' => $itemtype]]) as $r) {
            $rows[(int) $r['id']] ??= $r;
        }
        return $rows;
    }

    /** Единственный шаг, которому принадлежит запрос; ноль или несколько — null. */
    public static function ownerOf(string $itemtype, int $items_id): ?self
    {
        $owners = self::ownersOf($itemtype, $items_id);
        return count($owners) === 1 ? $owners[0] : null;
    }

    public static function getByValidation(string $itemtype, int $items_id): ?self
    {
        $matches = self::ownersOf($itemtype, $items_id);
        return count($matches) === 1 && $matches[0]->fields['state'] === self::RUNNING
            && $matches[0]->fields['execution_mode'] === Stage::MODE_APPROVAL
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
            || $percent < 1 || $percent > 100
            || OutcomeValidation::forStep($this) !== []) {
            return false;
        }
        $stage = $this->getStage();
        $fallback = json_decode((string) ($this->fields['approver_fallback'] ?? ''), true);
        return $stage !== null && (($stage->fields['approver_source'] ?? Stage::APPROVER_FIXED)
               === Stage::APPROVER_RUNTIME || ($stage->fields['approver_source'] ?? '') === 'form' || ($fallback['waiting'] ?? false) === true);
    }

    /** Внешняя виза не создаётся заново: пустой текущий шаг ждёт источник или ручное принятие. */
    public function awaitsFormRequests(): bool
    {
        $stage = $this->getStage();
        return $stage !== null && ($stage->fields['approver_source'] ?? '') === 'form'
            && $this->fields['execution_mode'] === Stage::MODE_APPROVAL
            && $this->fields['state'] === self::RUNNING
            && (int) ($this->fields['artifact_items_id'] ?? 0) === 0
            && (string) ($this->fields['artifact_itemtype'] ?? '') === ''
            && (string) ($this->fields['approver_items_ids'] ?? '') === ''
            && ($this->fields['approval_expected_count'] ?? null) === null
            && OutcomeValidation::forStep($this) === [];
    }
    /** Ручное принятие доступно пустому runtime/form этапу, независимо от версии снимка. */
    public function awaitsExistingRequests(): bool
    {
        $source = $this->getStage()?->fields['approver_source'] ?? '';
        return in_array($source, [Stage::APPROVER_RUNTIME, 'form'], true)
            && ($this->awaitsApprover() || $this->awaitsFormRequests());
    }
    /** Внешняя native ступень сохраняет своё правило расчёта даже у старого запуска. */
    public function hasExternalBindings(): bool
    {
        $kinds = $this->bindingKinds();
        return $kinds !== [] && count(array_filter($kinds, static fn(string $kind): bool =>
            !in_array($kind, [OutcomeValidation::KIND_FORM, OutcomeValidation::KIND_MANUAL], true))) === 0;
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
        foreach (CurrentRead::find(new $type(), ['id' => $ids]) as $row) {
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
        // Строки привязок (1.11) сверяются с самими запросами: попытка, заявка, организация,
        // адресат и ступень GLPI — те, с которыми запрос привязан.
        if (!$this->hasLegacyBinding()) {
            $bindings = OutcomeValidation::forStep($this);
            foreach ($rows as $id => $row) {
                $b = $bindings[$id] ?? null;
                if ($b === null || OutcomeValidation::kindOf($b) === 'unknown' || $b['validation_itemtype'] !== $type
                    || (int) $b['attempt_id'] !== (int) ($this->fields['attempt_id'] ?? 0)
                    || $b['artifact_itemtype'] !== $item->getType() || (int) $b['artifact_items_id'] !== (int) $item->getID()
                    || (int) $b['entities_id'] !== (int) $row['entities_id']
                    || $b['itemtype_target'] !== $row['itemtype_target'] || (int) $b['items_id_target'] !== (int) $row['items_id_target']
                    || (int) $b['validation_step_id'] !== (int) $row['itils_validationsteps_id']) {
                    return null;
                }
            }
        }
        // Один запрос согласования не может принадлежать двум попыткам/объектам.
        $owners = self::ownersMap($type, $ids);
        foreach ($ids as $id) {
            if (($owners[$id] ?? []) !== [(int) $this->getID()]) {
                return null;
            }
        }
        return $rows;
    }
}
