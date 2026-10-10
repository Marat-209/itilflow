<?php

namespace GlpiPlugin\Itilflow;

/** Включается только для экземпляров с полным снимком structured_v1. */
trait StructuredFlow
{
    private static function structuredResult(string $status, Instance $instance, ?Step $step = null, ?Outcome $outcome = null): array
    {
        return ['status' => $status, 'instance_id' => (int) $instance->getID(),
            'attempt_id' => (int) ($instance->fields['current_attempt_id'] ?? 0),
            'step_id' => $step ? (int) $step->getID() : 0,
            'outcome_id' => $outcome ? (int) $outcome->getID() : 0];
    }

    /** Сравнение квитанции идёт под тем же lock, что и изменение экземпляра. */
    private static function structuredCommand(Instance $instance, string $action, string $key,
        int $expected_attempt, array $payload, callable $operation): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || $expected_attempt <= 0
            || (int) \Session::getLoginUserID() <= 0) {
            return self::structuredResult('rejected', $instance);
        }
        $actor = (int) \Session::getLoginUserID();
        $digest = hash('sha256', DefinitionSnapshot::encode([$action, $expected_attempt, $payload]));
        $result = self::structuredResult('unknown', $instance);
        $rejected = false;
        $ok = self::locked($instance, static function () use ($instance, $action, $key,
            $expected_attempt, $digest, $actor, $payload, $operation, &$result, &$rejected): bool {
            if (!DefinitionSnapshot::enabled($instance) || DefinitionSnapshot::load($instance) === null) {
                $rejected = true; return false;
            }
            $receipt = CommandReceipt::findCommand($instance->getID(), $key);
            if ($receipt !== null) {
                if ($receipt->fields['action'] !== $action || (int) $receipt->fields['users_id'] !== $actor
                    || !hash_equals((string) $receipt->fields['payload_digest'], $digest)
                    || !self::structuredReplayAllowed($instance, $action, $payload)) {
                    $rejected = true; return false;
                }
                $result = json_decode($receipt->fields['result_json'], true, 16, JSON_THROW_ON_ERROR);
                $result['status'] = 'already_applied';
                return true;
            }
            if ((int) $instance->fields['current_attempt_id'] !== $expected_attempt) {
                $rejected = true; return false;
            }
            $result = $operation();
            if (!is_array($result) || ($result['status'] ?? '') !== 'applied') {
                $rejected = true; return false;
            }
            self::mustAdd(new CommandReceipt(), ['plugin_itilflow_instances_id' => $instance->getID(),
                'command_key' => $key, 'action' => $action, 'users_id' => $actor,
                'payload_digest' => $digest, 'result_json' => DefinitionSnapshot::encode($result)]);
            return true;
        });
        return $ok ? $result : self::structuredResult($rejected ? 'rejected' : 'unknown', $instance);
    }

    /** Старые кнопки используют тот же ledger; вызов без токенов закрыт только в новом режиме. */
    private static function manualStructuredCommand(Instance $instance, ?Step $step, string $action,
        string $key, int $expected_attempt, array $payload, callable $apply): bool
    {
        $result = self::structuredCommand($instance, $action, $key, $expected_attempt, $payload,
            static function () use ($instance, $step, $apply): array {
                return self::structuredResult($apply() ? 'applied' : 'rejected', $instance, $step);
            });
        if ($result['status'] === 'rejected') {
            self::msg('Команда отклонена. Обновите карточку и проверьте текущую попытку и полномочия.', ERROR);
        }
        return in_array($result['status'], ['applied', 'already_applied'], true);
    }

    private static function structuredReplayAllowed(Instance $instance, string $action, array $payload): bool
    {
        if (in_array($action, ['instance_withdraw', 'instance_abort', 'instance_resume', 'instance_halt'], true)) {
            return Authorizer::replayInstanceError($instance, substr($action, 9)) === null;
        }
        if (in_array($action, ['step_complete', 'step_skip', 'step_override', 'step_set_approver', 'step_reassign'], true)) {
            $step = new Step();
            return $step->getFromDB((int) $payload[0])
                && (int) $step->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()
                && Authorizer::replayStepError($step, substr($action, 5)) === null;
        }
        if ($action === 'activate_attempt') {
            return StructuredAccess::activateError($instance) === null;
        }
        if (in_array($action, ['new_attempt', 'reconcile_outcome', 'retry_status_sync'], true)) {
            return StructuredAccess::manageError($instance) === null;
        }
        $step = new Step();
        if (in_array($action, ['explain_outcome', 'publish_positive_result'], true)) {
            $outcome = new Outcome();
            return $outcome->getFromDB((int) $payload[0])
                && $step->getFromDB((int) $outcome->fields['plugin_itilflow_steps_id'])
                && (int) $step->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()
                && StructuredAccess::explanationError($outcome, $step) === null
                && ($action !== 'publish_positive_result' || self::positivePublicationAuthority($outcome, $step, true));
        }
        return in_array($action, ['department_outcome', 'request_clarification'], true)
            && $step->getFromDB((int) $payload[0])
            && (int) $step->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()
            && StructuredAccess::answerError($step, $action === 'request_clarification', true) === null;
    }

    public static function departmentOutcome(Step $step, string $outcome, string $reason,
        string $public_summary, string $command_key, int $expected_attempt): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return ['status' => 'rejected']; }
        $reason = trim(strip_tags($reason));
        $public_summary = trim(strip_tags($public_summary));
        return self::structuredCommand($instance, 'department_outcome', $command_key, $expected_attempt,
            [$step->getID(), $outcome, $reason, $public_summary], static function () use ($instance, $step,
                $outcome, $reason, $public_summary, $command_key): array {
                if (StructuredAccess::answerError($step) !== null
                    || !in_array($outcome, [Outcome::COMPLETED, Outcome::REFUSED], true)
                    || ($outcome === Outcome::REFUSED && $reason === '') || $public_summary === '') {
                    return self::structuredResult('rejected', $instance);
                }
                $record = self::recordStructuredOutcome($instance, $step, $outcome, 'assignee_action',
                    '', 0, (int) \Session::getLoginUserID(), $reason, $public_summary, $command_key);
                self::applyStructuredOutcome($instance, $step, $record);
                return self::structuredResult('applied', $instance, $step, $record);
            });
    }

    /** Повторяется только запрос статуса; этап, артефакты и исход повторно не создаются. */
    public static function retryStatusSync(Step $step, string $command_key, int $expected_attempt): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return ['status' => 'rejected']; }
        return self::structuredCommand($instance, 'retry_status_sync', $command_key, $expected_attempt,
            [$step->getID()], static function () use ($instance, $step): array {
                if (StructuredAccess::manageError($instance) !== null || StructuredAccess::context($step) === null
                    || ($step->fields['status_sync_state'] ?? '') !== StatusSync::FAILED) {
                    return self::structuredResult('rejected', $instance);
                }
                self::enter();
                try { $sync = StatusSync::retry($step); }
                finally { self::leave(); }
                return self::structuredResult($sync['state'] === StatusSync::QUEUED ? 'applied' : 'rejected', $instance, $step);
            });
    }

    public static function requestClarification(Step $step, int $target_stage, string $reason,
        string $public_summary, string $command_key, int $expected_attempt): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return ['status' => 'rejected']; }
        $reason = trim(strip_tags($reason));
        $public_summary = trim(strip_tags($public_summary));
        return self::structuredCommand($instance, 'request_clarification', $command_key, $expected_attempt,
            [$step->getID(), $target_stage, $reason, $public_summary], static function () use ($instance,
                $step, $target_stage, $reason, $public_summary, $command_key): array {
                if (StructuredAccess::answerError($step, true) !== null || $reason === '' || $public_summary === ''
                    || !self::structuredTargetAllowed($step, $target_stage)) {
                    return self::structuredResult('rejected', $instance);
                }
                $record = self::recordStructuredOutcome($instance, $step, Outcome::CLARIFICATION,
                    'clarification', '', 0, (int) \Session::getLoginUserID(), $reason, $public_summary, $command_key);
                self::closeStructuredAttempt($instance, $step, Attempt::RETURNED);
                self::createStructuredAttempt($instance, $target_stage, $step->getID(), $reason,
                    $public_summary, $command_key, true);
                return self::structuredResult('applied', $instance, $step, $record);
            });
    }

    public static function newAttempt(Instance $instance, int $target_stage, string $reason,
        string $subject_summary, string $command_key, int $expected_attempt): array
    {
        $reason = trim(strip_tags($reason));
        $subject_summary = trim(strip_tags($subject_summary));
        return self::structuredCommand($instance, 'new_attempt', $command_key, $expected_attempt,
            [$target_stage, $reason, $subject_summary], static function () use ($instance, $target_stage,
                $reason, $subject_summary, $command_key): array {
                $old = Attempt::current($instance);
                if (StructuredAccess::manageError($instance) !== null || $reason === '' || $subject_summary === ''
                    || $old === null || !in_array($old->fields['state'], [Attempt::REFUSED, Attempt::ABORTED,
                        Attempt::DONE], true)) {
                    return self::structuredResult('rejected', $instance);
                }
                $source = self::terminalSourceStep($instance, $old);
                if ($source === null || !self::structuredTargetAllowed($source, $target_stage)) {
                    return self::structuredResult('rejected', $instance);
                }
                self::createStructuredAttempt($instance, $target_stage, $source->getID(), $reason,
                    $subject_summary, $command_key, false);
                self::advance($instance);
                return self::structuredResult('applied', $instance);
            });
    }

    public static function activateAttempt(Instance $instance, string $subject_summary,
        string $reason, string $command_key, int $expected_attempt): array
    {
        $subject_summary = trim(strip_tags($subject_summary));
        $reason = trim(strip_tags($reason));
        return self::structuredCommand($instance, 'activate_attempt', $command_key, $expected_attempt,
            [$subject_summary, $reason], static function () use ($instance, $subject_summary, $reason): array {
                $attempt = Attempt::current($instance);
                if (StructuredAccess::activateError($instance) !== null || $attempt === null
                    || $attempt->fields['state'] !== Attempt::WAITING_INPUT || $subject_summary === '' || $reason === '') {
                    return self::structuredResult('rejected', $instance);
                }
                foreach ($instance->getAttemptSteps() as $row) {
                    if ($row['state'] !== Step::PENDING || (int) $row['artifact_items_id'] !== 0) {
                        return self::structuredResult('rejected', $instance);
                    }
                }
                self::mustUpdate($attempt, ['id' => $attempt->getID(), 'state' => Attempt::RUNNING,
                    'subject_summary' => $subject_summary, 'date_start' => self::now()]);
                self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::RUNNING, 'date_end' => null]);
                self::structuredFollowup($instance, 'Уточнение подтверждено. Начата новая попытка: ' . $subject_summary);
                self::advance($instance);
                return self::structuredResult('applied', $instance);
            });
    }

    public static function explainOutcome(Outcome $outcome, string $public_summary,
        string $command_key, int $expected_attempt): array
    {
        $step = new Step();
        if (!$outcome->getFromDB($outcome->getID())
            || !$step->getFromDB((int) $outcome->fields['plugin_itilflow_steps_id'])
            || ($instance = $step->getInstance()) === null) { return ['status' => 'rejected']; }
        $public_summary = trim(strip_tags($public_summary));
        return self::structuredCommand($instance, 'explain_outcome', $command_key, $expected_attempt,
            [$outcome->getID(), $public_summary], static function () use ($instance, $step, $outcome, $public_summary): array {
                if (!$outcome->getFromDB($outcome->getID()) || StructuredAccess::explanationError($outcome, $step) !== null
                    || empty($outcome->fields['explanation_pending']) || $public_summary === '') {
                    return self::structuredResult('rejected', $instance);
                }
                if ($outcome->fields['outcome'] === Outcome::COMPLETED) {
                    return self::structuredResult('rejected', $instance);
                }
                $fid = self::structuredFollowup($instance, 'Пояснение результата отдела: ' . $public_summary);
                self::mustUpdate($outcome, ['id' => $outcome->getID(), 'public_summary' => $public_summary,
                    'public_followups_id' => $fid, 'explanation_pending' => 0]);
                return self::structuredResult('applied', $instance, $step, $outcome);
            });
    }

    /** Публикация принятого исхода — не новый голос и не позднее оживление попытки. */
    public static function publishPositiveResult(Outcome $outcome, string $public_summary,
        string $command_key, int $expected_attempt): array
    {
        $step = new Step();
        if (!$outcome->getFromDB($outcome->getID())
            || !$step->getFromDB((int) $outcome->fields['plugin_itilflow_steps_id'])
            || ($instance = $step->getInstance()) === null) { return ['status' => 'rejected']; }
        $public_summary = trim(strip_tags($public_summary));
        return self::structuredCommand($instance, 'publish_positive_result', $command_key, $expected_attempt,
            [$outcome->getID(), $public_summary], static function () use ($instance, $step, $outcome, $public_summary): array {
                if (!$outcome->getFromDB($outcome->getID()) || $public_summary === ''
                    || empty($outcome->fields['explanation_pending'])
                    || !self::positivePublicationAuthority($outcome, $step, false)) {
                    return self::structuredResult('rejected', $instance);
                }
                $rows = StructuredAccess::roster($step, true);
                if ($rows === null) { return self::structuredResult('rejected', $instance); }
                ksort($rows);
                if (!hash_equals((string) $outcome->fields['source_digest'], hash('sha256', DefinitionSnapshot::encode($rows)))) {
                    return self::structuredResult('rejected', $instance);
                }
                $fid = self::structuredFollowup($instance, 'Публичный итог отдела: ' . $public_summary);
                self::mustUpdate($outcome, ['id' => $outcome->getID(), 'public_summary' => $public_summary,
                    'public_followups_id' => $fid, 'explanation_pending' => 0]);
                self::applyStructuredOutcome($instance, $step, $outcome);
                return self::structuredResult('applied', $instance, $step, $outcome);
            });
    }

    public static function canPublishPositive(Outcome $outcome, Step $step): bool
    {
        return self::positivePublicationAuthority($outcome, $step, false);
    }

    private static function positivePublicationAuthority(Outcome $outcome, Step $step, bool $replay): bool
    {
        if ($outcome->fields['outcome'] !== Outcome::COMPLETED || $outcome->fields['source_kind'] !== 'native_validation'
            || (!$replay && StructuredAccess::context($step) === null)
            || StructuredAccess::explanationError($outcome, $step) !== null) { return false; }
        $type = $outcome->fields['source_itemtype'];
        if (!in_array($type, ['TicketValidation', 'ChangeValidation'], true)) { return false; }
        $v = new $type();
        return CurrentRead::load($v, (int) $outcome->fields['source_items_id'])
            && ValidationAuthority::canAnswer($v) && $v->can($v->getID(), UPDATE)
            && (int) $outcome->fields['users_id'] === (int) \Session::getLoginUserID();
    }

    private static function structuredTargetAllowed(Step $source, int $target): bool
    {
        $stage = $source->getStage();
        return $stage !== null && in_array($target, DefinitionSnapshot::targets($stage->fields['return_targets']) ?? [], true);
    }

    private static function terminalSourceStep(Instance $instance, Attempt $attempt): ?Step
    {
        if ($attempt->fields['state'] === Attempt::ABORTED && (int) ($attempt->fields['source_step_id'] ?? 0) > 0) {
            $source = new Step();
            if ($source->getFromDB((int) $attempt->fields['source_step_id'])
                && (int) $source->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()) { return $source; }
        }
        $rows = (new Step())->find(['plugin_itilflow_instances_id' => $instance->getID(),
            'attempt_id' => $attempt->getID()], ['ranking DESC']);
        foreach ($rows as $id => $row) {
            if (in_array($row['state'], [Step::REJECTED, Step::DONE, Step::SKIPPED], true)) {
                $step = new Step();
                return $step->getFromDB((int) $id) ? $step : null;
            }
        }
        return null;
    }

    private static function createStructuredAttempt(Instance $instance, int $target, int $source_step,
        string $reason, string $subject, string $key, bool $waiting): Attempt
    {
        $snapshot = DefinitionSnapshot::load($instance);
        $stage = DefinitionSnapshot::stage($instance, $target);
        $old = Attempt::current($instance);
        if ($snapshot === null || $stage === null || ($old !== null && $old->fields['state'] === Attempt::RUNNING)) {
            throw new \RuntimeException('Новая попытка не подтверждена.');
        }
        if ($old !== null) {
            $latest = [];
            foreach ($instance->getSteps() as $row) {
                $sid = (int) $row['plugin_itilflow_stages_id'];
                if (!isset($latest[$sid]) || (int) $row['pass_number'] > (int) $latest[$sid]['pass_number']) { $latest[$sid] = $row; }
            }
            foreach ($snapshot['stages'] as $prefix) {
                if ((int) $prefix['ranking'] >= (int) $stage->fields['ranking']) { continue; }
                $row = $latest[(int) $prefix['id']] ?? null;
                if ($row === null || !in_array($row['state'], [Step::DONE, Step::SKIPPED], true)) {
                    throw new \RuntimeException('Принятая часть до точки возврата не подтверждена.');
                }
                if ($row['execution_mode'] !== Stage::MODE_INLINE) {
                    $outcome = Outcome::forStep((int) $row['id']);
                    if ($outcome === null || $outcome->fields['outcome'] !== Outcome::COMPLETED
                        || !empty($outcome->fields['explanation_pending'])) {
                        throw new \RuntimeException('Принятый исход до точки возврата не подтверждён.');
                    }
                }
            }
        }
        $number = $old === null ? 1 : (int) $old->fields['number'] + 1;
        $attempt = new Attempt();
        $aid = self::mustAdd($attempt, ['plugin_itilflow_instances_id' => $instance->getID(),
            'number' => $number, 'previous_attempt_id' => $old?->getID(), 'source_step_id' => $source_step ?: null,
            'target_stage_id' => $target, 'preserved_before_rank' => (int) $stage->fields['ranking'],
            'state' => $waiting ? Attempt::WAITING_INPUT : Attempt::RUNNING, 'reason' => $reason,
            'subject_summary' => $subject, 'users_id' => (int) \Session::getLoginUserID(),
            'date_start' => $waiting ? null : self::now(), 'command_key' => $key]);
        $attempt->getFromDB($aid);
        foreach ($snapshot['stages'] as $s) {
            if ((int) $s['ranking'] < (int) $stage->fields['ranking']) { continue; }
            self::mustAdd(new Step(), ['plugin_itilflow_instances_id' => $instance->getID(),
                'plugin_itilflow_stages_id' => (int) $s['id'], 'attempt_id' => $aid,
                'stage_name' => $s['name'], 'ranking' => (int) $s['ranking'], 'execution_mode' => $s['execution_mode'],
                'approval_percent_snapshot' => (int) $s['approval_percent'] >= 1 && (int) $s['approval_percent'] <= 100
                    ? (int) $s['approval_percent'] : null,
                'approval_snapshot_source' => 'definition', 'state' => Step::PENDING, 'pass_number' => $number,
                'users_id' => (int) $s['users_id'], 'groups_id' => (int) $s['groups_id']]);
        }
        if ($old !== null) {
            foreach ((new Step())->find(['attempt_id' => $old->getID()]) as $id => $row) {
                if ((int) $row['ranking'] >= (int) $stage->fields['ranking']) {
                    $prior = new Step(); $prior->getFromDB((int) $id);
                    self::mustUpdate($prior, ['id' => $id, 'superseded_by_attempt_id' => $aid]);
                }
            }
        }
        self::mustUpdate($instance, ['id' => $instance->getID(), 'current_attempt_id' => $aid,
            'state' => $waiting ? Instance::HALTED : Instance::RUNNING,
            'plugin_itilflow_stages_id_current' => 0, 'date_end' => null]);
        return $attempt;
    }

    /** Результат/причина приватны; в parent переносится только явно введённое публичное пояснение. */
    private static function recordStructuredOutcome(Instance $instance, Step $step, string $outcome,
        string $kind, string $source_type, int $source_id, int $source_actor, string $reason,
        string $public_summary, string $key, string $source_digest = ''): Outcome
    {
        if (Outcome::forStep($step->getID()) !== null) {
            throw new \RuntimeException('Исход шага уже сохранён.');
        }
        $record = new Outcome();
        $oid = self::mustAdd($record, ['plugin_itilflow_instances_id' => $instance->getID(),
            'plugin_itilflow_steps_id' => $step->getID(), 'attempt_id' => $step->fields['attempt_id'],
            'artifact_itemtype' => $step->fields['artifact_itemtype'], 'artifact_items_id' => (int) $step->fields['artifact_items_id'],
            'outcome' => $outcome, 'source_kind' => $kind, 'source_itemtype' => $source_type,
            'source_items_id' => $source_id, 'source_digest' => $source_digest !== '' ? $source_digest
                : hash('sha256', DefinitionSnapshot::encode([$kind, $source_actor, $outcome, $reason, $public_summary])),
            'users_id' => $source_actor, 'reason' => $reason, 'public_summary' => $public_summary ?: null,
            'explanation_pending' => $public_summary === '' ? 1 : 0,
            'command_key' => $key]);
        $record->getFromDB($oid);
        $label = Outcome::labels()[$outcome] ?? 'Результат отдела';
        $text = self::stageLabel($step) . ': ' . $label . '.';
        if ($source_actor > 0) { $text .= ' Автор результата: ' . getUserName($source_actor) . '.'; }
        $text .= $public_summary !== '' ? ' ' . $public_summary
            : ' Требуется отдельное публичное пояснение.';
        $fid = self::structuredFollowup($instance, $text);
        self::mustUpdate($record, ['id' => $oid, 'public_followups_id' => $fid]);
        self::solveStructuredChild($step, $text);
        return $record;
    }

    private static function structuredFollowup(Instance $instance, string $text): int
    {
        $parent = $instance->getItilItem();
        if ($parent === null || !empty($parent->fields['is_deleted'])) {
            throw new \RuntimeException('Публичный итог не сохранён.');
        }
        self::enter();
        try {
            $followup = new \ITILFollowup();
            $id = self::mustAdd($followup, ['itemtype' => $parent->getType(), 'items_id' => $parent->getID(),
                'content' => '<p>' . htmlescape($text) . '</p>', 'is_private' => 0,
                '_no_reopen' => 1, '_do_not_compute_status' => 1]);
            if (!CurrentRead::load($followup, $id) || (int) $followup->fields['is_private'] !== 0
                || $followup->fields['itemtype'] !== $parent->getType()
                || (int) $followup->fields['items_id'] !== (int) $parent->getID()) {
                throw new \RuntimeException('Публичный итог не подтверждён.');
            }
            return $id;
        } finally { self::leave(); }
    }

    /** Снятый с маршрута запрос визы не остаётся ждать ответа у согласующего. */
    private static function dropWaitingValidations(Step $step): void
    {
        if ($step->fields['execution_mode'] !== Stage::MODE_APPROVAL) { return; }
        foreach (StructuredAccess::roster($step) ?? [] as $id => $row) {
            $v = new ($row['_type'])();
            if ((int) $row['status'] === \CommonITILValidation::WAITING && $v->getFromDB((int) $id)) {
                self::enter();
                try { $v->delete(['id' => (int) $id], true); } finally { self::leave(); }
            }
        }
    }

    private static function closeStructuredAttempt(Instance $instance, Step $source, string $state): void
    {
        $attempt = Attempt::current($instance);
        if ($attempt === null || $attempt->fields['state'] !== Attempt::RUNNING) {
            throw new \RuntimeException('Текущая попытка не подтверждена.');
        }
        self::dropWaitingValidations($source);
        self::mustUpdate($source, ['id' => $source->getID(), 'state' => Step::REJECTED,
            'date_end' => self::now(), 'comment' => null]);
        foreach ($instance->getAttemptSteps() as $row) {
            if ($row['state'] === Step::PENDING) {
                $pending = new Step(); $pending->getFromDB((int) $row['id']);
                self::mustUpdate($pending, ['id' => $pending->getID(), 'state' => Step::SUPERSEDED]);
            }
        }
        self::mustUpdate($attempt, ['id' => $attempt->getID(), 'state' => $state, 'date_end' => self::now()]);
        self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::HALTED,
            'plugin_itilflow_stages_id_current' => 0]);
    }

    private static function applyStructuredOutcome(Instance $instance, Step $step, Outcome $record): void
    {
        if ($record->fields['outcome'] === Outcome::COMPLETED) {
            if (!empty($record->fields['explanation_pending'])) { return; }
            $end = self::now();
            $duration = Step::elapsedSeconds(['date_start' => $step->fields['date_start'] ?? null, 'date_end' => $end]);
            $update = ['id' => $step->getID(), 'state' => Step::DONE, 'date_end' => $end,
                'users_id_actual' => (int) $record->fields['users_id'], 'comment' => null];
            if ($duration !== null) { $update['duration'] = $duration; }
            // Если начало не подтверждено, default duration не объявляется измерением в UI/Report/Sheet.
            self::mustUpdate($step, $update);
            self::advance($instance);
            return;
        }
        self::closeStructuredAttempt($instance, $step, Attempt::REFUSED);
        if (($step->getStage()->fields['negative_policy'] ?? '') === 'abort') {
            self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::ABORTED,
                'date_end' => self::now()]);
            // Отказ — конец маршрута: заявитель получает прямой отрицательный ответ решением заявки.
            $why = trim((string) ($record->fields['public_summary'] ?: $record->fields['reason']));
            self::solveStructured($instance->getItilItem(), sprintf('Отказ на этапе «%s».', (string) $step->fields['stage_name'])
                . ($why !== '' ? "\nПричина: " . $why : ''));
        }
    }

    /** Native post-event: голос уже сохранён ядром; локальный сбой не объявляет его отменённым. */
    public static function structuredValidationUpdated(\CommonITILValidation $event): void
    {
        $step = OutcomeValidation::stepFor($event->getType(), $event->getID())
            ?? Step::getByValidation($event->getType(), $event->getID());
        $instance = $step?->getInstance();
        if ($step === null || $instance === null || !DefinitionSnapshot::enabled($instance)) { return; }
        $ok = self::locked($instance, static function () use ($instance, $step, $event): bool {
            if (StructuredAccess::validationError($event, $step) !== null) { return false; }
            return self::transferStructuredVote($instance, $step, $event);
        });
        if (!$ok) {
            self::msg('Ответ согласующего сохранён ядром; перенос в маршрут не подтверждён. Требуется сверка результата.', ERROR);
        }
    }

    public static function reconcileOutcome(Step $step, string $validation_type, int $validation_id,
        string $command_key, int $expected_attempt): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return ['status' => 'rejected']; }
        return self::structuredCommand($instance, 'reconcile_outcome', $command_key, $expected_attempt,
            [$step->getID(), $validation_type, $validation_id], static function () use ($instance,
                $step, $validation_type, $validation_id): array {
                if (StructuredAccess::manageError($instance) !== null || StructuredAccess::context($step) === null
                    || !in_array($validation_type, ['TicketValidation', 'ChangeValidation'], true)) {
                    return self::structuredResult('rejected', $instance);
                }
                $v = new $validation_type();
                if (!CurrentRead::load($v, $validation_id) || !self::transferStructuredVote($instance, $step, $v)) {
                    return self::structuredResult('rejected', $instance);
                }
                return self::structuredResult('applied', $instance, $step, Outcome::forStep($step->getID()));
            });
    }

    private static function transferStructuredVote(Instance $instance, Step $step, \CommonITILValidation $source): bool
    {
        $rows = StructuredAccess::roster($step, true);
        $need = (int) ($step->fields['approval_percent_snapshot'] ?? 0);
        if ($rows === null || !isset($rows[$source->getID()]) || $rows[$source->getID()]['_type'] !== $source->getType()
            || $need < 1 || $need > 100) { return false; }
        ksort($rows);
        $digest = hash('sha256', DefinitionSnapshot::encode($rows));
        $existing = Outcome::forStep($step->getID());
        if ($existing !== null) { return hash_equals((string) $existing->fields['source_digest'], $digest); }
        $accepted = 0; $refused = 0;
        foreach ($rows as $row) {
            $accepted += (int) $row['status'] === \CommonITILValidation::ACCEPTED ? 1 : 0;
            $refused += (int) $row['status'] === \CommonITILValidation::REFUSED ? 1 : 0;
        }
        $outcome = intdiv($accepted * 100, count($rows)) >= $need ? Outcome::COMPLETED
            : (intdiv((count($rows) - $refused) * 100, count($rows)) < $need ? Outcome::REFUSED : null);
        if ($outcome === null) { return true; } // промежуточный голос не порождает повторяемого объявления
        $row = $rows[$source->getID()];
        if (!in_array((int) $row['status'], [\CommonITILValidation::ACCEPTED, \CommonITILValidation::REFUSED], true)
            || (int) ($row['users_id_validate'] ?? 0) <= 0) { return false; }
        // Виза сама по себе — публичный результат этапа: «согласовано» продвигает маршрут сразу,
        // причина отказа — прямой ответ заявителю.
        $comment = trim(strip_tags((string) ($row['comment_validation'] ?? '')));
        $public = $outcome === Outcome::COMPLETED ? 'Согласовано.' : ($comment !== '' ? $comment : 'Не согласовано.');
        $record = self::recordStructuredOutcome($instance, $step, $outcome, 'native_validation',
            $source->getType(), $source->getID(), (int) $row['users_id_validate'], $comment, $public,
            hash('sha256', 'native:' . $step->getID() . ':' . $digest), $digest);
        self::applyStructuredOutcome($instance, $step, $record);
        return true;
    }

    private static function startStructuredInternal(\CommonITILObject $item, Process $process): Instance
    {
        if (!CurrentRead::load($item, (int) $item->getID()) || !empty($item->fields['is_deleted'])
            || in_array((int) $item->fields['status'], array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true)) {
            throw new \RuntimeException('Объект запуска недоступен.');
        }
        $snapshot = DefinitionSnapshot::capture($process);
        $raw = DefinitionSnapshot::encode($snapshot);
        $captured = new Process(); $captured->fields = $snapshot['process'];
        if (!Authorizer::processFitsItem($captured, $item)) {
            throw new \RuntimeException('Определение запуска изменилось.');
        }
        self::enter();
        try {
            $instance = new Instance();
            $iid = self::mustAdd($instance, ['itemtype' => $item->getType(), 'items_id' => $item->getID(),
                'entities_id' => (int) $item->fields['entities_id'], 'plugin_itilflow_processes_id' => $process->getID(),
                'process_version' => (int) $snapshot['process']['version'], 'state' => Instance::RUNNING,
                'date_start' => self::now(), 'users_id_requester_start' => self::initialRequester($item),
                'outcome_contract' => DefinitionSnapshot::MODE, 'definition_snapshot' => $raw,
                'definition_sha256' => hash('sha256', $raw)]);
            $instance->getFromDB($iid);
            $subject = trim(strip_tags((string) $item->fields['name'] . "\n" . (string) ($item->fields['content'] ?? '')));
            self::createStructuredAttempt($instance, (int) $snapshot['stages'][0]['id'], 0, '', $subject,
                hash('sha256', 'start:' . $iid . ':' . hash('sha256', $raw)), false);
            self::advance($instance);
            return $instance;
        } finally { self::leave(); }
    }

    private static function createStructuredChild(Instance $instance, Step $step, Stage $stage,
        \CommonITILObject $parent): bool|string
    {
        $attempt = Attempt::current($instance);
        $entity = $stage->resolveEntity($parent);
        if ($attempt === null || !$stage->groupFitsEntity($entity)) { return 'Состав исполнителей недоступен.'; }
        $type = $parent->getType();
        $child = new $type();
        $input = ['name' => sprintf('[Этап %d, попытка %d] %s', (int) $step->fields['ranking'],
            (int) $attempt->fields['number'], mb_substr((string) $stage->fields['name'], 0, 100)),
            'content' => '<p>' . htmlescape((string) $stage->fields['content']) . '</p><p>'
                . nl2br(htmlescape((string) $attempt->fields['subject_summary'])) . '</p>',
            'entities_id' => $entity];
        foreach (['groups_id' => '_groups_id_assign', 'users_id' => '_users_id_assign',
            'itilcategories_id' => 'itilcategories_id'] as $from => $to) {
            if ((int) $stage->fields[$from] > 0) { $input[$to] = (int) $stage->fields[$from]; }
        }
        if ($type === 'Ticket' && (int) $stage->fields['slas_id'] > 0) {
            $input['slas_id_ttr'] = (int) $stage->fields['slas_id'];
        }
        $cid = self::mustAdd($child, $input);
        if (!CurrentRead::load($child, $cid) || (int) $child->fields['entities_id'] !== $entity) {
            throw new \RuntimeException('Объект отдела не подтверждён.');
        }
        if ((int) $stage->fields['users_id'] > 0 && !Authorizer::userFitsItem((int) $stage->fields['users_id'], $child)) {
            throw new \RuntimeException('Исполнитель недоступен в сущности отдела.');
        }
        $link = \CommonITILObject_CommonITILObject::getLinkClass($type, $type);
        if ($link === null) { throw new \RuntimeException('Связь отдела не поддерживается.'); }
        $pairs = self::linkFields($type);
        self::mustAdd(new $link(), [$pairs[0] => $cid, $pairs[1] => $parent->getID(),
            'link' => \CommonITILObject_CommonITILObject::SON_OF]);
        self::mustUpdate($step, ['id' => $step->getID(), 'artifact_itemtype' => $type,
            'artifact_items_id' => $cid, 'artifact_entities_id' => $entity]);
        if ($stage->fields['department_result_source'] === 'native_validation') {
            // Адресат задан определением; все созданные визы включаются в собственный реестр.
            $targets = [];
            if ((int) $stage->fields['groups_id'] > 0) { $targets[] = [\Group::class, (int) $stage->fields['groups_id']]; }
            if ((int) $stage->fields['users_id'] > 0) { $targets[] = [\User::class, (int) $stage->fields['users_id']]; }
            foreach ($targets as [$target_type, $target_id]) {
                $vid = self::openValidation($step, $stage, $child, $target_type, $target_id);
                $v = $child::getValidationClassInstance();
                if (!is_int($vid) || $v === null || !CurrentRead::load($v, $vid)) {
                    throw new \RuntimeException('Полный состав согласований отдела не создан.');
                }
                self::mustAdd(new OutcomeValidation(), ['plugin_itilflow_steps_id' => $step->getID(),
                    'attempt_id' => $attempt->getID(), 'validation_itemtype' => $v->getType(), 'validations_id' => $vid,
                    'artifact_itemtype' => $type, 'artifact_items_id' => $cid, 'entities_id' => $entity,
                    'itemtype_target' => $target_type, 'items_id_target' => $target_id,
                    'validation_step_id' => (int) $v->fields['itils_validationsteps_id']]);
            }
            self::mustUpdate($step, ['id' => $step->getID(), 'approval_expected_count' => count($targets)]);
            if (StructuredAccess::roster($step) === null) {
                throw new \RuntimeException('Полный состав согласований отдела не подтверждён.');
            }
        }
        return StructuredAccess::child($step) !== null ? true : 'Связь отдела не подтверждена.';
    }

    private static function finishStructured(Instance $instance): void
    {
        $attempt = Attempt::current($instance);
        $steps = $instance->getAttemptSteps();
        if ($attempt === null || $attempt->fields['state'] !== Attempt::RUNNING || !count($steps)) {
            throw new \RuntimeException('Завершение попытки не подтверждено.');
        }
        foreach ($steps as $row) {
            if (!in_array($row['state'], [Step::DONE, Step::SKIPPED], true)) {
                throw new \RuntimeException('Не все этапы завершены.');
            }
            if ($row['execution_mode'] !== Stage::MODE_INLINE) {
                $outcome = Outcome::forStep((int) $row['id']);
                if ($outcome === null || $outcome->fields['outcome'] !== Outcome::COMPLETED
                    || !empty($outcome->fields['explanation_pending'])) {
                    throw new \RuntimeException('Положительный исход не подтверждён.');
                }
            }
        }
        self::mustUpdate($attempt, ['id' => $attempt->getID(), 'state' => Attempt::DONE, 'date_end' => self::now()]);
        self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::DONE,
            'plugin_itilflow_stages_id_current' => 0, 'date_end' => self::now()]);
        // Итог последнего отдела — содержательный ответ заявителю штатным решением заявки.
        $final = '';
        foreach ($steps as $row) {
            $outcome = Outcome::forStep((int) $row['id']);
            if ($outcome !== null && trim((string) $outcome->fields['public_summary']) !== '') {
                $final = trim((string) $outcome->fields['public_summary']);
            }
        }
        $process = DefinitionSnapshot::process($instance);
        self::solveStructured($instance->getItilItem(), sprintf('Заявка выполнена: все этапы маршрута «%s» пройдены.',
            (string) ($process?->fields['name'] ?? '')) . ($final !== '' ? "\nИтог: " . $final : ''));
    }

    /** Ответ заявителю штатным решением; уже решённый или закрытый объект не трогается. */
    private static function solveStructured(?\CommonITILObject $item, string $text): void
    {
        if ($item === null || !CurrentRead::load($item, (int) $item->getID()) || !empty($item->fields['is_deleted'])
            || in_array((int) $item->fields['status'], array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true)) {
            return;
        }
        self::enter();
        try {
            self::mustAdd(new \ITILSolution(), ['itemtype' => $item->getType(), 'items_id' => $item->getID(),
                'content' => '<p>' . nl2br(htmlescape($text)) . '</p>']);
        } finally { self::leave(); }
    }

    /** Работа отдела закончена исходом — его дочерняя заявка решается тем же текстом. */
    private static function solveStructuredChild(Step $step, string $text): void
    {
        if ($step->fields['execution_mode'] === Stage::MODE_DELEGATED && ($child = StructuredAccess::child($step)) !== null) {
            self::solveStructured($child, $text);
        }
    }

    private static function abortStructured(Instance $instance, string $reason): void
    {
        $attempt = Attempt::current($instance);
        if ($attempt !== null && $attempt->fields['state'] === Attempt::REFUSED
            && $instance->fields['state'] === Instance::HALTED) {
            // Исход отказа уже окончательный; административное прекращение не переписывает историю попытки.
            self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::ABORTED,
                'plugin_itilflow_stages_id_current' => 0, 'date_end' => self::now()]);
            self::structuredFollowup($instance, 'Маршрут прекращён после отказа: ' . trim(strip_tags($reason)));
            return;
        }
        if ($attempt === null || !in_array($attempt->fields['state'], [Attempt::RUNNING, Attempt::WAITING_INPUT], true)) {
            throw new \RuntimeException('Активная попытка не подтверждена.');
        }
        $source_id = $instance->getCurrentStep()?->getID() ?? (int) ($attempt->fields['source_step_id'] ?? 0);
        foreach ($instance->getAttemptSteps() as $row) {
            if (in_array($row['state'], [Step::PENDING, Step::RUNNING], true)) {
                $step = new Step(); $step->getFromDB((int) $row['id']);
                // Открытая работа отдела и запрос визы не остаются висеть в чужой очереди.
                self::solveStructuredChild($step, 'Работа не требуется: маршрут прекращён. ' . trim(strip_tags($reason)));
                self::dropWaitingValidations($step);
                self::mustUpdate($step, ['id' => $step->getID(), 'state' => Step::SUPERSEDED, 'date_end' => self::now()]);
            }
        }
        self::mustUpdate($attempt, ['id' => $attempt->getID(), 'state' => Attempt::ABORTED, 'date_end' => self::now(),
            'source_step_id' => $source_id ?: null]);
        self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::ABORTED,
            'plugin_itilflow_stages_id_current' => 0, 'date_end' => self::now()]);
        self::structuredFollowup($instance, 'Маршрут прерван: ' . trim(strip_tags($reason)));
    }
}
