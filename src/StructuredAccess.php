<?php

namespace GlpiPlugin\Itilflow;

/** Допуск ответа отдела проверяется на его объекте, без смены профиля/сущности. */
final class StructuredAccess
{
    /** Внешняя транзакция могла уже создать старый read-view: его нельзя выдать за текущий lock. */
    public static function currentRow(\CommonDBTM $object, array $keys): bool
    {
        global $DB;
        if (!TransactionScope::hasActive()) { return true; }
        $q = $DB->doQuery('SELECT * FROM ' . $DB->quoteName($object::getTable())
            . ' WHERE id=' . (int) $object->getID() . ' FOR UPDATE');
        $fresh = $DB->fetchAssoc($q);
        if (!$fresh) { return false; }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $fresh) || !array_key_exists($key, $object->fields)
                || (string) ($fresh[$key] ?? '') !== (string) ($object->fields[$key] ?? '')) { return false; }
        }
        return true;
    }

    public static function context(Step $step): ?Instance
    {
        $instance = $step->getInstance();
        if ($instance === null || !DefinitionSnapshot::enabled($instance)
            || DefinitionSnapshot::load($instance) === null
            || Authorizer::contextError($instance, $step) !== null) {
            return null;
        }
        $attempt = Attempt::current($instance);
        return $attempt !== null && $attempt->fields['state'] === Attempt::RUNNING
            && (int) $step->fields['attempt_id'] === (int) $attempt->getID()
            && (int) $step->fields['pass_number'] === (int) $attempt->fields['number']
            && empty($step->fields['superseded_by_attempt_id']) ? $instance : null;
    }

    /** Проверяет сохранённую типизированную child и штатную связь parent/child. */
    public static function child(Step $step): ?\CommonITILObject
    {
        global $DB;
        $instance = $step->getInstance();
        $parent = $instance?->getItilItem();
        $stage = $step->getStage();
        $type = (string) ($step->fields['artifact_itemtype'] ?? '');
        if ($parent === null || $stage === null || $type !== $parent->getType()
            || $step->fields['execution_mode'] !== Stage::MODE_DELEGATED
            || (int) ($step->fields['artifact_items_id'] ?? 0) <= 0) {
            return null;
        }
        $child = new $type();
        if (!CurrentRead::load($child, (int) $step->fields['artifact_items_id'])
            || !empty($child->fields['is_deleted'])
            || (int) $child->fields['entities_id'] !== (int) ($step->fields['artifact_entities_id'] ?? -1)) {
            return null;
        }
        $link = \CommonITILObject_CommonITILObject::getLinkClass($type, $type);
        $pairs = match ($type) {
            'Ticket' => ['tickets_id_1', 'tickets_id_2'],
            'Change' => ['changes_id_1', 'changes_id_2'],
            'Problem' => ['problems_id_1', 'problems_id_2'],
        };
        if ($link === null || count(CurrentRead::find(new $link(), [
                $pairs[0] => $child->getID(), $pairs[1] => $parent->getID(),
                'link' => \CommonITILObject_CommonITILObject::SON_OF,
        ])) !== 1) {
            return null;
        }
        return $child;
    }

    public static function answerError(Step $step, bool $clarification = false, bool $replay = false): ?string
    {
        $instance = $step->getInstance();
        if ((!$replay && self::context($step) === null) || $instance === null
            || DefinitionSnapshot::load($instance) === null || Authorizer::contextError($instance) !== null) {
            return 'Этап уже изменился. Обновите страницу.';
        }
        $stage = $step->getStage();
        if ($step->fields['execution_mode'] === Stage::MODE_DELEGATED) {
            $item = self::child($step);
            if ($item === null || Authorizer::itemError($item, READ) !== null) {
                return 'Дочерняя заявка недоступна.';
            }
            if (($stage->fields['department_result_source'] ?? '') === 'assignee_action') {
                return Authorizer::itemError($item, UPDATE) === null && Authorizer::isAssignee($item)
                    ? null : 'Вы не исполнитель дочерней заявки.';
            }
            if (!$clarification) {
                return 'Результат этого отдела даёт согласование в дочерней заявке.';
            }
        } elseif ($step->fields['execution_mode'] === Stage::MODE_APPROVAL && $clarification) {
            $item = $step->getInstance()?->getItilItem();
            if ($item === null || Authorizer::itemError($item, READ) !== null) {
                return 'Заявка недоступна.';
            }
        } else {
            return 'На этом этапе ответа отдела нет.';
        }
        $rows = self::roster($step);
        if (!$replay && self::nativeAggregate($step) !== null) {
            return 'Сначала перенесите ответ согласующего: «Перенести ответ согласующего» в меню этапа.';
        }
        if ($rows !== null) {
            foreach ($rows as $row) {
                $v = new ($row['_type'])();
                if (($replay || (int) $row['status'] === \CommonITILValidation::WAITING)
                    && CurrentRead::load($v, (int) $row['id']) && ValidationAuthority::canAnswer($v) && $v->can($v->getID(), UPDATE)) {
                    return null;
                }
            }
        }
        return 'Вы не согласующий по этому запросу.';
    }

    /** Не позволяет новой команде обойти ещё не перенесённый окончательный native результат. */
    public static function nativeAggregate(Step $step): ?string
    {
        $stage = $step->getStage();
        if ($step->fields['execution_mode'] !== Stage::MODE_APPROVAL
            && ($stage->fields['department_result_source'] ?? '') !== 'native_validation') { return null; }
        $rows = self::roster($step, TransactionScope::hasActive());
        $need = (int) ($step->fields['approval_percent_snapshot'] ?? 0);
        if (!$rows || $need < ($step->hasExternalBindings() ? 0 : 1) || $need > 100) { return 'unconfirmed'; }
        return self::decide($step, $rows, $need);
    }

    /**
     * Исход этапа по полному составу запросов и порогу.
     *
     * У запуска со снимком схемы 2 (1.11) у прохода этапа своя ступень GLPI, и исход считается
     * тем же правилом, что ядро считает её итог (ValidationLadder::status): отказ маршрута —
     * «Отклонено» и у GLPI. У запусков, начатых раньше, — прежнее правило целых процентов:
     * идущие заявки доходят по тем правилам, с которыми начали.
     *
     * @param array<int,array> $rows строки запросов с полем status
     */
    public static function decide(Step $step, array $rows, int $need): ?string
    {
        $accepted = $refused = $waiting = 0;
        foreach ($rows as $row) {
            match ((int) $row['status']) {
                \CommonITILValidation::ACCEPTED => $accepted++,
                \CommonITILValidation::REFUSED => $refused++,
                default => $waiting++,
            };
        }
        $instance = $step->getInstance();
        return $step->hasExternalBindings() || ($instance !== null && DefinitionSnapshot::schemaOf($instance) >= 2)
            ? ValidationLadder::outcome($accepted, $refused, $waiting, $need)
            : ValidationLadder::legacyOutcome($accepted, $refused, count($rows), $need);
    }

    public static function validationError(\CommonITILValidation $v, Step $step): ?string
    {
        if (self::context($step) === null || !CurrentRead::load($v, (int) $v->getID())) {
            return 'Это согласование уже не относится к этапу. Обновите страницу.';
        }
        $item = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
            ? self::child($step) : $step->getInstance()?->getItilItem();
        $rows = self::roster($step);
        if ($item === null || Authorizer::itemError($item, READ) !== null || $rows === null
            || !isset($rows[$v->getID()]) || $rows[$v->getID()]['_type'] !== $v->getType()
            || !ValidationAuthority::canAnswer($v) || !$v->can($v->getID(), UPDATE)) {
            return 'Вы не согласующий по этому запросу.';
        }
        return null;
    }

    /** Любая потерянная/изменённая связь закрывает расчёт, а не уменьшает знаменатель. */
    public static function roster(Step $step, bool $locking = false): ?array
    {
        global $DB;
        if ($locking) { TransactionScope::requireActive(); }
        if ($step->fields['execution_mode'] === Stage::MODE_APPROVAL) {
            $rows = $step->validationRoster();
            if ($rows === null) { return null; }
            if ($step->hasExternalBindings()) {
                $item = $step->getInstance()?->getItilItem();
                $first = reset($rows); $nativeId = (int) $first['itils_validationsteps_id'];
                if (!$item instanceof \Ticket) { return null; }
                $all = CurrentRead::find(new \TicketValidation(), ['tickets_id' => $item->getID(), 'itils_validationsteps_id' => $nativeId]);
                $expected = array_map('intval', array_keys($rows)); $actual = array_map('intval', array_keys($all));
                sort($expected); sort($actual);
                $native = $item::getValidationStepInstance();
                if ($actual !== $expected || $native === null || !CurrentRead::load($native, $nativeId)
                    || ($native->fields['itemtype'] ?? '') !== 'Ticket'
                    || (int) ($native->fields['items_id'] ?? 0) !== (int) $item->getID()
                    || (int) $native->fields['minimal_required_validation_percent'] !== (int) $step->fields['approval_percent_snapshot']) { return null; }
            }
            ksort($rows);
            foreach ($rows as &$row) {
                if ($locking) {
                    $type = $step->fields['artifact_itemtype'];
                    $q = $DB->doQuery('SELECT * FROM ' . $DB->quoteName($type::getTable()) . ' WHERE id=' . (int) $row['id'] . ' FOR UPDATE');
                    $fresh = $DB->fetchAssoc($q);
                    $item = $step->getInstance()?->getItilItem();
                    if (!$fresh || $item === null || (int) $fresh[$item::getForeignKeyField()] !== (int) $item->getID()
                        || (int) $fresh['entities_id'] !== (int) $item->fields['entities_id']
                        || !in_array((int) $fresh['status'], [\CommonITILValidation::WAITING, \CommonITILValidation::ACCEPTED,
                            \CommonITILValidation::REFUSED], true)) { return null; }
                    $row = $fresh;
                }
                $row['_type'] = $step->fields['artifact_itemtype'];
            }
            unset($row);
            return $rows;
        }
        $child = self::child($step);
        $expected = (int) ($step->fields['approval_expected_count'] ?? 0);
        if ($child === null || $expected <= 0 || ($vclass = $child::getValidationClassInstance()) === null) {
            return null;
        }
        $bindings = (new OutcomeValidation())->find(['plugin_itilflow_steps_id' => $step->getID()], ['validations_id ASC']);
        if (count($bindings) !== $expected) { return null; }
        $rows = [];
        foreach ($bindings as $binding) {
            $v = new ($vclass->getType())();
            if ($locking) {
                $q = $DB->doQuery('SELECT * FROM ' . $DB->quoteName($v::getTable()) . ' WHERE id=' . (int) $binding['validations_id'] . ' FOR UPDATE');
                $fresh = $DB->fetchAssoc($q);
                if (!$fresh) { return null; }
                $v->fields = $fresh;
            } elseif (!CurrentRead::load($v, (int) $binding['validations_id'])) { return null; }
            if ($binding['validation_itemtype'] !== $v->getType()
                || (int) $binding['attempt_id'] !== (int) $step->fields['attempt_id']
                || $binding['artifact_itemtype'] !== $child->getType()
                || (int) $binding['artifact_items_id'] !== (int) $child->getID()
                || (int) ($v->fields[$child::getForeignKeyField()] ?? 0) !== (int) $child->getID()
                || (int) $v->fields['entities_id'] !== (int) $child->fields['entities_id']
                || (int) $binding['entities_id'] !== (int) $child->fields['entities_id']
                || $v->fields['itemtype_target'] !== $binding['itemtype_target']
                || (int) $v->fields['items_id_target'] !== (int) $binding['items_id_target']
                || (int) $v->fields['itils_validationsteps_id'] !== (int) $binding['validation_step_id']
                || !in_array((int) $v->fields['status'], [\CommonITILValidation::WAITING,
                    \CommonITILValidation::ACCEPTED, \CommonITILValidation::REFUSED], true)
                || isset($rows[$v->getID()])) { return null; }
            $rows[$v->getID()] = $v->fields + ['_type' => $v->getType()];
        }
        return $rows;
    }

    /** Уточнение заявителя подтверждает тот, кто ведёт основную заявку, без смены профиля. */
    public static function activateError(Instance $instance): ?string
    {
        $item = $instance->getItilItem();
        if (!Authorizer::isAdministrator() && ($item === null || !Authorizer::isAssignee($item))) {
            return 'Продолжить маршрут после уточнения может исполнитель основной заявки или администратор маршрутов.';
        }
        return self::manageError($instance, true);
    }

    public static function manageError(Instance $instance, bool $assignee = false): ?string
    {
        $item = $instance->getItilItem();
        if (Authorizer::contextError($instance) !== null || !DefinitionSnapshot::enabled($instance)
            || DefinitionSnapshot::load($instance) === null || (!$assignee && !Authorizer::isAdministrator())
            || $item === null || Authorizer::itemError($item, UPDATE) !== null
            || in_array((int) $item->fields['status'], array_merge($item::getSolvedStatusArray(),
                $item::getClosedStatusArray()), true)) {
            return 'Управлять маршрутом сейчас нельзя: он уже изменился или у вас нет права. Обновите страницу.';
        }
        return null;
    }

    /** Только коммуникация: не оживляет попытку и не ослабляет допуск переходов. */
    public static function explanationError(Outcome $outcome, Step $step): ?string
    {
        $instance = $step->getInstance();
        $parent = $instance?->getItilItem();
        if ($instance === null || !DefinitionSnapshot::enabled($instance) || $parent === null
            || !empty($parent->fields['is_deleted']) || DefinitionSnapshot::load($instance) === null
            || (int) $outcome->fields['plugin_itilflow_instances_id'] !== (int) $instance->getID()
            || (int) $outcome->fields['plugin_itilflow_steps_id'] !== (int) $step->getID()
            || (int) $outcome->fields['attempt_id'] !== (int) $step->fields['attempt_id']
            || (string) $outcome->fields['artifact_itemtype'] !== (string) ($step->fields['artifact_itemtype'] ?? '')
            || (int) $outcome->fields['artifact_items_id'] !== (int) $step->fields['artifact_items_id']) {
            return 'Результат этапа недоступен. Обновите страницу.';
        }
        $item = $step->fields['execution_mode'] === Stage::MODE_DELEGATED ? self::child($step) : $parent;
        if ($item === null || Authorizer::itemError($item, READ) !== null) {
            return 'Нет права ответить инициатору.';
        }
        $source = (int) $outcome->fields['users_id'] > 0
            && (int) $outcome->fields['users_id'] === (int) \Session::getLoginUserID();
        $assignee = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
            && Authorizer::itemError($item, UPDATE) === null && Authorizer::isAssignee($item);
        return $source || $assignee ? null : 'Ответить инициатору может тот, кто отметил результат, или исполнитель дочерней заявки.';
    }
}
