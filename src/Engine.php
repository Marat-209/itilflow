<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use CommonITILObject_CommonITILObject;
use CommonITILValidation;
use Group;
use Planning;
use Session;
use ValidationStep;

/**
 * Движок маршрута. Создаёт объекты этапов штатными классами GLPI и двигает
 * маршрут по событиям. Собственных статусов заявки и собственных часов не имеет.
 */
final class Engine
{
    use StructuredFlow;
    /** Защита от рекурсии: объекты, которые создаёт сам движок, не должны его же и триггерить. */
    private static int $inside = 0;

    public static function isInside(): bool
    {
        return self::$inside > 0;
    }

    private static function enter(): void
    {
        self::$inside++;
    }

    private static function leave(): void
    {
        self::$inside = max(0, self::$inside - 1);
    }

    private static function mustUpdate(\CommonDBTM $object, array $input): void
    {
        if (!$object->update($input) || !$object->getFromDB((int) $input['id'])) {
            throw new \RuntimeException('Не удалось сохранить изменение маршрута.');
        }
    }

    private static function mustAdd(\CommonDBTM $object, array $input): int
    {
        $id = (int) $object->add($input);
        if ($id <= 0) {
            throw new \RuntimeException('Не удалось создать часть маршрута.');
        }
        return $id;
    }

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    private static function msg(string $text, int $level = INFO): void
    {
        Session::addMessageAfterRedirect($text, false, $level);
    }

