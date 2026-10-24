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
    /** $actor — автор команды, если это не вошедший: ответ инициатора письмом (сеанс сборщика почты). */
    private static function structuredCommand(Instance $instance, string $action, string $key,
        int $expected_attempt, array $payload, callable $operation, ?int $actor = null): array
    {
        $actor ??= (int) \Session::getLoginUserID();
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || $expected_attempt <= 0 || $actor <= 0) {
            return self::structuredResult('rejected', $instance);
        }
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
        string $key, int $expected_attempt, array $payload, callable $apply, ?string &$denial = null): bool
    {
        $result = self::structuredCommand($instance, $action, $key, $expected_attempt, $payload,
            static function () use ($instance, $step, $apply): array {
                return self::structuredResult($apply() ? 'applied' : 'rejected', $instance, $step);
            });
        if ($result['status'] === 'rejected' && $denial === null) {
            self::msg('Действие не выполнено: страница устарела или у вас нет права. Обновите страницу.', ERROR);
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
        if (in_array($action, ['save_decision', 'choose_condition'], true)) {
            $step = new Step();
            if (!$step->getFromDB((int) ($payload[0] ?? 0)) || (int) $step->fields['plugin_itilflow_instances_id'] !== (int) $instance->getID()) { return false; }
            $item = $instance->getItilItem();
            if ($item === null || Authorizer::itemError($item, $action === 'save_decision' ? READ : UPDATE) !== null) { return false; }
            if ($action === 'save_decision') {
                foreach (Decision::read($step)['history'] as $entry) {
                    if ((int) ($entry['actor'] ?? 0) === (int) \Session::getLoginUserID()) { return true; }
                }
            }
            if ($action === 'choose_condition' && ($payload[1] ?? '') === 'skip'
                && ($step->fields['execution_mode'] === Stage::MODE_APPROVAL || ($step->getStage()?->fields['department_result_source'] ?? '') === 'native_validation')) { return Authorizer::isAdministrator(); }
            return Authorizer::isAdministrator() || Authorizer::isAssignee($item) || ($action === 'save_decision' && Decision::canWrite($step));
        }
        if ($action === 'adopt_existing') {
            $step = new Step();
            if (!$step->getFromDB((int) ($payload[0] ?? 0))
                || (int) $step->fields['plugin_itilflow_instances_id'] !== (int) $instance->getID()) { return false; }
            $rows = $step->validationRoster();
            if ($rows === null) { return false; }
            $answered = count(array_filter($rows, static fn(array $r): bool => (int) $r['status'] !== 2)) > 0;
            $confirmation = $payload[3] ?? null;
            if ($confirmation !== null && !is_string($confirmation)) { return false; }
            if ($answered || $confirmation !== null) {
                $native = \Ticket::getValidationStepInstance();
                $first = reset($rows);
                if ($native === null || !$first || !CurrentRead::load($native, (int) $first['itils_validationsteps_id'])
                    || !ManualAdoptionConfirmation::accepts(array_values(CurrentRead::find(new \TicketValidation(), [
                        'tickets_id' => (int) $first['tickets_id'], 'itils_validationsteps_id' => (int) $first['itils_validationsteps_id'],
                    ])), $native->fields, $confirmation)) { return false; }
            }
            return Authorizer::formAdoptionError($step, $answered, true) === null;
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
        string $public_summary, string $command_key, int $expected_attempt, ?int $decision_revision = null, ?array $decision_answers = null): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return ['status' => 'rejected']; }
        $reason = trim(strip_tags($reason));
        $public_summary = trim(strip_tags($public_summary));
        return self::structuredCommand($instance, 'department_outcome', $command_key, $expected_attempt,
            array_merge([$step->getID(), $outcome, $reason, $public_summary], Decision::items($step) === [] ? [] : [$decision_revision, $decision_answers]), static function () use ($instance, $step,
                $outcome, $reason, $public_summary, $command_key, $decision_revision, $decision_answers): array {
                if (StructuredAccess::answerError($step) !== null
                    || !in_array($outcome, [Outcome::COMPLETED, Outcome::REFUSED], true)
                    || ($outcome === Outcome::REFUSED && $reason === '') || $public_summary === '') {
                    return self::structuredResult('rejected', $instance);
                }
                if (!self::prepareDecisionForOutcome($step, $decision_revision, $decision_answers, $outcome === Outcome::COMPLETED)) {
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
        string $public_summary, string $command_key, int $expected_attempt, ?int $decision_revision = null): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return ['status' => 'rejected']; }
        $reason = trim(strip_tags($reason));
        $public_summary = trim(strip_tags($public_summary));
        return self::structuredCommand($instance, 'request_clarification', $command_key, $expected_attempt,
            array_merge([$step->getID(), $target_stage, $reason, $public_summary], Decision::items($step) === [] ? [] : [$decision_revision]), static function () use ($instance,
                $step, $target_stage, $reason, $public_summary, $command_key, $decision_revision): array {
                if (StructuredAccess::answerError($step, true) !== null || $reason === '' || $public_summary === ''
                    || !self::structuredTargetAllowed($step, $target_stage)) {
                    return self::structuredResult('rejected', $instance);
                }
                if (!self::prepareDecisionForOutcome($step, $decision_revision, null, false)) { return self::structuredResult('rejected', $instance); }
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
        return self::continueAttempt($instance, $subject_summary, $reason, $command_key, $expected_attempt, false);
    }

    /**
     * Продолжится ли маршрут от ответа инициатора сам. Уточнение вернул этап согласования — у основной
     * заявки нет исполнителя, который нажал бы «Продолжить». Согласующие «Задан в этапе» получат
     * запрос заново по настройке этапа; «Согласование из формы» и «Указывает исполнитель» — только
     * если известны адресаты прошлого прохода этапа ($targetsKnown, Engine::repeatPlan). Иначе и при
     * уточнении от исполнителя задачи или отдела — «Продолжить» нажимает человек.
     */
    public static function continuesOnAnswer(?string $sourceMode, string $approverSource = Stage::APPROVER_FIXED, bool $targetsKnown = false): bool
    {
        return $sourceMode === Stage::MODE_APPROVAL && ($approverSource === Stage::APPROVER_FIXED || $targetsKnown);
    }

    /**
     * Этот запуск ждёт ответа инициатора и продолжится от его комментария сам. Решает этап, с которого
     * маршрут продолжится: согласование «из формы» / «укажет исполнитель» получит запросы само, только
     * если это тот же этап, что вернул на уточнение, и адресаты его запросов известны (planFrom).
     */
    public static function answerContinues(Instance $instance): bool
    {
        $attempt = DefinitionSnapshot::enabled($instance) ? Attempt::current($instance) : null;
        if ($attempt === null || $attempt->fields['state'] !== Attempt::WAITING_INPUT) { return false; }
        $source = new Step();
        if ((int) ($attempt->fields['source_step_id'] ?? 0) <= 0 || !$source->getFromDB((int) $attempt->fields['source_step_id'])) { return false; }
        $targetId = (int) ($attempt->fields['target_stage_id'] ?? 0);
        $target = DefinitionSnapshot::stage($instance, $targetId);
        if ($target === null) { return false; }
        $kind = ($target->fields['execution_mode'] ?? '') === Stage::MODE_APPROVAL
            ? (string) ($target->fields['approver_source'] ?? Stage::APPROVER_FIXED) : Stage::APPROVER_FIXED;
        $item = $instance->getItilItem();
        $known = $kind !== Stage::APPROVER_FIXED && $item !== null
            && (int) $source->fields['plugin_itilflow_stages_id'] === $targetId
            && self::planFrom($source, $item)['targets'] !== [];
        return self::continuesOnAnswer((string) ($source->fields['execution_mode'] ?? ''), $kind, $known);
    }

    /** Имя сеанса автоматического действия сборщика почты GLPI (CronTask::launch: «cron_» + имя задачи). */
    public const MAILGATE_CRON = 'cron_mailgate';

    /**
     * Комментарий годится как ответ инициатора: публичный, автор — инициатор заявки, и он же вошёл.
     * Письмо из сборщика почты (сеанс его автоматического действия, без вошедшего) — тоже: автор там —
     * инициатор, найденный ядром по адресу отправителя, как и для самого комментария. Подделать
     * сеанс автоматического действия из браузера нельзя; поля формы (_mailgate, users_id) — можно,
     * поэтому без сеанса cron они не учитываются. $cronTask — имя сеанса автоматического действия
     * (Session::getLoginUserID(false)), пусто — не cron; 1.13.2: годится только сборщик почты.
     */
    public static function answerAuthor(array $fields, array $input, int $sessionUser, string $cronTask): int
    {
        $author = (int) ($fields['users_id'] ?? 0);
        if (!empty($fields['is_private']) || $author <= 0) { return 0; }
        if ($author === $sessionUser) { return $author; }
        return $cronTask === self::MAILGATE_CRON && !empty($input['_mailgate']) ? $author : 0;
    }

    /**
     * Публичный комментарий инициатора к заявке, которая ждёт его ответа на уточнение этапа
     * согласования: маршрут продолжается с отмеченного этапа — согласующим снова уходит запрос.
     * Повтор того же комментария ничего не делает (ключ команды — по номеру комментария).
     * Не продолжается сам — запись в журнал маршрута для администратора. Инициатору служебных
     * сообщений не показывается, своя запись в ленту не пишется: его ответ уже там.
     */
    public static function initiatorAnswered(\ITILFollowup $followup): void
    {
        $instance = null;
        try {
            self::initiatorAnsweredOnce($followup, $instance);
        } catch (\Throwable $e) {
            // Комментарий уже сохранён ядром: исключение в ядро не выпускаем (HTTP 500 у инициатора).
            // Запись — с номером комментария: по ней ответ догонит сверка «Сроки этапов» (catchUpAnswers).
            self::hookFailed($e, $instance, 'continue_failed', self::ANSWER_FAILED, 'ITILFollowup', (int) $followup->getID());
        }
    }

    /**
     * Запись журнала: инициатор ответил, а маршрут сразу не продолжился (отказ из-за блокировок, сбой). Пишется
     * с номером комментария; инициатору обещано «маршрут продолжится сам» — его продолжит сверка.
     */
    public const ANSWER_FAILED = 'Инициатор ответил на уточнение, но маршрут сразу не продолжился. Продолжит автоматическое '
        . 'действие «Сроки этапов» или «Продолжить» в строке этапа.';

    /**
     * Сбой обработчика события ядра: строка в itilflow.log; если маршрут уже известен — запись журнала
     * маршрута для администратора. Людям — без ошибки: маршрут ждёт, есть «Продолжить» и догоняющая сверка.
     * Ничего не бросает, даже при потерянной транзакции.
     */
    public static function hookFailed(\Throwable $error, ?Instance $instance, string $action, string $message,
        ?string $itemtype = null, int $items_id = 0): void
    {
        try {
            \Toolbox::logInFile('itilflow', sprintf("Обработчик маршрута не завершён (%s): %s: %s\n", $action, get_class($error),
                mb_substr($error->getMessage(), 0, 500)), true, false);
        } catch (\Throwable) {}
        if ($instance === null || (int) $instance->getID() <= 0) { return; }
        try {
            Violation::record($instance, $itemtype ?? (string) $instance->fields['itemtype'],
                $itemtype !== null ? $items_id : (int) $instance->fields['items_id'], $action, false, $message);
        } catch (\Throwable) {}
    }

    /** Тело initiatorAnswered; $instance — маршрут, как только ясно, что это ответ инициатора на уточнение. */
    private static function initiatorAnsweredOnce(\ITILFollowup $followup, ?Instance &$known): void
    {
        $type = (string) ($followup->fields['itemtype'] ?? '');
        if (!in_array($type, ['Ticket', 'Change', 'Problem'], true)) { return; }
        $author = self::answerAuthor($followup->fields, is_array($followup->input ?? null) ? $followup->input : [],
            (int) \Session::getLoginUserID(), \Session::isCron() ? (string) \Session::getLoginUserID(false) : '');
        if ($author <= 0) { return; }
        $item = new $type();
        if (!$item->getFromDB((int) ($followup->fields['items_id'] ?? 0)) || !$item->isUser(\CommonITILActor::REQUESTER, $author)) { return; }
        $instance = Instance::getForItem($item);
        $attempt = $instance !== null && DefinitionSnapshot::enabled($instance) ? Attempt::current($instance) : null;
        if ($attempt === null || $attempt->fields['state'] !== Attempt::WAITING_INPUT) { return; }
        $known = $instance;
        $text = self::answerText($followup);
        if ($text === '' || !self::answerContinues($instance)) {
            self::journalAnswer($instance, 'initiator_answered', 'Инициатор ответил на уточнение. Продолжите маршрут: «Продолжить».');
            return;
        }
        if (!self::continueFromAnswer($instance, $followup, $author, $text)) {
            $instance->getFromDB($instance->getID());
            // Комментарий и письмо (или «Продолжить») в один момент: маршрут продолжил другой ответ — это не сбой,
            // записи «сразу не продолжился» нет (её обещание про «Сроки этапов» было бы неправдой).
            if (self::answerStillAwaited(Attempt::current($instance)?->fields['state'] ?? null)) {
                self::journalAnswer($instance, 'continue_failed', self::ANSWER_FAILED, 'ITILFollowup', (int) $followup->getID());
            }
        }
    }

    /** Запуск по-прежнему ждёт ответа инициатора (состояние текущей попытки после неудачного продолжения). */
    public static function answerStillAwaited(?string $attemptState): bool
    {
        return $attemptState === Attempt::WAITING_INPUT;
    }

    /** Текст комментария без разметки. */
    private static function answerText(\ITILFollowup $followup): string
    {
        return trim(html_entity_decode(strip_tags((string) ($followup->fields['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Продолжить маршрут от ответа инициатора $author комментарием $followup. Ключ команды — по номеру
     * комментария: живой обработчик и догоняющая сверка не продолжат маршрут дважды. Без вошедшего (письмо,
     * сверка) этап переводит автор ответа.
     */
    private static function continueFromAnswer(Instance $instance, \ITILFollowup $followup, int $author, string $text): bool
    {
        $was = self::$quiet;
        $wasActor = self::$proxyActor;
        self::$quiet = true;
        if ((int) \Session::getLoginUserID() <= 0) { self::$proxyActor = $author; }
        try {
            $result = self::continueAttempt($instance, mb_substr($text, 0, 4000), 'Ответ инициатора в ленте заявки.',
                hash('sha256', 'initiator_answer:' . $followup->getID()), (int) $instance->fields['current_attempt_id'], true, $author);
        } finally {
            self::$quiet = $was;
            self::$proxyActor = $wasActor;
        }
        return in_array($result['status'] ?? '', ['applied', 'already_applied'], true);
    }

    /**
     * 1.13.2. Догнать ответ инициатора: заявка ждёт его ответа, а его комментарий маршрут сразу не
     * учёл (в журнале — «не продолжился» с номером комментария этой попытки). Комментарий проверяется заново:
     * в этой заявке, публичный, с текстом, автор — инициатор. Продолжение — тем же путём, что живой ответ.
     *
     * @return bool маршрут продолжен
     */
    public static function catchUpAnswer(Instance $instance): bool
    {
        try {
            if (!DefinitionSnapshot::enabled($instance)) { return false; }
            $attempt = Attempt::current($instance);
            if ($attempt === null || $attempt->fields['state'] !== Attempt::WAITING_INPUT || !self::answerContinues($instance)) { return false; }
            $since = (string) ($attempt->fields['date_creation'] ?? '');
            $records = (new Violation())->find(['plugin_itilflow_instances_id' => (int) $instance->getID(), 'action' => 'continue_failed',
                'itemtype' => 'ITILFollowup'] + ($since !== '' ? [['date' => ['>=', $since]]] : []), ['id DESC'], 1);
            $record = reset($records);
            $followup = new \ITILFollowup();
            $item = $instance->getItilItem();
            if ($record === false || $item === null || !$followup->getFromDB((int) $record['items_id'])
                || $followup->fields['itemtype'] !== $item->getType() || (int) $followup->fields['items_id'] !== (int) $item->getID()
                || !empty($followup->fields['is_private'])) { return false; }
            $author = (int) $followup->fields['users_id'];
            $text = self::answerText($followup);
            if ($author <= 0 || $text === '' || !$item->isUser(\CommonITILActor::REQUESTER, $author)) { return false; }
            if (!self::continueFromAnswer($instance, $followup, $author, $text)) { return false; }
            $instance->getFromDB($instance->getID());
            self::journalAnswer($instance, 'answer_caught_up', 'Ответ инициатора учтён автоматической сверкой — маршрут продолжился.',
                'ITILFollowup', (int) $followup->getID());
            return true;
        } catch (\Throwable $e) {
            self::hookFailed($e, $instance, 'answer_catch_up_failed', 'Ответ инициатора сверка не учла. Проверьте этап и нажмите «Продолжить».');
            return false;
        }
    }

    /**
     * Сверка ответов инициаторов по всем запускам, которые ждут ответа на уточнение (автоматическое действие
     * «Сроки этапов»), страницами по $page.
     *
     * @return int сколько маршрутов продолжено
     */
    public static function catchUpAnswers(int $page = 200): int
    {
        $done = 0;
        $after = 0;
        $page = max(1, $page);
        do {
            $rows = (new Attempt())->find(['state' => Attempt::WAITING_INPUT, ['id' => ['>', $after]]], ['id ASC'], $page);
            if ($rows === []) { break; }
            $after = (int) max(array_keys($rows));
            foreach ($rows as $aid => $row) {
                $instance = new Instance();
                if ($instance->getFromDB((int) $row['plugin_itilflow_instances_id'])
                    && (int) $instance->fields['current_attempt_id'] === (int) $aid && self::catchUpAnswer($instance)) { $done++; }
            }
        } while (count($rows) === $page);
        return $done;
    }

    /** Запись журнала маршрута об ответе инициатора; сбой записи не мешает его комментарию. */
    private static function journalAnswer(Instance $instance, string $action, string $message, ?string $itemtype = null, int $items_id = 0): void
    {
        try {
            Violation::record($instance, $itemtype ?? (string) $instance->fields['itemtype'],
                $itemtype !== null ? $items_id : (int) $instance->fields['items_id'], $action, false, $message);
        } catch (\Throwable) {
            TransactionScope::assertHealthy();
        }
    }

    private static function continueAttempt(Instance $instance, string $subject_summary, string $reason,
        string $command_key, int $expected_attempt, bool $byInitiator, int $actor = 0): array
    {
        $subject_summary = trim(strip_tags($subject_summary));
        $reason = trim(strip_tags($reason));
        return self::structuredCommand($instance, 'activate_attempt', $command_key, $expected_attempt,
            [$subject_summary, $reason], static function () use ($instance, $subject_summary, $reason, $byInitiator): array {
                $attempt = Attempt::current($instance);
                $item = $instance->getItilItem();
                $allowed = $byInitiator
                    // Инициатор продолжает только то, что продолжится от его ответа, и только живую заявку.
                    ? self::answerContinues($instance) && $item !== null && empty($item->fields['is_deleted'])
                        && Authorizer::contextError($instance) === null
                        && !in_array((int) $item->fields['status'], array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true)
                    : StructuredAccess::activateError($instance) === null;
                if (!$allowed || $attempt === null
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
                if ($byInitiator) {
                    // Ответ инициатора уже в ленте его комментарием: служебную запись от его имени не пишем,
                    // продолжение — в журнале маршрута.
                    if (!Violation::record($instance, (string) $instance->fields['itemtype'], (int) $instance->fields['items_id'],
                        'initiator_answered', false, 'Инициатор ответил комментарием — маршрут продолжился сам.')) {
                        throw new \RuntimeException('Запись журнала маршрута не сохранена.');
                    }
                } else {
                    self::structuredFollowup($instance, 'Инициатор ответил, маршрут продолжается: ' . $subject_summary);
                }
                self::advance($instance);
                return self::structuredResult('applied', $instance);
            }, $actor > 0 ? $actor : null);
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
                $fid = self::structuredFollowup($instance, 'Ответ отдела: ' . $public_summary);
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
                $fid = self::structuredFollowup($instance, 'Результат отдела: ' . $public_summary);
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
            if (in_array($row['state'], [Step::REJECTED, Step::DONE, Step::SKIPPED, Step::NOT_NEEDED], true)) {
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
                if ($row === null || !in_array($row['state'], [Step::DONE, Step::SKIPPED, Step::NOT_NEEDED], true)) {
                    throw new \RuntimeException('Принятая часть до точки возврата не подтверждена.');
                }
                if ($row['state'] === Step::NOT_NEEDED) {
                    if (!self::notNeededIsValid($instance, $row)) { throw new \RuntimeException('Пропуск этапа не подтверждён.'); }
                    continue;
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
            // У визы, которая ещё ждёт согласующего, объекта нет (NULL): исход закрытия — без объекта.
            'artifact_itemtype' => (string) ($step->fields['artifact_itemtype'] ?? ''), 'artifact_items_id' => (int) $step->fields['artifact_items_id'],
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
            : ' Нужен отдельный ответ инициатору.';
        $fid = self::structuredFollowup($instance, $text);
        self::mustUpdate($record, ['id' => $oid, 'public_followups_id' => $fid]);
        self::solveStructuredChild($step, $text);
        return $record;
    }

    private static function structuredFollowup(Instance $instance, string $text): int
    {
        $parent = $instance->getItilItem();
        if ($parent === null) {
            throw new \RuntimeException('Публичный итог не сохранён.');
        }
        // В корзину не пишем публичный комментарий. Результат остаётся в журнале
        // того же маршрута и той же транзакции; ошибка журнала не скрывается.
        if (!empty($parent->fields['is_deleted'])) {
            if (!Violation::record($instance, $parent->getType(), (int) $parent->getID(),
                'followup_in_trash', false, $text)) {
                throw new \RuntimeException('Итог для заявки в корзине не сохранён в журнале.');
            }
            return 0;
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

    /** @var array<string,true> запросы, которые сейчас снимает маршрут: «Тип#номер» */
    private static array $dropping = [];

    /** Запрос на согласование снимает сам маршрут — сейчас, в dropWaitingValidations. */
    public static function isDropping(\CommonDBTM $validation): bool
    {
        return isset(self::$dropping[$validation->getType() . '#' . (int) $validation->getID()]);
    }

    /** Маршрут сейчас снимает свои запросы: всё прочее, что ядро удалит заодно, — чужое. */
    public static function dropInProgress(): bool
    {
        return self::$dropping !== [];
    }

    /**
     * Снятый с маршрута запрос визы не остаётся ждать ответа у согласующего.
     *
     * Удаление — штатным delete. Два его побочных действия в ядре GLPI 11.0.8 перехватываются
     * штатными хуками (Hook::preItilUpdate, Hook::prePurgeValidation):
     * - пересчёт «Статуса согласования» идёт до того, как ядро уберёт опустевшую ступень, а пустую
     *   ступень оно считает отказом: заявка получала «Отклонено» — с уведомлением «Заявка изменена»
     *   и бизнес-правилами на это значение (CommonITILValidation::post_purgeItem);
     * - удаление опустевшей ступени удаляет все запросы заявки, а не только этой ступени
     *   (ITIL_ValidationStep::cleanDBonPurge), — в том числе чужие, например визу из формы.
     * Промежуточный пересчёт отменяется, чужие запросы не удаляются; итоговый статус записывается
     * один раз — тем же входом, что у ядра, с его уведомлением и правилами.
     *
     * Постусловия: снятых запросов нет, остальные запросы заявки на месте и в прежнем состоянии,
     * статус совпадает с расчётом ядра. Иначе — исключение: команда откатывается целиком.
     *
     * Данные итогового статуса читаются текущим чтением с блокировкой и сверяются со снимком
     * транзакции, по которому считает ядро (aggregateInputs). Состав этапа, который не сходится,
     * не превращается в «снимать нечего» (waitingOfStep).
     */
    private static function dropWaitingValidations(Step $step): void
    {
        $delegated = $step->fields['execution_mode'] === Stage::MODE_DELEGATED;
        if (!$delegated && $step->fields['execution_mode'] !== Stage::MODE_APPROVAL) { return; }
        $item = $delegated ? StructuredAccess::child($step) : $step->getInstance()?->getItilItem();
        if ($item === null || $item::getValidationClassInstance() === null) { return; }
        if ($delegated) {
            if (($step->getStage()?->fields['department_result_source'] ?? '') !== 'native_validation') { return; }
            $rows = StructuredAccess::roster($step, true);
            if ($rows === null) { self::dropFailed($step, 'не удалось проверить согласования дочерней заявки'); }
            $drop = [];
            foreach ($rows as $id => $row) {
                if ((int) $row['status'] === \CommonITILValidation::WAITING) { $drop[(int) $id] = $row['_type']; }
            }
        } else { $drop = self::waitingOfStep($step, $item); }
        // Снимаются только запросы, созданные маршрутом. Принятый вручную остаётся в заявке;
        // запрос формы снимает только «Виза из формы» — по квитанции подачи (dropBoundValidations).
        $kinds = $step->bindingKinds();
        foreach (array_keys($drop) as $id) {
            if (($kinds[$id] ?? OutcomeValidation::KIND_CREATED) !== OutcomeValidation::KIND_CREATED) {
                unset($drop[$id]);
            }
        }
        if (($step->getStage()?->fields['approver_source'] ?? '') === 'form') {
            $verified = FormAdoption::verifiedCancellationIds($step);
            foreach ($verified as $id) {
                if (($kinds[$id] ?? '') === OutcomeValidation::KIND_FORM) { $drop[$id] = get_class($item::getValidationClassInstance()); }
            }
        }
        self::dropValidations($step, $item, $drop);
    }

    /**
     * Снять неотвеченные запросы шага по точным номерам — тем же механизмом. Только внутри
     * команды маршрута (уровень транзакции открыт); номер, не привязанный к этому шагу или уже
     * отвеченный, не снимается. Точка подключения «Визы из формы»: доказанный запрос формы
     * снимается только так, после её собственных проверок (квитанция, исключительность шага).
     *
     * @param list<int> $ids
     */
    public static function dropBoundValidations(Step $step, array $ids): void
    {
        TransactionScope::requireActive();
        if ($step->fields['execution_mode'] !== Stage::MODE_APPROVAL) { return; }
        $item = $step->getInstance()?->getItilItem();
        if ($item === null || $item::getValidationClassInstance() === null) { return; }
        $verified = FormAdoption::verifiedCancellationIds($step);
        $kinds = $step->bindingKinds();
        $ids = array_values(array_filter($ids, static fn($id): bool => is_int($id)
            && in_array($id, $verified, true) && ($kinds[$id] ?? '') === OutcomeValidation::KIND_FORM));
        self::dropValidations($step, $item, array_intersect_key(self::waitingOfStep($step, $item), array_flip($ids)));
    }

    /** Будущий этап не получает фиктивной привязки: право снятия даёт повторно проверенная квитанция. */
    private static function dropFutureFormValidations(Step $step): void
    {
        TransactionScope::requireActive();
        if ($step->fields['state'] !== Step::PENDING) { return; }
        $item = $step->getInstance()?->getItilItem();
        if (!$item instanceof \Ticket) { return; }
        $ids = FormAdoption::futureCancellationIds($step);
        if ($ids === []) { return; }
        self::dropValidations($step, $item, array_fill_keys($ids, 'TicketValidation'), true);
    }

    /**
     * Механизм снятия 1.10.1 для заданного набора неотвеченных запросов шага: номер => тип.
     */
    private static function dropValidations(Step $step, \CommonITILObject $item, array $drop, bool $futureForm = false): void
    {
        if ($drop === []) { return; }
        [$do] = self::aggregateInputs($step, $item);
        $prochie = array_diff_key($do, $drop);

        $auditRaw = (string) ($step->fields['approval_dropped'] ?? '');
        $audit = ApprovalRoster::dropped($auditRaw);
        if ($audit === [] && trim($auditRaw) !== '' && trim($auditRaw) !== '[]') {
            self::dropFailed($step, 'история снятых запросов повреждена');
        }
        $outcome = Outcome::forStep((int) $step->getID());
        $dropReason = ($outcome?->fields['outcome'] ?? '') === Outcome::COMPLETED
            ? 'Этап выполнен; оставшийся без ответа запрос снят.'
            : 'Этап прерван; запрос без ответа снят.';
        if ($futureForm) {
            $dropReason = 'Маршрут закончился раньше этапа «' . (string) $step->fields['stage_name']
                . '»; запрос на согласование из формы без ответа снят.';
        }
        foreach ($drop as $id => $type) {
            $v = new $type();
            if (CurrentRead::load($v, (int) $id)) {
                if ((int) $v->fields['status'] !== \CommonITILValidation::WAITING) {
                    self::dropFailed($step, 'отвеченный запрос нельзя снять');
                }
                if ((Step::ownersMap($type, [(int) $id])[(int) $id] ?? []) !== ($futureForm ? [] : [(int) $step->getID()])) {
                    self::dropFailed($step, 'запрос связан с другим этапом');
                }
                $audit[(int) $id] = ['id' => (int) $id, 'itemtype_target' => (string) $v->fields['itemtype_target'],
                    'items_id_target' => (int) $v->fields['items_id_target'], 'date' => self::now(),
                    'reason' => $dropReason, 'users_id' => (int) (\Session::getLoginUserID() ?: 0)];
            }
        }
        $trigger = null;
        $validationsteps_id = null;
        foreach ($drop as $id => $type) {
            self::$dropping[$type . '#' . $id] = true;
        }
        self::enter();
        try {
            foreach ($drop as $id => $type) {
                $v = new $type();
                if (!$v->getFromDB($id)) { continue; } // уже снят: допустимо, постусловие то же
                $ivs = $item::getValidationStepInstance();
                if ($ivs !== null && $ivs->getFromDB((int) $v->fields['itils_validationsteps_id'])) {
                    $validationsteps_id = (int) $ivs->fields['validationsteps_id'];
                }
                $v->delete(['id' => $id], true);
                $trigger = $v;
            }
        } finally {
            self::$dropping = [];
            self::leave();
        }

        [$posle, $zayavka] = self::aggregateInputs($step, $item);
        if (array_intersect_key($posle, $drop) !== []) {
            self::dropFailed($step, 'неотвеченный запрос на согласование не снят: '
                . implode(',', array_keys(array_intersect_key($posle, $drop))));
        }
        foreach ($prochie as $id => $status) {
            if (($posle[$id] ?? null) !== $status) {
                self::dropFailed($step, 'снятие затронуло другой запрос заявки: ' . $id);
            }
        }
        self::mustUpdate($step, ['id' => $step->getID(), 'approval_dropped' => DefinitionSnapshot::encode(array_values($audit))]);
        // Снимок сверен с текущим чтением — расчёт ядра по нему верен.
        $want = \CommonITILValidation::computeValidationStatus($item);
        if ((int) $zayavka['global_validation'] === $want) { return; } // статус и так верный
        $input = ['id' => $item->getID(), 'global_validation' => $want, '_from_itilvalidation' => true];
        if ($trigger !== null) { $input['_trigger'] = $trigger; }
        if ($validationsteps_id !== null) { $input['_validationsteps_id'] = $validationsteps_id; }
        self::enter();
        try { $item->update($input); } finally { self::leave(); }
        if (!CurrentRead::load($item, (int) $item->getID()) || (int) $item->fields['global_validation'] !== $want) {
            self::dropFailed($step, '«Статус согласования» заявки не записан');
        }
    }

    /**
     * Неотвеченные запросы этапа: номер => тип.
     *
     * Штатно — по сверенному составу (StructuredAccess::roster). Запросов ещё нет (согласующего
     * не указали) — снимать нечего. Если связь есть, а состав не сходится — часть запросов удалили
     * вне маршрута (ядро GLPI до 1.10.1, вручную) или связь испорчена: это не «снимать нечего».
     * Тогда берутся сохранённые номера этапа — только запросы этой заявки, связанные только с этим
     * этапом; неотвеченные из них снимаются, расхождение пишется в журнал и показывается. Номер
     * чужой заявки или другого этапа, нераспознанная связь — отказ команды.
     */
    private static function waitingOfStep(Step $step, \CommonITILObject $item): array
    {
        $rows = StructuredAccess::roster($step);
        if ($rows !== null) {
            $out = [];
            foreach ($rows as $id => $row) {
                if ((int) $row['status'] === \CommonITILValidation::WAITING) { $out[(int) $id] = (string) $row['_type']; }
            }
            return $out;
        }
        $raw = trim((string) ($step->fields['approver_items_ids'] ?? ''));
        if ($raw === '' && (int) ($step->fields['artifact_items_id'] ?? 0) <= 0) {
            return []; // запросов ещё нет
        }
        $type = get_class($item::getValidationClassInstance());
        $ids = $step->validationIds();
        if ($ids === [] || (string) ($step->fields['artifact_itemtype'] ?? '') !== $type) {
            self::dropFailed($step, 'связь этапа с запросами записана неверно: ' . $raw);
        }
        $fk = $item::getForeignKeyField();
        $out = [];
        $naideno = CurrentRead::find(new $type(), ['id' => $ids]);
        foreach ($naideno as $id => $row) {
            if ((int) ($row[$fk] ?? 0) !== (int) $item->getID()) {
                self::dropFailed($step, 'запрос ' . $id . ' из связи этапа относится к другой заявке');
            }
            // Та же проверка организации, что у сверенного состава (Step::validationRoster), —
            // для всех найденных запросов, не только ждущих ответа.
            if ((int) ($row['entities_id'] ?? -1) !== (int) $item->fields['entities_id']) {
                self::dropFailed($step, 'запрос ' . $id . ' из связи этапа — в другой организации, чем заявка');
            }
            // Владельцы — по прежней строке номеров и по строкам привязок (в том числе этапа заявки
            // отдела): любая другая связь, идущая или прежняя, значит, что запрос не только этого этапа.
            $owners = Step::ownersOf($type, (int) $id);
            if (count($owners) !== 1 || (int) $owners[0]->getID() !== (int) $step->getID()) {
                self::dropFailed($step, 'запрос ' . $id . ' связан не только с этим этапом');
            }
            if ((int) $row['status'] === \CommonITILValidation::WAITING) { $out[(int) $id] = $type; }
        }
        \Toolbox::logInFile('itilflow', sprintf("Шаг %d: состав согласования не сходится (в связи %s, найдено %d); "
            . "снимаются неотвеченные: %s\n", (int) $step->getID(), implode(',', $ids), count($naideno),
            $out ? implode(',', array_keys($out)) : 'нет'), true, false);
        self::msg(sprintf('Этап %d «%s»: часть запросов на согласование удалили вне маршрута. Оставшиеся без ответа сняты.',
            (int) $step->fields['ranking'], (string) $step->fields['stage_name']), WARNING);
        return $out;
    }

    /**
     * Данные итогового «Статуса согласования»: запросы и ступени согласования заявки и сама заявка.
     *
     * Ядро считает статус обычным чтением — по снимку транзакции (REPEATABLE READ). Если снимок
     * сделан раньше, чем другая транзакция изменила эти данные (ответила на чужой запрос, добавила
     * новый), расчёт по нему устарел. Поэтому данные читаются ещё и текущим чтением с блокировкой —
     * оно же не даёт им измениться до конца команды, — и сверяются со снимком. Расхождение — отказ
     * команды: повтор пойдёт в новой транзакции со свежим снимком.
     *
     * @return array{0: array<int,int>, 1: array} запросы заявки: номер => статус; строка заявки
     */
    private static function aggregateInputs(Step $step, \CommonITILObject $item): array
    {
        global $DB;
        $fk = $item::getForeignKeyField();
        $id = (int) $item->getID();
        $chasti = [
            [get_class($item::getValidationClassInstance()), [$fk => $id], ['status', 'itils_validationsteps_id', $fk]],
            [get_class($item::getValidationStepInstance()), ['itemtype' => $item->getType(), 'items_id' => $id],
                ['minimal_required_validation_percent', 'validationsteps_id']],
            [$item->getType(), ['id' => $id], null],
        ];
        $tekushchie = [];
        foreach ($chasti as [$class, $where, $polya]) {
            $tek = CurrentRead::find(new $class(), $where);
            $snimok = [];
            foreach ($DB->request(['FROM' => $class::getTable(), 'WHERE' => $where]) as $row) {
                $snimok[(int) $row['id']] = $row;
            }
            $vybor = static function (array $rows) use ($polya): array {
                $out = [];
                foreach ($rows as $k => $row) {
                    $out[$k] = array_map('strval', array_map(static fn($v) => $v ?? '', $polya === null ? $row
                        : array_intersect_key($row, array_flip($polya))));
                }
                ksort($out);
                return $out;
            };
            if ($vybor($tek) !== $vybor($snimok)) {
                self::dropFailed($step, 'данные согласования изменились; обновите страницу');
            }
            $tekushchie[] = $tek;
        }
        $zapros = [];
        foreach ($tekushchie[0] as $vid => $row) {
            $zapros[(int) $vid] = (int) $row['status'];
        }
        $zayavka = $tekushchie[2][$id] ?? null;
        if ($zayavka === null) {
            self::dropFailed($step, 'заявка недоступна');
        }
        return [$zapros, $zayavka];
    }

    /**
     * Снятие запросов не подтверждено: команда не выполняется, транзакция откатывается (Engine::locked),
     * человек видит «Не удалось подтвердить результат действия…», причина — в itilflow.log.
     */
    private static function dropFailed(Step $step, string $why): never
    {
        \Toolbox::logInFile('itilflow', sprintf("Снятие запросов этапа (шаг %d) не подтверждено: %s\n",
            (int) $step->getID(), $why), true, false);
        throw new \RuntimeException('Снятие запросов на согласование не подтверждено.');
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
                self::dropFutureFormValidations($pending);
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
            self::dropWaitingValidations($step);
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
        $instance = null;
        try {
            $step = Step::ownerOf($event->getType(), $event->getID());
            $instance = $step?->getInstance();
            if ($step === null || $instance === null || !DefinitionSnapshot::enabled($instance)) { return; }
            // При отказе из-за блокировок согласующий видит правду: ответ сохранён, учтёт сверка.
            $ok = self::voteLocked($instance, static function () use ($instance, $step, $event): bool {
                if (StructuredAccess::validationError($event, $step) !== null) { return false; }
                return self::transferStructuredVote($instance, $step, $event);
            });
            if (!$ok) {
                $instance->getFromDB($instance->getID());
                self::hookFailed(new \RuntimeException('vote_not_applied'), $instance, 'vote_failed', self::VOTE_FAILED);
            }
        } catch (\Throwable $e) {
            // Ответ уже сохранён ядром: исключение в ядро не выпускаем. Догонит catchUpApprovals.
            self::hookFailed($e, $instance, 'vote_failed', self::VOTE_FAILED);
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
            || $need < ($step->hasExternalBindings() ? 0 : 1) || $need > 100) { return false; }
        ksort($rows);
        $digest = hash('sha256', DefinitionSnapshot::encode($rows));
        $existing = Outcome::forStep($step->getID());
        if ($existing !== null) { return hash_equals((string) $existing->fields['source_digest'], $digest); }
        $outcome = StructuredAccess::decide($step, $rows, $need);
        if ($outcome === null) { return true; } // промежуточный голос не порождает повторяемого объявления
        if ($outcome === Outcome::COMPLETED && !Decision::ready($step)) { return true; }
        $row = $rows[$source->getID()];
        if (!in_array((int) $row['status'], [\CommonITILValidation::ACCEPTED, \CommonITILValidation::REFUSED], true)
            || (int) ($row['users_id_validate'] ?? 0) <= 0) { return false; }
        // Виза сама по себе — публичный результат этапа: «согласовано» продвигает маршрут сразу,
        // причина отказа — прямой ответ заявителю.
        $comment = trim(strip_tags((string) ($row['comment_validation'] ?? '')));
        $public = $outcome === Outcome::COMPLETED ? 'Согласовано.' : ($comment !== '' ? $comment : 'Отклонено.');
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
        // Номер запуска на заявке — часть ключа своих ступеней согласования (ValidationLadder).
        $snapshot = DefinitionSnapshot::capture($process, ValidationLadder::nextRun($item));
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
            if (DefinitionSnapshot::schemaOf($instance) >= 3) { FormFacts::forStart($instance, $item); }
            self::advance($instance);
            return $instance;
        } finally { self::leave(); }
    }

    private static function createStructuredChild(Instance $instance, Step $step, Stage $stage,
        \CommonITILObject $parent): bool|string
    {
        $date_error = self::delegatedDeadlineError($stage, $parent);
        if ($date_error !== null) { throw new \RuntimeException($date_error); }
        $attempt = Attempt::current($instance);
        $entity = $stage->resolveEntity($parent);
        if ($attempt === null || !$stage->groupFitsEntity($entity)) { return 'Состав исполнителей недоступен.'; }
        $type = $parent->getType();
        $child = new $type();
        // Описание — HTML. Инструкция этапа из редактора уже HTML: идёт как есть, GLPI очищает
        // её при выводе; простой текст (через API) — абзацем, экранированным. Краткое содержание
        // основной заявки — текст из её HTML без тегов: сущности раскодируются и экранируются
        // один раз, иначе в описании видны «&lt;p&gt;» и «&amp;».
        $instruction = (string) $stage->fields['content'];
        $instruction = \Glpi\RichText\RichText::isRichTextHtmlContent($instruction)
            ? $instruction : '<p>' . nl2br(htmlescape($instruction)) . '</p>';
        $summary = html_entity_decode((string) $attempt->fields['subject_summary'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $input = ['name' => StepLine::childName((string) $stage->fields['name'], $parent->getID()),
            'content' => $instruction . '<p>' . nl2br(htmlescape($summary)) . '</p>',
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
        self::childWithoutInitiator($child, $parent);
        if ((int) $stage->fields['users_id'] > 0 && !Authorizer::userFitsItem((int) $stage->fields['users_id'], $child)) {
            throw new \RuntimeException('Исполнитель недоступен в организации отдела.');
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
                if (!is_int($vid) || $v === null || !CurrentRead::load($v, $vid)
                    || (int) $v->fields['entities_id'] !== $entity || $v->fields['itemtype_target'] !== $target_type
                    || (int) $v->fields['items_id_target'] !== $target_id) {
                    throw new \RuntimeException('Полный состав согласований отдела не создан.');
                }
                OutcomeValidation::bind($step, $v, $child, OutcomeValidation::KIND_CREATED);
            }
            self::mustUpdate($step, ['id' => $step->getID(), 'approval_expected_count' => count($targets)]);
            if (StructuredAccess::roster($step) === null) {
                throw new \RuntimeException('Полный состав согласований отдела не подтверждён.');
            }
        }
        return StructuredAccess::child($step) !== null ? true : 'Не удалось проверить связь с дочерней заявкой.';
    }

    private static function finishStructured(Instance $instance): void
    {
        $attempt = Attempt::current($instance);
        $steps = $instance->getAttemptSteps();
        if ($attempt === null || $attempt->fields['state'] !== Attempt::RUNNING || !count($steps)) {
            throw new \RuntimeException('Завершение попытки не подтверждено.');
        }
        foreach ($steps as $row) {
            if (!in_array($row['state'], [Step::DONE, Step::SKIPPED, Step::NOT_NEEDED], true)) {
                throw new \RuntimeException('Не все этапы завершены.');
            }
            if ($row['state'] === Step::NOT_NEEDED) {
                if (!self::notNeededIsValid($instance, $row)) { throw new \RuntimeException('Пропуск этапа не подтверждён.'); }
                continue;
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
        // Итог последнего этапа — содержательный ответ заявителю штатным решением заявки.
        // Обычно это итог последнего отдела. Если маршрут заканчивается задачей в самой заявке
        // («Итог заявителю»), ответом служит её отчёт — но только когда ход этапов и так пишется
        // в ленту заявителю: иначе отчёт задачи внутренний и в решение не попадает.
        $process = DefinitionSnapshot::process($instance);
        $public_log = !empty($process?->fields['public_log']);
        $final = '';
        foreach ($steps as $row) {
            $outcome = Outcome::forStep((int) $row['id']);
            if ($outcome !== null && trim((string) $outcome->fields['public_summary']) !== '') {
                $final = trim((string) $outcome->fields['public_summary']);
            } elseif ($public_log && $row['execution_mode'] === Stage::MODE_INLINE && $row['state'] === Step::DONE
                && ($report = trim(strip_tags((string) ($row['comment'] ?? '')))) !== '') {
                $final = $report;
            }
        }
        self::solveStructured($instance->getItilItem(), sprintf('Заявка выполнена: все этапы маршрута «%s» пройдены.',
            (string) ($process?->fields['name'] ?? '')) . ($final !== '' ? "\nРезультат: " . $final : ''));
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
                'content' => '<p>' . nl2br(htmlescape($text)) . '</p>', '_disable_auto_assign' => true]);
            // Последний ответ мог дать согласующий из самообслуживания: GLPI сохранит решение,
            // но отбросит смену статуса без права изменения. Статус ставит сам маршрут.
            if (CurrentRead::load($item, (int) $item->getID())
                && !in_array((int) $item->fields['status'], array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true)) {
                $item->update(['id' => $item->getID(), 'status' => $item::SOLVED, '_from_assignment' => true]);
            }
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
            self::structuredFollowup($instance, 'Маршрут закончен отказом: ' . trim(strip_tags($reason)));
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
                self::solveStructuredChild($step, 'Работа больше не нужна: маршрут прерван. ' . trim(strip_tags($reason)));
                self::dropFutureFormValidations($step);
                self::dropWaitingValidations($step);
                self::mustUpdate($step, ['id' => $step->getID(), 'state' => Step::SUPERSEDED, 'date_end' => self::now()]);
                // Задача идущего этапа не остаётся «К выполнению» (после «Начать заново» их было бы две).
                self::releaseInlineWork($instance, $step);
            }
        }
        self::mustUpdate($attempt, ['id' => $attempt->getID(), 'state' => Attempt::ABORTED, 'date_end' => self::now(),
            'source_step_id' => $source_id ?: null]);
        self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::ABORTED,
            'plugin_itilflow_stages_id_current' => 0, 'date_end' => self::now()]);
        self::structuredFollowup($instance, 'Маршрут прерван: ' . trim(strip_tags($reason)));
    }
}