    /**
     * Публичная запись в ленту заявки: инициатор должен видеть, на каком этапе
     * находится его обращение и что уже сделано, не имея доступа к настройкам.
     */
    private static function announce(Instance $instance, string $html): void
    {
        $process = $instance->getProcess();
        if ($process === null || !$process->fields['public_log']) {
            return;
        }
        $item = $instance->getItilItem();
        if ($item === null) {
            return;
        }
        $was_inside = self::$inside;
        self::$inside++;
        try {
            (new \ITILFollowup())->add([
                'itemtype'               => $item->getType(),
                'items_id'               => $item->getID(),
                'content'                => $html,
                'is_private'             => 0,
                '_no_reopen'             => 1,
                '_do_not_compute_status' => 1,
            ]);
        } finally {
            self::$inside = $was_inside;
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    private static function stageLabel(Step $step): string
    {
        return sprintf('Этап %d. %s', (int) $step->fields['ranking'], (string) $step->fields['stage_name']);
    }

    /** Серверная операция сериализуется по экземпляру; повтор читает новое состояние. */
    private static function locked(Instance $instance, callable $operation): bool
    {
        global $DB;
        $scope = null;
        try {
            $scope = new TransactionScope();
            $result = $DB->doQuery('SELECT * FROM ' . $DB->quoteName(Instance::getTable())
                . ' WHERE id = ' . (int) $instance->getID() . ' FOR UPDATE');
            if ((int) $DB->numrows($result) !== 1) {
                $scope->rollback();
                return false;
            }
            $instance->fields = $DB->fetchAssoc($result);
            if (DefinitionSnapshot::enabled($instance)) {
                $type = $instance->fields['itemtype'];
                if (!in_array($type, ['Ticket', 'Change', 'Problem'], true)) {
                    $scope->rollback(); return false;
                }
                // Связанные модели читают SELECT * FOR UPDATE через RuntimeRead/CurrentRead,
                // в том числе при уже существующем внешнем RR read-view.
                $parent_lock = $DB->doQuery('SELECT * FROM ' . $DB->quoteName($type::getTable())
                    . ' WHERE id=' . (int) $instance->fields['items_id'] . ' FOR UPDATE');
                if ((int) $DB->numrows($parent_lock) !== 1) { $scope->rollback(); return false; }
                $DB->doQuery('SELECT * FROM ' . $DB->quoteName(Attempt::getTable())
                    . ' WHERE id=' . (int) $instance->fields['current_attempt_id'] . ' FOR UPDATE');
                $DB->doQuery('SELECT * FROM ' . $DB->quoteName(Step::getTable())
                    . ' WHERE plugin_itilflow_instances_id=' . (int) $instance->getID() . ' ORDER BY id FOR UPDATE');
            }
            $ok = $operation();
            if (!$ok) {
                $scope->rollback();
                return false;
            }
            $scope->commit();
            return true;
        } catch (\Throwable $e) {
            if ($scope !== null) {
                try {
                    $scope->rollback();
                } catch (\Throwable $rollback_error) {
                    // Исход неизвестен; не обещаем откат и не повторяем действие.
                }
            }
            self::msg('Не удалось подтвердить результат действия. Обновите карточку перед новой попыткой.', ERROR);
            return false;
        }
    }

    private static function auditAction(Instance $instance, ?Step $step, string $action, bool $blocked, string $reason = ''): bool
    {
        return Violation::record($instance, (string) $instance->fields['itemtype'],
            (int) $instance->fields['items_id'], 'action_' . $action, $blocked,
            json_encode([
                'action' => $action,
                'step' => $step ? (int) $step->getID() : null,
                'attempt' => $step ? (int) $step->fields['pass_number'] : null,
                'outcome' => $blocked ? 'denied' : 'applied',
                'authority' => $action === 'override'
                    ? (Authorizer::mayBypass() ? 'bypass' : 'process_update') : 'native_actor',
                'reason' => trim(strip_tags($reason)),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function manualStep(Step $step, string $action, string $reason, callable $operation,
        string $command_key = '', int $expected_attempt = 0, array $payload = []): bool
    {
        $instance = $step->getInstance();
        if ($instance === null) {
            return false;
        }
        $denial = null;
        $apply = static function () use ($instance, $step, $action, $reason, $operation, &$denial): bool {
            $denial = Authorizer::stepError($step, $action);
            if ($denial === null && in_array($action, ['complete', 'skip'], true)) {
                $denial = self::validateReport($step, $reason, $action === 'skip');
            }
            if ($denial === null && in_array($action, ['override', 'set_approver', 'reassign'], true)
                && trim(strip_tags($reason)) === '') {
                $denial = 'Укажите причину действия.';
            }
            if ($denial !== null) {
                return false;
            }
            if (!$operation()) {
                return false;
            }
            if (!self::auditAction($instance, $step, $action, false, $reason)) {
                throw new \RuntimeException('Не удалось записать результат действия.');
            }
            return true;
        };
        $ok = DefinitionSnapshot::enabled($instance)
            ? self::manualStructuredCommand($instance, $step, 'step_' . $action, $command_key,
                $expected_attempt, [$step->getID(), $reason, $payload], $apply)
            : self::locked($instance, $apply);
        if ($denial !== null) {
            self::auditAction($instance, $step, $action, true);
            self::msg($denial, ERROR);
        }
        return $ok;
    }

    private static function manualInstance(Instance $instance, string $action, string $reason, callable $operation,
        string $command_key = '', int $expected_attempt = 0): bool
    {
        $denial = null;
        $apply = static function () use ($instance, $action, $reason, $operation, &$denial): bool {
            $denial = Authorizer::instanceError($instance, $action);
            if ($denial === null && in_array($action, ['withdraw', 'abort'], true)
                && trim(strip_tags($reason)) === '') {
                $denial = 'Укажите причину действия.';
            }
            if ($denial !== null || !$operation()) {
                return false;
            }
            if (!self::auditAction($instance, null, $action, false, $reason)) {
                throw new \RuntimeException('Не удалось записать результат действия.');
            }
            return true;
        };
        $ok = DefinitionSnapshot::enabled($instance)
            ? self::manualStructuredCommand($instance, null, 'instance_' . $action, $command_key,
                $expected_attempt, [$reason], $apply)
            : self::locked($instance, $apply);
        if ($denial !== null) {
            self::auditAction($instance, null, $action, true);
            self::msg($denial, ERROR);
        }
        return $ok;
    }

    public static function completeStep(Step $step, string $comment = '', bool $skipped = false, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualStep($step, $skipped ? 'skip' : 'complete', $comment,
            static fn(): bool => self::completeStepInternal($step, $comment, $skipped), $command_key, $expected_attempt);
    }

    /** Привилегированное решение явно отделено от голоса согласующего. */
    public static function overrideStep(Step $step, string $reason, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualStep($step, 'override', $reason,
            static fn(): bool => self::completeStepInternal($step, 'Принудительное завершение: ' . $reason, false, true), $command_key, $expected_attempt);
    }

    public static function designateApprover(Step $step, array $groups_ids, array $users_ids, string $reason, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualStep($step, 'set_approver', $reason,
            static fn(): bool => self::designateApproverInternal($step, $groups_ids, $users_ids, $reason), $command_key, $expected_attempt, [$groups_ids, $users_ids]);
    }

    public static function reassign(Step $step, int $groups_id, int $users_id, string $reason, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualStep($step, 'reassign', $reason,
            static fn(): bool => self::reassignInternal($step, $groups_id, $users_id, $reason), $command_key, $expected_attempt, [$groups_id, $users_id]);
    }

    public static function withdraw(Instance $instance, string $reason, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualInstance($instance, 'withdraw', $reason,
            static fn(): bool => self::withdrawInternal($instance, $reason), $command_key, $expected_attempt);
    }

    public static function abort(Instance $instance, string $reason = '', string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualInstance($instance, 'abort', $reason, static function () use ($instance, $reason): bool {
            self::abortInternal($instance, $reason);
            return $instance->fields['state'] === Instance::ABORTED;
        }, $command_key, $expected_attempt);
    }

    public static function resume(Instance $instance, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualInstance($instance, 'resume', '', static function () use ($instance): bool {
            self::resumeInternal($instance);
            return true;
        }, $command_key, $expected_attempt);
    }

    public static function halt(Instance $instance, string $reason, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualInstance($instance, 'halt', $reason, static function () use ($instance, $reason): bool {
            self::haltInternal($instance, $reason);
            return true;
        }, $command_key, $expected_attempt);
    }

    public static function reopenFrom(Instance $instance, int $stages_id): bool
    {
        if (DefinitionSnapshot::enabled($instance)) { return false; }
        return self::manualInstance($instance, 'reopen', '', static function () use ($instance, $stages_id): bool {
            self::reopenFromInternal($instance, $stages_id);
            return true;
        });
    }

    public static function start(CommonITILObject $item, int $processes_id): ?Instance
    {
        if (($error = Authorizer::startError($item, $processes_id)) !== null) {
            Violation::record(null, $item->getType(), $item->getID(), 'action_start', true, 'Запуск отклонён.');
            self::msg($error, ERROR);
            return null;
        }
        return self::startTransaction($item, $processes_id);
    }

    /** PID берётся только из одноразового свидетельства штатной цепочки правил. */
    public static function startFromRule(CommonITILObject $item): ?Instance
    {
        $processes_id = RuleStart::consume($item);
        $process = new Process();
        if ($processes_id === null || !$process->getFromDB($processes_id)
            || !Authorizer::processFitsItem($process, $item)) {
            Violation::record(null, $item->getType(), $item->getID(), 'action_rule_start', true,
                'Запуск не подтверждён штатным правилом для этого объекта.');
            return null;
        }
        return self::startTransaction($item, $processes_id, true);
    }

    private static function startTransaction(CommonITILObject $item, int $processes_id, bool $from_rule = false): ?Instance
    {
        global $DB;
        $scope = null;
        try {
            $scope = new TransactionScope();
            $observed = $item->fields;
            if (!CurrentRead::load($item, (int) $item->getID())) { $scope->rollback(); return null; }
            foreach (['entities_id', 'status', 'is_deleted', 'name', 'content'] as $field) {
                if (($observed[$field] ?? null) !== ($item->fields[$field] ?? null)) {
                    // Свидетельство правила относится к проверенному предмету, не к конкурентно изменённому.
                    $scope->rollback(); return null;
                }
            }
            if (!$from_rule && Authorizer::startError($item, $processes_id) !== null) { $scope->rollback(); return null; }
            // Уникальная связь instance(itemtype,items_id) сохраняется; конкурентный start не повторяется.
            $instance = self::startInternal($item, $processes_id);
            if ($instance === null) {
                $scope->rollback();
                return null;
            }
            if (!self::auditAction($instance, null, 'start', false)) {
                throw new \RuntimeException('Не удалось записать запуск маршрута.');
            }
            $scope->commit();
            return $instance;
        } catch (\Throwable $e) {
            if ($scope !== null) {
                try {
                    $scope->rollback();
                } catch (\Throwable $rollback_error) {
                    // Подтверждения отката нет. Новый start автоматически не выполняется.
                }
            }
            self::msg('Результат запуска не подтверждён. Обновите карточку перед новой попыткой.', ERROR);
            return null;
        }
    }

    /** Остановка после подтверждённого переноса, без пользовательского system-флага. */
    public static function transferred(CommonITILObject $item): void
    {
        $instance = Instance::getForItem($item);
        $step = $instance?->getCurrentStep();
        $stage = $step?->getStage();
        if ($instance === null || $step === null || $stage === null) {
            return;
        }
        self::locked($instance, static function () use ($instance, $step, $stage, $item): bool {
            if (!$item->getFromDB($item->getID()) || Authorizer::contextError($instance, $step) !== null
                || $stage->groupFitsEntity((int) $item->fields['entities_id'])) {
                return false;
            }
            self::haltInternal($instance, 'после переноса ответственный этапа недоступен');
            return self::auditAction($instance, $step, 'transfer_halt', false);
        });
    }

    /** Событие относится к сохранённой задаче текущей попытки. */
    public static function taskCompleted(\CommonITILTask $event): void
    {
        $task = new ($event->getType())();
        if (!$task->getFromDB($event->getID()) || (int) $task->fields['state'] !== Planning::DONE) {
            return;
        }
        $step = Step::getByArtifact($task->getType(), $task->getID());
        $instance = $step?->getInstance();
        if ($step === null || $instance === null) {
            return;
        }
        self::locked($instance, static function () use ($step, $instance, $task): bool {
            if (Authorizer::stepError($step, 'complete') !== null
                || !CurrentRead::load($task, (int) $task->getID())
                || (int) $task->fields['state'] !== Planning::DONE
                || !empty($task->fields['is_deleted'])
                || $step->fields['artifact_itemtype'] !== $task->getType()
                || (int) $step->fields['artifact_items_id'] !== (int) $task->getID()) {
                return false;
            }
            $item = $instance->getItilItem();
            if ($item === null || (int) ($task->fields[$item::getForeignKeyField()] ?? 0) !== (int) $item->getID()
                || !$task->can($task->getID(), UPDATE)
                || self::validateReport($step, '', false) !== null) {
                return false;
            }
            return self::completeStepInternal($step, 'Задача этапа отмечена выполненной.')
                && self::auditAction($instance, $step, 'task_complete', false);
        });
    }

    /** Совместимость прежних маршрутов: штатный результат сохранённой child. */
    public static function delegatedCompleted(CommonITILObject $event): void
    {
        $bound = Step::getByArtifact($event->getType(), $event->getID())?->getInstance();
        if ($bound !== null && DefinitionSnapshot::enabled($bound)) { return; }
        $child = new ($event->getType())();
        if (!$child->getFromDB($event->getID()) || Authorizer::itemError($child, READ) !== null
            || !in_array((int) $child->fields['status'], array_merge($child::getSolvedStatusArray(), $child::getClosedStatusArray()), true)) {
            return;
        }
        $step = Step::getByArtifact($child->getType(), $child->getID());
        $instance = $step?->getInstance();
        if ($step === null || $instance === null) {
            return;
        }
        self::locked($instance, static function () use ($step, $instance, $child): bool {
            if (DefinitionSnapshot::enabled($instance)
                || Authorizer::contextError($instance, $step) !== null
                || !CurrentRead::load($child, (int) $child->getID())
                || Authorizer::itemError($child, READ) !== null
                || !in_array((int) $child->fields['status'], array_merge($child::getSolvedStatusArray(), $child::getClosedStatusArray()), true)
                || $step->fields['execution_mode'] !== Stage::MODE_DELEGATED
                || $step->fields['artifact_itemtype'] !== $child->getType()
                || (int) $step->fields['artifact_items_id'] !== (int) $child->getID()) {
                return false;
            }
            return self::completeStepInternal($step, 'Связанная заявка решена.')
                && self::auditAction($instance, $step, 'delegated_complete', false);
        });
    }

    public static function validationUpdated(CommonITILValidation $event): void
    {
        $bound = OutcomeValidation::stepFor($event->getType(), $event->getID())
            ?? Step::getByValidation($event->getType(), $event->getID());
        $bound_instance = $bound?->getInstance();
        if ($bound_instance !== null && DefinitionSnapshot::enabled($bound_instance)) {
            self::structuredValidationUpdated($event); return;
        }
        $validation = new ($event->getType())();
        if (!$validation->getFromDB($event->getID())) {
            return;
        }
        $step = Step::getByValidation($validation->getType(), $validation->getID());
        $instance = $step?->getInstance();
        if ($step === null || $instance === null) {
            return;
        }
        self::locked($instance, static function () use ($step, $instance, $validation): bool {
            if (Authorizer::validationError($validation, $step) !== null) {
                return false;
            }
            return self::evaluateApproval($step, strip_tags((string) ($validation->fields['comment_validation'] ?? '')))
                && self::auditAction($instance, $step, 'approval_result', false);
        });
    }

    // ================================================================= запуск

    /** Запустить маршрут на заявке. Возвращает экземпляр или null. */
    /** Не регистратор: только единственный исходный заявитель в начале маршрута. */
    private static function initialRequester(CommonITILObject $item): ?int
    {
        // ITEM_ADD вызывается после native post_addItem; обновляем кэш actors.
        if (!CurrentRead::load($item, (int) $item->getID()) || !CurrentRead::actorsAgree($item)) {
            return null;
        }
        $requesters = [];
        foreach ($item->getUsers(\CommonITILActor::REQUESTER) as $actor) {
            $uid = (int) ($actor['users_id'] ?? 0);
            if ($uid > 0) {
                $requesters[$uid] = true;
            }
        }
        return count($requesters) === 1 ? (int) array_key_first($requesters) : null;
    }

    private static function startInternal(CommonITILObject $item, int $processes_id): ?Instance
    {
        if (Instance::getForItem($item) !== null) {
            return null; // маршрут уже идёт
        }
        $process = new Process();
        if (!$process->getFromDB($processes_id) || !$process->fields['is_active']) {
            return null;
        }
        if ($process->fields['itemtype'] !== $item->getType()) {
            self::msg(sprintf(
                'Маршрут «%s» описан для типа %s и не может быть запущен на %s.',
                $process->fields['name'],
                $process->fields['itemtype'],
                $item->getType()
            ), ERROR);
            return null;
        }
        if (($process->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE) {
            return self::startStructuredInternal($item, $process);
        }
        $stages = $process->getStages();
        if (!count($stages)) {
            self::msg('В маршруте нет ни одного этапа.', ERROR);
            return null;
        }

        self::enter();
        try {
            $instance = new Instance();
            $iid = (int) $instance->add([
                'itemtype'                     => $item->getType(),
                'items_id'                     => $item->getID(),
                'entities_id'                  => (int) $item->fields['entities_id'],
                'plugin_itilflow_processes_id' => $processes_id,
                'process_version'              => (int) $process->fields['version'],
                'state'                        => Instance::RUNNING,
                'date_start'                   => self::now(),
                'users_id_requester_start'     => self::initialRequester($item),
            ]);
            if (!$iid) {
                return null;
            }
            $instance->getFromDB($iid);

            // Снимок определения: правка маршрута не переписывает историю.
            foreach ($stages as $s) {
                self::mustAdd(new Step(), [
                    'plugin_itilflow_instances_id' => $iid,
                    'plugin_itilflow_stages_id'    => (int) $s['id'],
                    'stage_name'                   => (string) $s['name'],
                    'ranking'                      => (int) $s['ranking'],
                    'execution_mode'               => (string) $s['execution_mode'],
                    'approval_percent_snapshot'    => $s['execution_mode'] === Stage::MODE_APPROVAL
                        && (int) $s['approval_percent'] >= 1 && (int) $s['approval_percent'] <= 100
                        ? (int) $s['approval_percent'] : null,
                    'approval_snapshot_source'     => 'definition',
                    'state'                        => Step::PENDING,
                    'pass_number'                  => 1,
                    'users_id'                     => (int) $s['users_id'],
                    'groups_id'                    => (int) $s['groups_id'],
                ]);
            }
        } finally {
            self::leave();
        }

        self::advance($instance);
        return $instance;
    }

    // ============================================================== движение

    /** Открыть следующий ожидающий шаг; если их нет — завершить маршрут. */
    private static function advance(Instance $instance): void
    {
        if ($instance->fields['state'] !== Instance::RUNNING) {
            return;
        }
        if ($instance->getCurrentStep() !== null) {
            return; // текущий шаг ещё не закрыт
        }

        $next = null;
        foreach ($instance->getAttemptSteps() as $row) {
            if ($row['state'] === Step::PENDING) {
                $next = $row;
                break;
            }
        }

        if ($next === null) {
            self::finish($instance);
            return;
        }

        $step = new Step();
        $step->getFromDB((int) $next['id']);
        self::openStep($instance, $step);
    }

    private static function finish(Instance $instance): void
    {
        if (DefinitionSnapshot::enabled($instance)) { self::finishStructured($instance); return; }
        self::enter();
        try {
            self::mustUpdate($instance, [
                'id'                                => $instance->getID(),
                'state'                             => Instance::DONE,
                'plugin_itilflow_stages_id_current' => 0,
                'date_end'                          => self::now(),
            ]);
        } finally {
            self::leave();
        }
        self::msg('Маршрут этапов пройден полностью.');
        self::announce($instance, '<p><strong>Все этапы пройдены.</strong> '
            . 'Заявка передана на закрытие.</p>');
    }

    private static function haltInternal(Instance $instance, string $reason): void
    {
        self::enter();
        try {
            self::mustUpdate($instance, [
                'id'    => $instance->getID(),
                'state' => Instance::HALTED,
            ]);
        } finally {
            self::leave();
        }
        Violation::record(
            $instance,
            $instance->fields['itemtype'],
            (int) $instance->fields['items_id'],
            'halt',
            true,
            $reason
        );
        self::msg('Маршрут остановлен: ' . $reason, ERROR);
        self::announce($instance, sprintf(
            '<p><strong>Обработка приостановлена.</strong></p><p>Причина: %s. '
            . 'Заявка передана ответственному за процесс.</p>',
            htmlescape($reason)
        ));
    }

    private static function resumeInternal(Instance $instance): void
    {
        if (DefinitionSnapshot::enabled($instance)) {
            $attempt = Attempt::current($instance);
            if ($attempt === null || $attempt->fields['state'] !== Attempt::RUNNING) {
                throw new \RuntimeException('После отказа или уточнения требуется отдельная команда попытки.');
            }
            if ($instance->fields['state'] !== Instance::HALTED) { return; }
            // Только техническая пауза той же попытки. Исторические REJECTED не переоткрываются.
            foreach ($instance->getAttemptSteps() as $row) {
                if (!in_array($row['state'], [Step::PENDING, Step::RUNNING, Step::DONE, Step::SKIPPED], true)) {
                    throw new \RuntimeException('Состояние попытки требует отдельной сверки.');
                }
            }
            self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::RUNNING]);
            self::advance($instance);
            return;
        }
        if ($instance->fields['state'] !== Instance::HALTED) {
            return;
        }
        self::enter();
        try {
            self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::RUNNING]);
        } finally {
            self::leave();
        }
        $instance->getFromDB($instance->getID());

        // Маршрут мог встать из-за отклонения. Тогда возобновление означает
        // «открыть отклонённый этап заново», а не «считать маршрут пройденным».
        if ($instance->getCurrentStep() === null) {
            $has_pending = false;
            $rejected = null;
            foreach ($instance->getSteps() as $row) {
                if ($row['state'] === Step::PENDING) {
                    $has_pending = true;
                }
                if ($row['state'] === Step::REJECTED) {
                    $rejected = $row;
                }
            }
            if (!$has_pending && $rejected !== null) {
                self::reopenFromInternal($instance, (int) $rejected['plugin_itilflow_stages_id']);
                return;
            }
        }

        self::advance($instance);
    }

    private static function abortInternal(Instance $instance, string $reason = ''): void
    {
        if (DefinitionSnapshot::enabled($instance)) { self::abortStructured($instance, $reason); return; }
        self::enter();
        try {
            foreach ($instance->getSteps() as $row) {
                if (in_array($row['state'], [Step::PENDING, Step::RUNNING], true)) {
                    self::mustUpdate((new Step()), ['id' => (int) $row['id'], 'state' => Step::SKIPPED]);
                }
            }
            self::mustUpdate($instance, [
                'id'                                => $instance->getID(),
                'state'                             => Instance::ABORTED,
                'plugin_itilflow_stages_id_current' => 0,
                'date_end'                          => self::now(),
            ]);
        } finally {
            self::leave();
        }
        if ($reason !== '') {
            self::msg('Маршрут прерван: ' . $reason);
        }
        self::announce($instance, sprintf(
            '<p><strong>Обработка по регламенту прекращена.</strong>%s</p>',
            $reason !== '' ? ' Причина: ' . htmlescape($reason) . '.' : ''
        ));
    }

    // ========================================================== открытие шага

    private static function openStep(Instance $instance, Step $step): void
    {
        $stage = $step->getStage();
        $item = $instance->getItilItem();
        if ($stage === null || $item === null) {
            self::haltInternal($instance, 'Не найден этап или заявка.');
            return;
        }

        $ok = 'Не удалось открыть этап.';
        $status_note = '';
        self::enter();
        try {
            self::mustUpdate($step, [
                'id'         => $step->getID(),
                'state'      => Step::RUNNING,
                'date_start' => self::now(),
                'date_due'   => self::computeDue($instance, $stage),
                'is_escalated' => 0,
            ]);
            self::mustUpdate($instance, [
                'id'                                => $instance->getID(),
                'plugin_itilflow_stages_id_current' => $stage->getID(),
            ]);

            $ok = match ($step->fields['execution_mode']) {
                Stage::MODE_DELEGATED => self::createDelegated($instance, $step, $stage, $item),
                Stage::MODE_APPROVAL  => self::createApproval($instance, $step, $stage, $item),
                default               => self::createInlineTask($instance, $step, $stage, $item),
            };
            if ($ok === true) {
                if (!DefinitionSnapshot::enabled($instance)) {
                    $status_note = self::applyStageStatus($stage, $item);
                }
            }
        } finally {
            self::leave();
        }

        $instance->getFromDB($instance->getID());

        if ($ok !== true) {
            if (DefinitionSnapshot::enabled($instance)) {
                throw new \RuntimeException('Создание полного состава этапа не подтверждено.');
            }
            self::haltInternal($instance, (string) $ok);
            return;
        }

        if (DefinitionSnapshot::enabled($instance)) { return; }
        $who = self::responsibleLabel($step);
        self::msg(sprintf('Открыт этап «%s». Ответственный: %s.', $stage->fields['name'], $who));

        $total = count($instance->getSteps());
        $done = 0;
        foreach ($instance->getSteps() as $r) {
            if (in_array($r['state'], [Step::DONE, Step::SKIPPED], true)) {
                $done++;
            }
        }
        self::announce($instance, sprintf(
            '<p><strong>%s</strong> — в работе.</p><p>Ответственный: %s.%s</p>%s'
            . '<p><em>Пройдено этапов: %d из %d.</em></p>',
            htmlescape(self::stageLabel($step)),
            htmlescape($who),
            trim(strip_tags((string) $stage->fields['content'])) !== ''
                ? ' ' . htmlescape(trim(strip_tags((string) $stage->fields['content'])))
                : '',
            $status_note !== ''
                ? '<p>' . htmlescape($status_note) . '</p>'
                : '',
            $done,
            $total
        ));
    }

    /**
     * Выставить заявке статус, заданный у этапа.
     *
     * Вызывается только изнутри движка, поэтому собственные запреты плагина
     * (в том числе «Запрещать решение до прохождения маршрута») этот вызов
     * пропускают: они проверяют Engine::isInside().
     *
     * @return string пояснение для ленты заявки, пустая строка — если статус не менялся
     */
    private static function applyStageStatus(Stage $stage, CommonITILObject $item): string
    {
        $target = (int) ($stage->fields['ticket_status'] ?? 0);
        if ($target <= 0) {
            return '';
        }
        $itemtype = $item::class;
        $choices = Stage::getStatusChoices($itemtype);
        // Тип объекта у маршрута могли сменить после настройки этапа —
        // тогда сохранённый статус этому типу уже не подходит.
        if (!array_key_exists($target, $choices)) {
            return '';
        }
        $current = (int) $item->fields['status'];
        if ($current === $target) {
            return '';
        }
        $before = $itemtype::getStatus($current);
        if (!$item->update(['id' => $item->getID(), 'status' => $target])) {
            self::msg(sprintf(
                'Не удалось сменить статус на «%s» — статус остался прежним.',
                $choices[$target]
            ), WARNING);
            return '';
        }
        $item->getFromDB($item->getID());
        return sprintf('Статус: %s → %s.', $before, $choices[$target]);
    }

    /**
     * Срок этапа. Считается по рабочему календарю маршрута — тому же механизму,
     * которым GLPI считает SLA, поэтому ночь и выходные не съедают норматив.
     */
    private static function computeDue(Instance $instance, Stage $stage): ?string
    {
        $minutes = (int) $stage->fields['deadline_minutes'];
        if ($minutes <= 0) {
            return null;
        }
        $start = self::now();
        $process = $instance->getProcess();
        $cal_id = $process ? (int) $process->fields['calendars_id'] : 0;

        if ($cal_id > 0) {
            $cal = new \Calendar();
            if ($cal->getFromDB($cal_id) && $cal->hasAWorkingDay()) {
                return $cal->computeEndDate($start, $minutes * MINUTE_TIMESTAMP);
            }
        }
        return date('Y-m-d H:i:s', strtotime($start) + $minutes * MINUTE_TIMESTAMP);
    }

    /** Просрочен ли шаг прямо сейчас. */
    public static function isOverdue(array $step_row): bool
    {
        if ($step_row['state'] !== Step::RUNNING || empty($step_row['date_due'])) {
            return false;
        }
        return strtotime((string) $step_row['date_due']) < time();
    }

    private static function responsibleLabel(Step $step): string
    {
        if ((int) $step->fields['groups_id'] > 0) {
            $g = new Group();
            if ($g->getFromDB((int) $step->fields['groups_id'])) {
                return $g->fields['name'];
            }
        }
        if ((int) $step->fields['users_id'] > 0) {
            return getUserName((int) $step->fields['users_id']);
        }
        return 'не назначен';
    }

    /** Этап-задача внутри той же заявки. */
    private static function createInlineTask(Instance $i, Step $step, Stage $stage, CommonITILObject $item): bool|string
    {
        $taskclass = $item::getTaskClass();
        if (!class_exists($taskclass)) {
            return 'Для типа ' . $item->getType() . ' нет класса задач.';
        }
        $fk = $item::getForeignKeyField();
        $task = new $taskclass();
        $content = trim((string) $stage->fields['content']) !== ''
            ? $stage->fields['content']
            : $stage->fields['name'];

        $tid = $task->add([
            $fk               => $item->getID(),
            'content'         => sprintf('<p><strong>Этап %d. %s</strong></p>%s',
                (int) $step->fields['ranking'], $stage->fields['name'], $content),
            'state'           => Planning::TODO,
            'groups_id_tech'  => (int) $stage->fields['groups_id'],
            'users_id_tech'   => (int) $stage->fields['users_id'],
            'actiontime'      => (int) $stage->fields['duration'],
            // Инициатору внутренняя задача не нужна: он видит журнал этапов.
            // Исполнителям она видна — у профилей техников есть право
            // «Видеть личные задачи».
            'is_private'      => 1,
            '_do_not_compute_status' => 1,
        ]);
        if (!$tid) {
            return 'Не удалось создать задачу этапа.';
        }
        $attached = self::attachGroup($item, (int) $stage->fields['groups_id']);
        self::mustUpdate($step, [
            'id'                => $step->getID(),
            'artifact_itemtype' => $taskclass,
            'artifact_items_id' => (int) $tid,
            'group_attached'    => $attached ? 1 : 0,
        ]);
        return true;
    }

    /**
     * Исполнитель этапа должен видеть заявку, иначе работать с задачей он
     * не сможет: видимость в GLPI даёт участие в заявке, а не задача.
     * Поэтому на время этапа группа становится исполнителем заявки.
     */
    private static function attachGroup(CommonITILObject $item, int $groups_id): bool
    {
        if ($groups_id <= 0) {
            return false;
        }
        $linkclass = $item->getActorObjectForItem(Group::class);
        if (!is_object($linkclass)) {
            return false;
        }
        $fk = $item::getForeignKeyField();
        $crit = [$fk => $item->getID(), 'groups_id' => $groups_id, 'type' => \CommonITILActor::ASSIGN];
        if (count($linkclass->find($crit, [], 1))) {
            return false; // группа и так была исполнителем — не нами добавлена
        }
        return (bool) $linkclass->add($crit);
    }

    private static function detachGroup(CommonITILObject $item, int $groups_id): void
    {
        if ($groups_id <= 0) {
            return;
        }
        $linkclass = $item->getActorObjectForItem(Group::class);
        if (!is_object($linkclass)) {
            return;
        }
        $fk = $item::getForeignKeyField();
        foreach ($linkclass->find([
            $fk => $item->getID(), 'groups_id' => $groups_id, 'type' => \CommonITILActor::ASSIGN,
        ]) as $id => $row) {
            $linkclass->delete(['id' => $id], true);
        }
    }

    /** Этап-дочерняя заявка, возможно в другой организационной единице. */
    private static function createDelegated(Instance $i, Step $step, Stage $stage, CommonITILObject $item): bool|string
    {
        if (DefinitionSnapshot::enabled($i)) { return self::createStructuredChild($i, $step, $stage, $item); }
        $target_entity = $stage->resolveEntity($item);

        // GLPI не проверяет это сама: модель принимает группу чужой сущности молча.
        if (!$stage->groupFitsEntity($target_entity)) {
            $g = new Group();
            $g->getFromDB((int) $stage->fields['groups_id']);
            $e = new \Entity();
            $e->getFromDB($target_entity);
            return sprintf(
                'Группа «%s» не принадлежит сущности «%s» и не видна в ней. Исправьте настройку этапа.',
                $g->fields['name'] ?? '?',
                $e->fields['completename'] ?? $target_entity
            );
        }

        $class = $item->getType();
        $child = new $class();
        $input = [
            'name'        => sprintf('[Этап %d] %s — %s',
                (int) $step->fields['ranking'],
                $stage->fields['name'],
                mb_substr((string) $item->fields['name'], 0, 60)),
            'content'     => trim((string) $stage->fields['content']) !== ''
                ? $stage->fields['content']
                : sprintf('Этап %d маршрута по заявке №%d.', (int) $step->fields['ranking'], $item->getID()),
            'entities_id' => $target_entity,
            // Статус считаем штатно: у заявки этапа сразу есть исполнитель,
            // поэтому она должна стать «в работе», а не висеть «новой».
            // Иначе её видят все техники по праву «Просмотреть новые заявки».
        ];
        if ((int) $stage->fields['itilcategories_id'] > 0) {
            $input['itilcategories_id'] = (int) $stage->fields['itilcategories_id'];
        }
        if ((int) $stage->fields['slas_id'] > 0 && $class === 'Ticket') {
            $input['slas_id_ttr'] = (int) $stage->fields['slas_id'];
        }
        if ((int) $stage->fields['groups_id'] > 0) {
            $input['_groups_id_assign'] = (int) $stage->fields['groups_id'];
        }
        if ((int) $stage->fields['users_id'] > 0) {
            $input['_users_id_assign'] = (int) $stage->fields['users_id'];
        }
        // Инициатора на дочернюю заявку НЕ переносим: для него этап — внутренняя
        // работа, он следит за ходом по журналу родительской заявки. Иначе одно
        // обращение выглядело бы в его списке как две разные заявки.

        $cid = $child->add($input);
        if (!$cid) {
            return 'Не удалось создать дочернюю заявку этапа в сущности #' . $target_entity . '.';
        }

        $linkclass = CommonITILObject_CommonITILObject::getLinkClass($class, $class);
        if ($linkclass !== null) {
            // Ticket_Ticket / Change_Change / Problem_Problem используют парные поля.
            $link = new $linkclass();
            $pair = self::linkFields($class);
            $link->add([
                $pair[0] => (int) $cid,
                $pair[1] => $item->getID(),
                'link'   => CommonITILObject_CommonITILObject::SON_OF,
            ]);
        }

        self::mustUpdate($step, [
            'id'                => $step->getID(),
            'artifact_itemtype' => $class,
            'artifact_items_id' => (int) $cid,
        ]);
        return true;
    }

    private static function linkFields(string $class): array
    {
        return match ($class) {
            'Change'  => ['changes_id_1', 'changes_id_2'],
            'Problem' => ['problems_id_1', 'problems_id_2'],
            default   => ['tickets_id_1', 'tickets_id_2'],
        };
    }

    /** Этап-согласование через штатный механизм GLPI. */
    private static function createApproval(Instance $i, Step $step, Stage $stage, CommonITILObject $item): bool|string
    {
        $val = $item::getValidationClassInstance();
        if ($val === null) {
            return 'Для типа ' . $item->getType() . ' согласование не поддерживается.';
        }
        $fk = $item::getForeignKeyField();

        $source = (string) ($stage->fields['approver_source'] ?? Stage::APPROVER_FIXED);

        // Согласующего указывает исполнитель уже по факту обращения — например
        // когда владелец ресурса известен только по внешнему реестру. Запрос
        // здесь не создаём: этап открывается и ждёт указания.
        if ($source === Stage::APPROVER_RUNTIME) {
            self::announce($i, sprintf(
                '<p><strong>%s</strong> — ожидает указания согласующего.</p>'
                . '<p>Согласующего на этом этапе назначает исполнитель.</p>',
                htmlescape(self::stageLabel($step))
            ));
            return true;
        }

        $target_type = (int) $stage->fields['groups_id'] > 0 ? Group::class : \User::class;
        $target_id = $target_type === Group::class
            ? (int) $stage->fields['groups_id']
            : (int) $stage->fields['users_id'];
        if ($target_id <= 0) {
            return 'У этапа согласования не указан согласующий.';
        }

        $res = self::openValidation($step, $stage, $item, $target_type, $target_id);
        if (is_string($res)) {
            return $res;
        }
        self::bindValidations($step, get_class($item::getValidationClassInstance()), [$res]);
        return true;
    }

    /**
     * Создать штатный запрос на согласование конкретному адресату и привязать
     * его к шагу. Общий код для этапа с заданным согласующим и для случая,
     * когда согласующего указали при прохождении.
     */
    private static function openValidation(
        Step $step,
        Stage $stage,
        CommonITILObject $item,
        string $target_type,
        int $target_id
    ): int|string {
        $percent = (int) ($step->fields['approval_percent_snapshot'] ?? 0);
        if ($percent < 1 || $percent > 100
            || ($target_type === \User::class && !Authorizer::userFitsItem($target_id, $item))
            || ($target_type === Group::class && !self::groupFitsItem($target_id, $item))
            || !in_array($target_type, [\User::class, Group::class], true)) {
            return 'Не подтверждены порог или адресат согласования.';
        }
        $val = $item::getValidationClassInstance();
        if ($val === null) {
            return 'Для типа ' . $item->getType() . ' согласование не поддерживается.';
        }
        $fk = $item::getForeignKeyField();

        $input = [
            $fk                  => $item->getID(),
            'entities_id'        => (int) $item->fields['entities_id'],
            'itemtype_target'    => $target_type,
            'items_id_target'    => $target_id,
            'comment_submission' => sprintf(
                'Согласование этапа %d «%s».%s',
                (int) $step->fields['ranking'],
                $stage->fields['name'],
                trim((string) $stage->fields['content']) !== ''
                    ? ' ' . strip_tags((string) $stage->fields['content']) : ''
            ),
        ];
        $vs = self::ensureValidationStep($percent);
        if ($vs > 0) {
            $input['_validationsteps_id'] = $vs;
        }

        $vid = (int) $val->add($input);
        if (!$vid) {
            return 'Не удалось создать запрос на согласование.';
        }
        return $vid;
    }

    /** Привязать к шагу созданные объекты согласования. */
    private static function bindValidations(Step $step, string $itemtype, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if (!count($ids)) {
            return;
        }
        $csv = implode(',', $ids);
        if (strlen($csv) > 255 || count(array_unique($ids)) !== count($ids)) {
            throw new \RuntimeException('Не удалось сохранить полный состав согласования.');
        }
        if (!$step->update([
            'id'                 => $step->getID(),
            'approval_expected_count' => count($ids),
            'artifact_itemtype'  => $itemtype,
            'artifact_items_id'  => $ids[0],
            'approver_items_ids' => $csv,
        ]) || !$step->getFromDB($step->getID()) || $step->validationRoster() === null) {
            throw new \RuntimeException('Не удалось закрепить состав согласования.');
        }
    }

    /**
     * Решить судьбу этапа-согласования по всем его запросам.
     *
     * На этапе может быть несколько отдельных согласующих — например
     * руководитель заявителя и владелец ресурса. Этап пройден, когда доля
     * согласовавших дошла до порога; отклонён, когда порога уже не достичь.
     */
    private static function evaluateApproval(Step $step, string $comment = ''): bool
    {
        global $DB;
        $instance = $step->getInstance();
        if ($instance === null || Authorizer::contextError($instance, $step) !== null
            || $step->fields['execution_mode'] !== Stage::MODE_APPROVAL) {
            return false;
        }
        $item = $instance->getItilItem();
        $expected_class = $item?->getValidationClassInstance();
        $ids = $step->validationIds();
        if (!count($ids)) {
            return false;
        }
        $type = (string) $step->fields['artifact_itemtype'];
        if ($item === null || $expected_class === null || $type !== get_class($expected_class)) {
            return false;
        }
        $roster = $step->validationRoster();
        if ($roster === null) {
            return false;
        }
        $total = 0;
        $accepted = 0;
        $refused = 0;
        foreach ($roster as $row) {
            if ((int) $row[$item::getForeignKeyField()] !== (int) $item->getID()
                || (int) $row['entities_id'] !== (int) $item->fields['entities_id']) {
                return false;
            }
            $total++;
            if ((int) $row['status'] === CommonITILValidation::ACCEPTED) {
                $accepted++;
            } elseif ((int) $row['status'] === CommonITILValidation::REFUSED) {
                $refused++;
            }
        }
        if ($total !== count($ids)) {
            return false;
        }
        $need = (int) ($step->fields['approval_percent_snapshot'] ?? 0);
        if ($need < 1 || $need > 100) {
            return false;
        }

        $accepted_pct = (int) floor(100 * $accepted / $total);
        // Максимум, которого ещё можно достичь, если все неответившие согласуют.
        $possible_pct = (int) floor(100 * ($total - $refused) / $total);

        if ($accepted_pct >= $need) {
            return self::completeStepInternal($step, sprintf(
                'Согласовано (%d из %d, порог %d%%). %s', $accepted, $total, $need, $comment
            ));
        }
        if ($possible_pct < $need) {
            self::rejectStepInternal($step, sprintf(
                'Не согласовано: отказов %d из %d, порог %d%% недостижим. %s',
                $refused, $total, $need, $comment
            ));
            return true;
        }
        // Порог ещё достижим — ждём остальных.
        $left = $total - $accepted - $refused;
        self::announce($step->getInstance(), sprintf(
            '<p><strong>%s</strong> — получен ответ согласующего.</p>'
            . '<p>Согласовали %d из %d, порог %d%%. Ожидаем ещё %d.</p>',
            htmlescape(self::stageLabel($step)),
            $accepted,
            $total,
            $need,
            $left
        ));
        return true;
    }

    /**
     * Указать согласующего на этапе, который его ждёт.
     *
     * Нужно там, где согласующий известен только по факту обращения: например
     * владелец сетевого ресурса числится во внешнем реестре, и диспетчер
     * находит его сам. Основание выбора обязательно и остаётся в истории.
     */
    /**
     * Указать согласующих на этапе, который их ждёт.
     *
     * Нужно там, где согласующий известен только по факту обращения: например
     * руководителя заявителя и владельца сетевого ресурса диспетчер находит
     * сам, каждого по своему источнику. Согласующих может быть несколько,
     * и каждый согласует отдельно — по порогу этапа.
     *
     * @param int[] $users_ids
     * @param int[] $groups_ids
     */
    private static function designateApproverInternal(
        Step $step,
        array $groups_ids,
        array $users_ids,
        string $reason
    ): bool {
        $instance = $step->getInstance();
        $stage = $step->getStage();
        $item = $instance?->getItilItem();
        if ($instance === null || $stage === null || $item === null) {
            return false;
        }
        if (!$step->awaitsApprover()) {
            self::msg('Этот этап не ждёт указания согласующего.', ERROR);
            return false;
        }

        foreach (array_merge($groups_ids, $users_ids) as $id) {
            if (!is_int($id) || $id <= 0) {
                return false;
            }
        }
        $groups_ids = array_values(array_unique($groups_ids));
        $users_ids = array_values(array_unique($users_ids));
        if (!count($groups_ids) && !count($users_ids)) {
            self::msg('Укажите хотя бы одного согласующего: сотрудника или группу.', ERROR);
            return false;
        }
        if (trim($reason) === '') {
            self::msg(
                'Укажите основание выбора согласующего — оно попадёт в лист согласования.',
                ERROR
            );
            return false;
        }

        foreach ($users_ids as $uid) {
            if (!Authorizer::userFitsItem($uid, $item)) {
                return false;
            }
        }
        foreach ($groups_ids as $gid) {
            if (!self::groupFitsItem($gid, $item)) {
                return false;
            }
        }

        $targets = [];
        foreach ($users_ids as $uid) {
            $targets[] = [\User::class, $uid];
        }
        foreach ($groups_ids as $gid) {
            $targets[] = [Group::class, $gid];
        }

        $created = [];
        $vclass = get_class($item::getValidationClassInstance());
        self::enter();
        try {
            foreach ($targets as [$ttype, $tid]) {
                $res = self::openValidation($step, $stage, $item, $ttype, $tid);
                if (is_string($res)) {
                    self::msg($res, ERROR);
                    throw new \RuntimeException('Не удалось создать полный состав согласования.');
                }
                $created[] = (int) $res;
            }
            if (!count($created)) {
                self::msg('Ни одного запроса на согласование создать не удалось.', ERROR);
                return false;
            }
            self::bindValidations($step, $vclass, $created);
            // Ответственным у шага пишем группу, если она одна и сотрудников нет,
            // иначе первого сотрудника: колонки под список ответственных нет,
            // а полный состав виден по запросам согласования и в листе.
            self::mustUpdate($step, [
                'id'              => $step->getID(),
                'groups_id'       => (count($groups_ids) === 1 && !count($users_ids))
                    ? $groups_ids[0] : 0,
                'users_id'        => count($users_ids) === 1 ? $users_ids[0] : 0,
                'approver_set_by' => (int) (Session::getLoginUserID() ?: 0),
                'approver_reason' => trim($reason),
            ]);
        } finally {
            self::leave();
        }

        $step->getFromDB($step->getID());
        $names = [];
        foreach ($users_ids as $uid) {
            $names[] = getUserName($uid);
        }
        foreach ($groups_ids as $gid) {
            $names[] = \Dropdown::getDropdownName('glpi_groups', $gid);
        }
        $who = implode(', ', $names);
        $by = Session::getLoginUserID()
            ? getUserName((int) Session::getLoginUserID()) : 'система';
        $need = (int) $step->fields['approval_percent_snapshot'];

        self::msg(sprintf(
            count($created) > 1
                ? 'Согласующие указаны (%d): %s.'
                : 'Согласующий указан: %2$s.',
            count($created),
            $who
        ));
        self::announce($instance, sprintf(
            '<p><strong>%s</strong> — %s.</p><p>Согласует: %s.</p>'
            . '%s<p>Указал: %s. Основание: %s</p>',
            htmlescape(self::stageLabel($step)),
            count($created) > 1 ? 'согласующие указаны' : 'согласующий указан',
            htmlescape($who),
            count($created) > 1
                ? sprintf('<p>Каждый согласует отдельно, порог этапа — %d%%.</p>', $need)
                : '',
            htmlescape($by),
            htmlescape(trim($reason))
        ));
        return true;
    }

    /** Ступень согласования с нужным порогом; создаётся один раз и переиспользуется. */
    private static function ensureValidationStep(int $percent): int
    {
        if ($percent < 1 || $percent > 100) {
            throw new \RuntimeException('Недопустимый порог согласования.');
        }
        $name = sprintf('Этап маршрута — порог %d%%', $percent);
        $vs = new ValidationStep();
        $found = $vs->find(['name' => $name, 'minimal_required_validation_percent' => $percent], [], 2);
        if (count($found) > 1) {
            throw new \RuntimeException('Неоднозначная ступень согласования.');
        }
        if (count($found)) {
            return (int) array_key_first($found);
        }
        $id = self::mustAdd($vs, [
            'name' => $name,
            'minimal_required_validation_percent' => $percent,
            'is_default' => 0,
        ]);
        return $id;
    }

    // ========================================================= закрытие шага

    /**
     * Проверка отчёта о выполнении. Возвращает текст ошибки или null.
     * Живёт в движке, а не в форме, чтобы правило действовало и для API.
     */
    public static function validateReport(Step $step, string $report, bool $skip): ?string
    {
        $stage = $step->getStage();
        $report = trim($report);

        if ($skip) {
            if ($stage === null || !$stage->fields['is_optional']) {
                return 'Этот этап нельзя пропустить.';
            }
            if ($report === '') {
                return 'Укажите причину пропуска этапа в поле «Что сделано».';
            }
            return null;
        }

        if ($stage !== null
            && $stage->fields['completion_comment'] === 'required'
            && $report === '') {
            return 'Для этого этапа отчёт о выполнении обязателен: опишите, что сделано.';
        }
        return null;
    }

    /** Закрыть текущий шаг и перейти к следующему. */
    private static function completeStepInternal(Step $step, string $comment = '', bool $skipped = false, bool $override = false): bool
    {
        $instance = $step->getInstance();
        if ($instance === null || Authorizer::contextError($instance, $step) !== null) {
            return false;
        }
        if (DefinitionSnapshot::enabled($instance) && $step->fields['execution_mode'] !== Stage::MODE_INLINE) {
            if (!$override || StructuredAccess::context($step) === null) { return false; }
            if (StructuredAccess::nativeAggregate($step) !== null) {
                // Уже сохранённый окончательный штатный ответ сначала переносится, не заменяется override.
                return false;
            }
            $key = hash('sha256', 'override:' . $step->getID());
            $outcome = self::recordStructuredOutcome($instance, $step, Outcome::COMPLETED, 'privileged_override',
                '', 0, (int) Session::getLoginUserID(), $comment, $comment, $key);
            self::applyStructuredOutcome($instance, $step, $outcome);
            return true;
        }
        $start = strtotime((string) ($step->fields['date_start'] ?? self::now()));
        $dur = max(0, time() - ($start ?: time()));

        self::enter();
        try {
            self::mustUpdate($step, [
                'id'              => $step->getID(),
                'state'           => $skipped ? Step::SKIPPED : Step::DONE,
                'date_end'        => self::now(),
                'duration'        => $dur,
                'comment'         => $comment,
                'users_id_actual' => (int) (Session::getLoginUserID() ?: 0),
            ]);
            // Закрываем связанную задачу, если её закрыл не сам исполнитель.
            self::closeArtifact($step);
            // Снимаем с заявки группу, добавленную на время этапа.
            if ((int) $step->fields['group_attached'] === 1) {
                $it = $instance->getItilItem();
                if ($it !== null) {
                    self::detachGroup($it, (int) $step->fields['groups_id']);
                }
            }
        } finally {
            self::leave();
        }

        $instance->getFromDB($instance->getID());

        $actor = Session::getLoginUserID() ? getUserName((int) Session::getLoginUserID()) : 'система';
        $text = trim(strip_tags($comment));
        self::announce($instance, sprintf(
            '<p><strong>%s</strong> — %s.</p><p>Исполнитель: %s.</p>%s',
            htmlescape(self::stageLabel($step)),
            $skipped ? 'пропущен' : 'выполнен',
            htmlescape($actor),
            $text !== '' ? '<p>' . ($skipped ? 'Причина: ' : 'Что сделано: ') . htmlescape($text) . '</p>' : ''
        ));

        self::advance($instance);
        return true;
    }

    private static function closeArtifact(Step $step): void
    {
        $type = (string) $step->fields['artifact_itemtype'];
        $id = (int) $step->fields['artifact_items_id'];
        if ($type === '' || $id <= 0 || !class_exists($type)) {
            return;
        }
        if (is_a($type, \CommonITILTask::class, true)) {
            $t = new $type();
            if ($t->getFromDB($id) && (int) $t->fields['state'] !== Planning::DONE) {
                self::mustUpdate($t, ['id' => $id, 'state' => Planning::DONE]);
            }
        }
    }

    /** Согласование отклонено. */
    private static function rejectStepInternal(Step $step, string $comment = ''): void
    {
        $instance = $step->getInstance();
        $stage = $step->getStage();
        if ($instance === null || $stage === null) {
            return;
        }

        self::enter();
        try {
            self::mustUpdate($step, [
                'id'       => $step->getID(),
                'state'    => Step::REJECTED,
                'date_end' => self::now(),
                'comment'  => $comment,
            ]);
        } finally {
            self::leave();
        }
        $instance->getFromDB($instance->getID());

        $text = trim(strip_tags($comment));
        self::announce($instance, sprintf(
            '<p><strong>%s</strong> — не согласовано.</p>%s',
            htmlescape(self::stageLabel($step)),
            $text !== '' ? '<p>Комментарий согласующего: ' . htmlescape($text) . '</p>' : ''
        ));

        switch ($stage->fields['on_reject']) {
            case Stage::REJECT_ABORT:
                self::abortInternal($instance, 'этап «' . $stage->fields['name'] . '» отклонён');
                break;

            case Stage::REJECT_BACK:
                $back = (int) $stage->fields['plugin_itilflow_stages_id_reject'];
                self::reopenFromInternal($instance, $back);
                break;

            case Stage::REJECT_BLOCK:
            default:
                self::haltInternal($instance, 'этап «' . $stage->fields['name'] . '» отклонён согласующим');
                break;
        }
    }

    /** Вернуть маршрут на указанный этап: создаём новый проход. */
    private static function reopenFromInternal(Instance $instance, int $stages_id): void
    {
        $target = null;
        foreach ($instance->getSteps() as $row) {
            if ((int) $row['plugin_itilflow_stages_id'] === $stages_id) {
                $target = $row;
            }
        }
        if ($target === null) {
            self::haltInternal($instance, 'этап для возврата не найден');
            return;
        }

        $pass = 0;
        foreach ($instance->getSteps() as $row) {
            $pass = max($pass, (int) $row['pass_number']);
        }
        if ($pass >= 10) {
            self::haltInternal($instance, 'превышено число возвратов на доработку');
            return;
        }

        self::enter();
        try {
            // Новый проход: копии этапов начиная с целевого.
            $stages = [];
            foreach ((new Stage())->find(
                ['plugin_itilflow_processes_id' => (int) $instance->fields['plugin_itilflow_processes_id']],
                ['ranking ASC']
            ) as $sid => $s) {
                if ((int) $s['ranking'] >= (int) $target['ranking']) {
                    $s['id'] = $sid;
                    $stages[] = $s;
                }
            }
            foreach ($stages as $s) {
                self::mustAdd(new Step(), [
                    'plugin_itilflow_instances_id' => $instance->getID(),
                    'plugin_itilflow_stages_id'    => (int) $s['id'],
                    'stage_name'                   => (string) $s['name'],
                    'ranking'                      => (int) $s['ranking'],
                    'execution_mode'               => (string) $s['execution_mode'],
                    'approval_percent_snapshot'    => $s['execution_mode'] === Stage::MODE_APPROVAL
                        && (int) $s['approval_percent'] >= 1 && (int) $s['approval_percent'] <= 100
                        ? (int) $s['approval_percent'] : null,
                    'approval_snapshot_source'     => 'definition',
                    'state'                        => Step::PENDING,
                    'pass_number'                  => $pass + 1,
                    'users_id'                     => (int) $s['users_id'],
                    'groups_id'                    => (int) $s['groups_id'],
                ]);
            }
            // Остальные шаги текущего прохода помечаем пропущенными.
            foreach ($instance->getSteps() as $row) {
                if ((int) $row['pass_number'] <= $pass && $row['state'] === Step::PENDING) {
                    self::mustUpdate((new Step()), ['id' => (int) $row['id'], 'state' => Step::SKIPPED]);
                }
            }
        } finally {
            self::leave();
        }

        $instance->getFromDB($instance->getID());
        self::msg('Маршрут возвращён на доработку.');
        self::advance($instance);
    }

    // ========================================================== переназначение

    /**
     * Передать текущий этап другому ответственному. В листе согласования
     * остаётся отметка: кто был назначен по регламенту и кто фактически принял.
     */
    private static function reassignInternal(Step $step, int $groups_id, int $users_id, string $reason): bool
    {
        $instance = $step->getInstance();
        $stage = $step->getStage();
        $item = $instance?->getItilItem();
        if ($instance === null || $stage === null || $item === null) {
            return false;
        }
        if ($step->fields['state'] !== Step::RUNNING) {
            self::msg('Переназначить можно только текущий этап.', ERROR);
            return false;
        }
        if ($groups_id <= 0 && $users_id <= 0) {
            self::msg('Укажите нового ответственного: группу или пользователя.', ERROR);
            return false;
        }
        if (trim($reason) === '') {
            self::msg('Укажите причину переназначения.', ERROR);
            return false;
        }
        if ($users_id > 0 && !Authorizer::userFitsItem($users_id, $item)) {
            return false;
        }
        if ($groups_id > 0 && !self::groupFitsItem($groups_id, $item)) {
            self::msg('Выбранная группа не видит эту заявку: она из другой организационной единицы.', ERROR);
            return false;
        }

        $old_g = (int) $step->fields['groups_id'];
        $old_u = (int) $step->fields['users_id'];

        self::enter();
        try {
            self::mustUpdate($step, [
                'id'              => $step->getID(),
                'groups_id'       => $groups_id,
                'users_id'        => $users_id,
                'groups_id_from'  => $step->fields['groups_id_from'] ?: $old_g,
                'users_id_from'   => $step->fields['users_id_from'] ?: $old_u,
                'reassign_reason' => trim($reason),
            ]);

            // задачу этапа передаём вместе с этапом
            $type = (string) $step->fields['artifact_itemtype'];
            $aid = (int) $step->fields['artifact_items_id'];
            if ($aid > 0 && $type !== '' && is_a($type, \CommonITILTask::class, true)) {
                $t = new $type();
                if ($t->getFromDB($aid)) {
                    self::mustUpdate($t, ['id' => $aid, 'groups_id_tech' => $groups_id, 'users_id_tech' => $users_id]);
                }
            }

            if ($old_g > 0 && $old_g !== $groups_id) {
                self::detachGroup($item, $old_g);
            }
            if ($groups_id > 0) {
                self::attachGroup($item, $groups_id);
            }
        } finally {
            self::leave();
        }

        $step->getFromDB($step->getID());
        $to = self::responsibleLabel($step);
        self::msg(sprintf('Этап передан: %s.', $to));
        self::announce($instance, sprintf(
            '<p><strong>%s</strong> — передан другому исполнителю.</p>'
            . '<p>Было: %s. Стало: %s.</p><p>Причина: %s</p>',
            htmlescape(self::stageLabel($step)),
            htmlescape(self::nameOf($old_g, $old_u)),
            htmlescape($to),
            htmlescape(trim($reason))
        ));
        return true;
    }

    private static function nameOf(int $groups_id, int $users_id): string
    {
        if ($groups_id > 0) {
            $g = new Group();
            if ($g->getFromDB($groups_id)) {
                return $g->fields['name'];
            }
        }
        if ($users_id > 0) {
            return getUserName($users_id);
        }
        return 'не назначен';
    }

    /** Видна ли группа в сущности заявки. */
    public static function groupFitsItem(int $groups_id, CommonITILObject $item): bool
    {
        $g = new Group();
        if (!CurrentRead::load($g, $groups_id)) {
            return false;
        }
        $ge = (int) $g->fields['entities_id'];
        $ie = (int) $item->fields['entities_id'];
        if ($ge === $ie) {
            return true;
        }
        return (bool) $g->fields['is_recursive']
            && in_array($ge, array_map('intval', getAncestorsOf('glpi_entities', $ie)), true);
    }

    // ================================================================== отзыв

    /** Инициатор отзывает заявку: маршрут прекращается, заявку можно закрыть. */
    private static function withdrawInternal(Instance $instance, string $reason): bool
    {
        $process = $instance->getProcess();
        if ($process === null || !$process->fields['allow_withdraw']) {
            self::msg('Для этого процесса отзыв заявки не предусмотрен.', ERROR);
            return false;
        }
        if (!in_array($instance->fields['state'], [Instance::RUNNING, Instance::HALTED], true)) {
            return false;
        }
        if (trim($reason) === '') {
            self::msg('Укажите причину отзыва.', ERROR);
            return false;
        }

        if (DefinitionSnapshot::enabled($instance)) {
            // Обязательная проверенная публичная запись входит в ту же транзакцию.
            self::abortStructured($instance, 'Отозвано исходным инициатором. ' . trim(strip_tags($reason)));
            return true;
        }

        $who = Session::getLoginUserID() ? getUserName((int) Session::getLoginUserID()) : 'инициатор';
        self::abortInternal($instance, '');
        self::announce($instance, sprintf(
            '<p><strong>Заявка отозвана инициатором.</strong></p><p>Отозвал: %s.</p><p>Причина: %s</p>',
            htmlescape($who),
            htmlescape(trim($reason))
        ));
        self::msg('Заявка отозвана, обработка по регламенту прекращена.');
        return true;
    }

    // ============================================================ эскалация

    /** Отметить просроченный шаг и уведомить. Вызывается автоматическим действием. */
    public static function escalate(Step $step): bool
    {
        $instance = $step->getInstance();
        $item = $instance?->getItilItem();
        if ($instance === null || $item === null || Authorizer::contextError($instance, $step) !== null
            || !empty($step->fields['is_escalated']) || !self::isOverdue($step->fields)) {
            return false;
        }
        $cron = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? '') === Cron::class
                && ($frame['function'] ?? '') === 'cronItilflow_deadlines') {
                $cron = true;
                break;
            }
        }
        if (!$cron && (!Authorizer::isAdministrator() || Authorizer::itemError($item, UPDATE) !== null)) {
            return false;
        }
        return self::locked($instance, static function () use ($instance, $step, $item): bool {
            if (Authorizer::contextError($instance, $step) !== null
                || !empty($step->fields['is_escalated']) || !self::isOverdue($step->fields)) {
                return false;
            }
            return self::escalateInternal($instance, $step, $item);
        });
    }

    private static function escalateInternal(Instance $instance, Step $step, CommonITILObject $item): bool
    {
        self::enter();
        try {
            self::mustUpdate($step, ['id' => $step->getID(), 'is_escalated' => 1]);
            (new \ITILFollowup())->add([
                'itemtype'   => $item->getType(),
                'items_id'   => $item->getID(),
                'content'    => sprintf(
                    '<p><strong>Просрочен %s.</strong></p><p>Срок истёк %s. Ответственный: %s.</p>',
                    htmlescape(self::stageLabel($step)),
                    htmlescape(\Html::convDateTime((string) $step->fields['date_due'])),
                    htmlescape(self::responsibleLabel($step))
                ),
                'is_private' => 1,
                '_no_reopen' => 1,
                '_do_not_compute_status' => 1,
            ]);
        } finally {
            self::leave();
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        Violation::record(
            $instance,
            $item->getType(),
            $item->getID(),
            'deadline',
            false,
            sprintf('Просрочен %s. Срок: %s. Ответственный: %s.',
                self::stageLabel($step),
                (string) $step->fields['date_due'],
                self::responsibleLabel($step))
        );
        return true;
    }

    // ============================================================== контроль

    /** Режим контроля маршрута. */
    public static function enforcement(Instance $instance): string
    {
        $p = $instance->getProcess();
        return $p ? (string) $p->fields['enforcement'] : Process::ENFORCE_STRICT;
    }

    /** Право обойти контроль (для диспетчера / администратора процесса). */
    public static function userMayBypass(): bool
    {
        return (bool) Session::haveRight('plugin_itilflow_bypass', READ);
    }

    /**
     * Единая точка решения: разрешить действие или нет.
     * Возвращает true, если действие надо ЗАБЛОКИРОВАТЬ.
     */
    public static function deny(
        Instance $instance,
        string $itemtype,
        int $items_id,
        string $action,
        string $message
    ): bool {
        if (self::userMayBypass()) {
            return false;
        }
        $mode = self::enforcement($instance);
        if ($mode === Process::ENFORCE_AUDIT) {
            Violation::record($instance, $itemtype, $items_id, $action, false, $message);
            return false;
        }
        if ($mode === Process::ENFORCE_WARN) {
            Violation::record($instance, $itemtype, $items_id, $action, false, $message);
            self::msg('Маршрут этапов: ' . $message, WARNING);
            return false;
        }
        Violation::record($instance, $itemtype, $items_id, $action, true, $message);
        self::msg($message, ERROR);
        return true;
    }

    public static function myGroups(): array
    {
        return array_map('intval', $_SESSION['glpigroups'] ?? []);
    }
}
