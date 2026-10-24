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
    use ConditionalFlow;
    /** Прежнее имя общей записи «Этап согласования» до 1.11 — только ключ поиска, не переименовываем. */
    public const SHARED_LEGACY = 'Этап маршрута — порог %d%%';
    /** Защита от рекурсии: объекты, которые создаёт сам движок, не должны его же и триггерить. */
    private static int $inside = 0;

    /** Вызов только из зарегистрированного штатного cron; без подмены сессии. */
    public static function synchronizeStatusFromCron(Step $step, \CronTask $task): array
    {
        StatusSync::assertCron($task);
        self::enter();
        try { return StatusSync::runCron($step, $task); }
        finally { self::leave(); }
    }

    public static function isInside(): bool
    {
        TransactionScope::assertHealthy();
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
        self::touch($object);
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

    /**
     * Сообщение после перехода. Текст — простой, с названиями маршрутов, этапов,
     * групп и причинами, а GLPI 11 выводит такие сообщения как HTML: экранирует
     * тот, кто пишет. Поэтому здесь, для всех сообщений движка сразу.
     */
    private static function msg(string $text, int $level = INFO): void
    {
        if (self::$quiet) {
            return; // 1.13: запуск по ответу формы — служебное не показывается инициатору заявки
        }
        Session::addMessageAfterRedirect(htmlescape($text), false, $level);
    }

    /** На время Engine::startFromForm сообщения движка не выводятся. */
    private static bool $quiet = false;

    /**
     * Чьё действие применяет автоматическое действие без вошедшего: ответ письмом (сборщик почты) — его
     * автор, инициатор; догоняющая сверка ответов — согласующий, чей ответ решил этап. Ставят только
     * initiatorAnswered и catchUpApproval в сеансе автоматического действия; из браузера его не подделать.
     */
    private static int $proxyActor = 0;

    /** Кто переводит этап: вошедший, а в автоматическом действии — автор применяемого действия. 0 — неизвестно. */
    public static function sourceActor(): int
    {
        $user = (int) Session::getLoginUserID();
        if ($user > 0) { return $user; }
        return Session::isCron() ? self::$proxyActor : 0;
    }

    /** Идёт «Возобновить»: этап, остановленный сроком, берёт срок и SLA из текущей настройки этапа. */
    private static bool $resuming = false;

    /**
     * Срок и SLA этапа при «Возобновить» после остановки сроком.
     * Снимок запуска хранит прежний срок (например, 5000 дней из 1.10.x): без этого «Возобновить»
     * снова останавливал маршрут тем же «Укажите меньше», и выход был один — прервать маршрут.
     * Берутся только срок и SLA, только у этапа того же маршрута; остальное — по снимку запуска.
     *
     * @param array      $snapshot поля этапа из снимка запуска
     * @param array|null $live     поля этапа из базы сейчас; null — этапа больше нет
     */
    public static function resumeDeadline(array $snapshot, ?array $live, int $processId): array
    {
        if ($live === null || $processId <= 0 || (int) ($live['plugin_itilflow_processes_id'] ?? 0) !== $processId) {
            return $snapshot;
        }
        foreach (['deadline_minutes', 'slas_id'] as $field) {
            if (array_key_exists($field, $live)) { $snapshot[$field] = (int) $live[$field]; }
        }
        return $snapshot;
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
        return sprintf('«%s»', (string) $step->fields['stage_name']);
    }

    /** Сколько раз исполнитель команд пробует транзакцию, если сервер прервал её из-за блокировок. */
    public const CONFLICT_TRIES = 3;

    /** Объекты, прочитанные или изменённые в транзакции команды: при повторе их поля — снова из базы. */
    private static ?\WeakMap $touched = null;

    /** Пауза перед повтором (номер попытки); null — случайная 30–150 мс × номер. Подменяют только тесты. */
    private static ?\Closure $conflictPause = null;

    public static function touch(\CommonDBTM $object): void
    {
        if (!TransactionScope::hasActive()) { return; }
        self::$touched ??= new \WeakMap();
        self::$touched[$object] = true;
    }

    /** Отказ команды из-за блокировок: ничего не сохранено, человек может повторить. */
    public const CONFLICT_MESSAGE = 'Не удалось выполнить действие: заявку в этот момент меняли другие. Повторите.';

    /**
     * Ответ согласующего уже сохранён GLPI, а маршрут его не учёл из-за блокировок: «Повторите» было бы
     * неправдой — этап догонит сверка (catchUpApprovals), администратору — запись журнала (vote_failed).
     */
    public const VOTE_SAVED_MESSAGE = 'Ваш ответ сохранён. Заявку в этот момент меняли другие — '
        . 'маршрут учтёт ответ при автоматической сверке.';

    /** Текст отказа из-за блокировок для текущего обработчика; null — CONFLICT_MESSAGE. */
    private static ?string $conflictText = null;

    /** Последний retrying отказал из-за блокировок (все попытки или 1205). Сбрасывает тот, кто проверяет. */
    private static bool $gaveUp = false;

    /**
     * Исполнитель команд: одна транзакция на попытку. Взаимная блокировка (1213) — ещё попытка после
     * короткой случайной паузы, всего CONFLICT_TRIES; перед повтором — поля прочитанных объектов снова
     * из базы и сообщения GLPI как до попытки: откаченная попытка не оставляет ни данных, ни сообщений.
     * Истекло ожидание блокировки (1205) — сразу отказ, без повтора: ожидание уже длилось до
     * innodb_lock_wait_timeout (50 с), повтор упёрся бы в таймаут nginx на проде (60 с).
     *
     * @template T
     * @param callable(): T $once попытка; бросает TransactionConflict, если её откатил сервер
     * @param T $failed результат, если команда не выполнена
     * @return T
     */
    public static function retrying(callable $once, mixed $failed, ?\Closure $pause = null): mixed
    {
        for ($try = 1; ; $try++) {
            $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? null;
            $outer = self::$touched;
            self::$touched = new \WeakMap();
            try {
                return $once();
            } catch (TransactionConflict $conflict) {
                if ($messages === null) { unset($_SESSION['MESSAGE_AFTER_REDIRECT']); }
                else { $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messages; }
                foreach (self::$touched as $object => $_) {
                    $id = (int) ($object->fields['id'] ?? 0);
                    if ($id > 0 && !$object->getFromDB($id)) { $object->fields = []; }
                }
                // 1.13.2: откат возвращает базу, но не то, что хук другого плагина уже сделал наружу
                // (письмо, HTTP, файл). Если команда успела менять объекты, на которые подписаны другие
                // плагины, повтор сделал бы это ещё раз — тогда без повтора: понятный отказ; ответ согласующего
                // и инициатора догонит сверка «Сроки этапов».
                $foreign = $conflict->retryable && $conflict->afterStart && $try < self::CONFLICT_TRIES ? self::foreignHooks() : [];
                if (!$conflict->retryable || $try >= self::CONFLICT_TRIES || $foreign !== []) {
                    try {
                        \Toolbox::logInFile('itilflow', sprintf("Команда маршрута отклонена сервером из-за блокировок (попыток: %d)%s: %s\n",
                            $try, $foreign !== [] ? '; без повтора — изменения видят другие плагины: ' . implode(', ', $foreign) : '',
                            mb_substr($conflict->getPrevious()?->getMessage() ?? '', 0, 300)), true, false);
                    } catch (\Throwable) {}
                    self::$gaveUp = true;
                    self::msg(self::$conflictText ?? self::CONFLICT_MESSAGE, self::$conflictText === null ? ERROR : WARNING);
                    return $failed;
                }
                ($pause ?? self::$conflictPause ?? static fn(int $n) => usleep(random_int(30_000, 150_000) * $n))($try);
            } finally {
                self::$touched = $outer;
            }
        }
    }

    /** События объектов, на которые подписываются плагины GLPI (Glpi\Plugin\Hooks). */
    private const OBJECT_HOOKS = ['pre_item_add', 'item_add', 'pre_item_update', 'item_update', 'pre_item_delete', 'item_delete',
        'pre_item_purge', 'item_purge', 'pre_item_restore', 'item_restore'];

    /** Объекты GLPI, которые создаёт, меняет или снимает команда маршрута. */
    private const WRITTEN_TYPES = ['Ticket', 'Change', 'Problem', 'ITILFollowup', 'ITILSolution', 'TicketTask', 'ChangeTask',
        'ProblemTask', 'TicketValidation', 'ChangeValidation', 'TicketValidationStep', 'ChangeValidationStep', 'ValidationStep',
        'Ticket_User', 'Group_Ticket', 'Change_User', 'Change_Group', 'Problem_User', 'Group_Problem', 'Ticket_Ticket',
        'Change_Change', 'Problem_Problem'];

    /**
     * Другие включённые плагины, подписанные на изменения объектов, которые меняет команда маршрута
     * (их хук мог уже сработать в откаченной попытке). Пусто — повтор безопасен.
     *
     * @return list<string> ключи плагинов
     */
    public static function foreignHooks(): array
    {
        global $PLUGIN_HOOKS;
        $out = [];
        foreach (self::OBJECT_HOOKS as $event) {
            foreach (is_array($PLUGIN_HOOKS[$event] ?? null) ? $PLUGIN_HOOKS[$event] : [] as $plugin => $types) {
                $plugin = (string) $plugin;
                if ($plugin === 'itilflow' || isset($out[$plugin]) || !is_array($types)
                    || array_intersect(array_map('strval', array_keys($types)), self::WRITTEN_TYPES) === []) { continue; }
                try {
                    if (\Plugin::isPluginActive($plugin)) { $out[$plugin] = $plugin; }
                } catch (\Throwable) {
                    $out[$plugin] = $plugin; // не узнать — считаем подписанным: лучше отказ, чем двойной вызов
                }
            }
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * locked() для обработчика ответа согласующего: ответ уже сохранён GLPI, поэтому при отказе из-за
     * блокировок — не «Повторите», а VOTE_SAVED_MESSAGE. $conflicted — отказ был из-за блокировок.
     */
    private static function voteLocked(Instance $instance, callable $operation, ?bool &$conflicted = null): bool
    {
        $text = self::$conflictText;
        self::$conflictText = self::VOTE_SAVED_MESSAGE;
        self::$gaveUp = false;
        try {
            $ok = self::locked($instance, $operation);
        } finally {
            self::$conflictText = $text;
        }
        $conflicted = !$ok && self::$gaveUp;
        self::$gaveUp = false;
        return $ok;
    }

    /** Серверная операция сериализуется по экземпляру; повтор читает новое состояние. */
    private static function locked(Instance $instance, callable $operation): bool
    {
        return self::retrying(static fn(): bool => self::lockedOnce($instance, $operation), false);
    }

    /** Одна попытка locked(): отказ из-за блокировок — TransactionConflict (транзакция откачена). */
    private static function lockedOnce(Instance $instance, callable $operation): bool
    {
        global $DB;
        $scope = null;
        $started = false;
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
            $started = true; // дальше команда меняет объекты GLPI
            $ok = $operation();
            if (!$ok) {
                $scope->rollback();
                return false;
            }
            $scope->commit();
            return true;
        } catch (\Throwable $e) {
            if ($scope !== null && TransactionScope::isConflict($e)) {
                // Сервер откатил транзакцию сам — состояние известно, попытку можно повторить.
                $scope->rollbackAfterConflict();
                throw new TransactionConflict($e, $started);
            }
            if ($scope !== null) {
                try {
                    $scope->rollback();
                } catch (\Throwable $rollback_error) {
                    // Исход неизвестен; не обещаем откат и не повторяем действие.
                }
            }
            TransactionScope::assertHealthy();
            self::msg('Не удалось проверить, выполнено ли действие. Обновите страницу и посмотрите вкладку маршрута.', ERROR);
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
                $expected_attempt, [$step->getID(), $reason, $payload], $apply, $denial)
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
                $expected_attempt, [$reason], $apply, $denial)
            : self::locked($instance, $apply);
        if ($denial !== null) {
            self::auditAction($instance, null, $action, true);
            self::msg($denial, ERROR);
        }
        return $ok;
    }

    public static function completeStep(Step $step, string $comment = '', bool $skipped = false, string $command_key = '', int $expected_attempt = 0, ?int $decision_revision = null, ?array $decision_answers = null): bool
    {
        return self::manualStep($step, $skipped ? 'skip' : 'complete', $comment,
            static fn(): bool => self::prepareDecisionForOutcome($step, $decision_revision, $decision_answers)
                && self::completeStepInternal($step, $comment, $skipped), $command_key, $expected_attempt,
            Decision::items($step) === [] ? [] : [$decision_revision, $decision_answers]);
    }

    /** Привилегированное решение явно отделено от голоса согласующего. */
    public static function overrideStep(Step $step, string $reason, string $command_key = '', int $expected_attempt = 0): bool
    {
        return self::manualStep($step, 'override', $reason,
            static fn(): bool => self::completeStepInternal($step, 'Принудительное завершение: ' . $reason, false, true), $command_key, $expected_attempt);
    }

    public static function designateApprover(Step $step, array $groups_ids, array $users_ids, string $reason, string $command_key = '', int $expected_attempt = 0, string $group_mode = 'any', ?int $fallback_percent = null): bool
    {
        return self::manualStep($step, 'set_approver', $reason,
            static fn(): bool => self::designateApproverInternal($step, $groups_ids, $users_ids, $reason, $group_mode, $fallback_percent), $command_key, $expected_attempt, [$groups_ids, $users_ids, $group_mode, $fallback_percent]);
    }

    /** Серверный путь из callback/открытия этапа. Права заполнившего форму не повышаются. */
    public static function adoptFormRequests(Step $step, array $ids, array $receipt): bool
    {
        TransactionScope::requireActive();
        $instance = $step->getInstance();
        if ($instance === null || !CurrentRead::load($instance, (int) $instance->getID())
            || !CurrentRead::load($step, (int) $step->getID()) || StructuredAccess::context($step) === null
            || (int) ($step->fields['pass_number'] ?? 0) !== 1) { return false; }
        $stored = FormAdoption::storedReceipt($step);
        if ($stored === null || ($stored['key'] ?? null) !== ($receipt['key'] ?? null)
            || ($stored['digest'] ?? null) !== ($receipt['digest'] ?? null)) { return false; }
        if (!$step->awaitsFormRequests()) {
            $existing = OutcomeValidation::forStep($step);
            $bound = array_map('intval', array_keys($existing)); sort($bound); sort($ids);
            return $bound === $ids && $bound !== []
                && count(array_filter($existing, static fn(array $b): bool => OutcomeValidation::kindOf($b) !== OutcomeValidation::KIND_FORM)) === 0;
        }
        return self::bindExternalRequests($step, $ids, OutcomeValidation::KIND_FORM, $stored, '');
    }

    public static function adoptExistingRequests(Step $step, array $ids, string $reason, string $key, int $attempt, ?string $confirmation = null): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return ['status' => 'rejected']; }
        $reason = trim(strip_tags($reason));
        sort($ids);
        if (!DefinitionSnapshot::enabled($instance)) {
            // Старый контракт не получает выдуманную квитанцию. Повтор закрывается уже существующей связью.
            $ok = self::locked($instance, static function () use ($instance, $step, $ids, $reason, $confirmation): bool {
                if (!CurrentRead::load($step, (int) $step->getID()) || $reason === ''
                    || Authorizer::formAdoptionError($step) !== null) { return false; }
                return self::bindExternalRequests($step, $ids, OutcomeValidation::KIND_MANUAL, null, $reason, $confirmation)
                    && self::auditAction($instance, $step, 'adopt_existing', false, $reason);
            });
            return ['status' => $ok ? 'applied' : 'rejected'];
        }
        return self::structuredCommand($instance, 'adopt_existing', $key, $attempt, [$step->getID(), $ids, $reason, $confirmation],
            static function () use ($instance, $step, $ids, $reason, $confirmation): array {
                if ($reason === '' || Authorizer::formAdoptionError($step) !== null
                    || !self::bindExternalRequests($step, $ids, OutcomeValidation::KIND_MANUAL, null, $reason, $confirmation)) {
                    return self::structuredResult('rejected', $instance);
                }
                return self::structuredResult('applied', $instance, $step, Outcome::forStep($step->getID()));
            });
    }

    /** Проверки предшествуют первой привязке; ступень и её порог остаются штатными. */
    private static function bindExternalRequests(Step $step, array $ids, string $kind, ?array $receipt, string $reason, ?string $confirmation = null): bool
    {
        TransactionScope::requireActive();
        if (!(($kind === OutcomeValidation::KIND_FORM) ? $step->awaitsFormRequests() : $step->awaitsExistingRequests())
            || Authorizer::contextError($step->getInstance(), $step) !== null || !$ids || count($ids) > 50) { return false; }
        foreach ($ids as $id) { if (!is_int($id) || $id <= 0) { return false; } }
        if (count(array_unique($ids)) !== count($ids)) { return false; }
        sort($ids);
        $instance = $step->getInstance(); $item = $instance?->getItilItem();
        if (!$item instanceof \Ticket || !CurrentRead::load($item, (int) $item->getID())) { return false; }
        $rows = CurrentRead::find(new \TicketValidation(), ['id' => $ids]);
        $plan = OutcomeValidation::adoptionPlan(array_values($rows), ['ticket_id' => (int) $item->getID(), 'entity_id' => (int) $item->fields['entities_id']]);
        if ($plan === null || $plan['ids'] !== $ids) { return false; }
        $native = $item::getValidationStepInstance();
        if ($native === null || !CurrentRead::load($native, $plan['step_id'])
            || ($native->fields['itemtype'] ?? '') !== 'Ticket'
            || (int) ($native->fields['items_id'] ?? 0) !== (int) $item->getID()) { return false; }
        $percent = (int) $native->fields['minimal_required_validation_percent'];
        if ($percent < 0 || $percent > 100) { return false; }
        $all = CurrentRead::find(new \TicketValidation(), ['tickets_id' => $item->getID(), 'itils_validationsteps_id' => $plan['step_id']]);
        $allIds = array_map('intval', array_keys($all)); sort($allIds);
        if ($allIds !== $ids) { return false; }
        foreach (Step::ownersMap('TicketValidation', $ids) as $owners) { if ($owners !== []) { return false; } }
        if ($kind === OutcomeValidation::KIND_MANUAL) {
            if (Authorizer::formAdoptionError($step, $plan['answered']) !== null
                || !ManualAdoptionConfirmation::accepts(array_values($rows), $native->fields, $confirmation)) { return false; }
        } else {
            if ($receipt === null || (int) ($receipt['source']['ticket_id'] ?? 0) !== (int) $item->getID()
                || (int) ($receipt['source']['entity_id'] ?? -1) !== (int) $item->fields['entities_id']
                || (int) ($receipt['step_id'] ?? 0) !== $plan['step_id']
                || (int) ($receipt['approval_percent'] ?? -1) !== $percent
                || array_column($receipt['validations'] ?? [], 'id') !== $ids) { return false; }
            foreach ($receipt['validations'] as $expected) {
                $row = $rows[$expected['id']] ?? null;
                if ($row === null || $row['itemtype_target'] !== $expected['itemtype_target']
                    || (int) $row['items_id_target'] !== $expected['items_id_target']
                    || (int) $native->fields['validationsteps_id'] !== $expected['validationsteps_id']) { return false; }
            }
        }
        foreach ($rows as $row) {
            $v = new \TicketValidation(); $v->fields = $row;
            OutcomeValidation::bind($step, $v, $item, $kind);
        }
        self::mustUpdate($step, ['id' => $step->getID(), 'artifact_itemtype' => 'TicketValidation',
            'artifact_items_id' => $ids[0], 'approver_items_ids' => '', 'approval_snapshot_source' => 'definition', 'approval_expected_count' => count($ids),
            'approval_percent_snapshot' => $percent, 'approver_set_by' => $kind === OutcomeValidation::KIND_MANUAL ? (int) \Session::getLoginUserID() : 0,
            'approver_reason' => $reason]);
        if (!DefinitionSnapshot::enabled($instance)) {
            return self::evaluateApproval($step, 'Принят существующий запрос: ' . $reason);
        }
        // Для позднего принятия автор исхода — автор голоса соответствующего итогового вида.
        $aggregate = StructuredAccess::nativeAggregate($step);
        if ($aggregate === 'unconfirmed') { throw new \RuntimeException('Итог внешней ступени не подтверждён.'); }
        $wanted = $aggregate === Outcome::COMPLETED ? 3 : ($aggregate === Outcome::REFUSED ? 4 : null);
        foreach ($rows as $row) {
            if ($wanted !== null && (int) $row['status'] === $wanted) {
                $v = new \TicketValidation(); $v->fields = $row;
                if (!self::transferStructuredVote($instance, $step, $v)) {
                    throw new \RuntimeException('Сохранённый ответ не перенесён в маршрут.');
                }
                break;
            }
        }
        return true;
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
                'Для этой заявки нет подходящего правила запуска маршрута. Проверьте бизнес-правило.');
            return null;
        }
        return self::startTransaction($item, $processes_id, true);
    }

    /**
     * Запуск по ответу формы: PID — только из одноразового выбора
     * FormStart в обратном вызове формы (как у правила — из RuleStart). Права текущего
     * пользователя не проверяются: форму отправляет инициатор заявки, маршрут выбрал сервер.
     * Тот же путь запуска, что у правила: startTransaction(…, true).
     */
    public static function startFromForm(CommonITILObject $item): ?Instance
    {
        $processes_id = FormStart::consume($item);
        if ($processes_id === null) {
            return null;
        }
        $process = new Process();
        if (!$process->getFromDB($processes_id) || !Authorizer::processFitsItem($process, $item)) {
            Violation::record(null, $item->getType(), $item->getID(), 'action_form_start', true,
                'Маршрут по ответу формы не запущен: маршрут недоступен в организации заявки.',
                (int) ($item->fields['entities_id'] ?? 0));
            return null;
        }
        // Причину отказа администратор маршрутов увидит в журнале (FormStart), не инициатор.
        $quiet = self::$quiet;
        self::$quiet = true;
        try {
            return self::startTransaction($item, $processes_id, true);
        } finally {
            self::$quiet = $quiet;
        }
    }

    private static function startTransaction(CommonITILObject $item, int $processes_id, bool $from_rule = false): ?Instance
    {
        // Заявка — такой, какой её увидели правило, форма или человек: снимается один раз, до всех попыток.
        // 1.13.2: при повторе после 1213 объекты перечитываются из базы, и сравнение «как увидели»
        // с «как в базе» шло бы базы с базой — запуск на уже изменённой заявке не ловился.
        $observed = $item->fields;
        return self::retrying(static fn(): ?Instance => self::startOnce($item, $processes_id, $from_rule, $observed), null);
    }

    /** Одна попытка запуска: отказ из-за блокировок — TransactionConflict (транзакция откачена). */
    private static function startOnce(CommonITILObject $item, int $processes_id, bool $from_rule, array $observed): ?Instance
    {
        $scope = null;
        $started = false;
        try {
            $scope = new TransactionScope();
            if (!CurrentRead::load($item, (int) $item->getID())) { $scope->rollback(); return null; }
            foreach (['entities_id', 'status', 'is_deleted', 'name', 'content'] as $field) {
                if (($observed[$field] ?? null) !== ($item->fields[$field] ?? null)) {
                    // Свидетельство правила относится к проверенному предмету, не к конкурентно изменённому.
                    $scope->rollback(); return null;
                }
            }
            if (!$from_rule && Authorizer::startError($item, $processes_id) !== null) { $scope->rollback(); return null; }
            // Уникальная связь instance(itemtype,items_id) сохраняется; конкурентный start не повторяется.
            $started = true; // дальше запуск создаёт объекты GLPI
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
            if ($scope !== null && TransactionScope::isConflict($e)) {
                $scope->rollbackAfterConflict();
                throw new TransactionConflict($e, $started);
            }
            if ($scope !== null) {
                try {
                    $scope->rollback();
                } catch (\Throwable $rollback_error) {
                    // Подтверждения отката нет. Новый start автоматически не выполняется.
                }
            }
            TransactionScope::assertHealthy();
            self::msg('Не удалось проверить, выполнено ли действие. Обновите страницу и посмотрите вкладку маршрута.', ERROR);
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
            // Решённая дочерняя с отклонённой визой — отказ отдела, а не «выполнено».
            if ((int) ($child->fields['global_validation'] ?? 0) === CommonITILValidation::REFUSED) {
                self::rejectStepInternal($step, 'Дочерняя заявка: согласование отклонено.');
                return self::auditAction($instance, $step, 'delegated_refused', false);
            }
            return self::completeStepInternal($step, 'Дочерняя заявка решена.')
                && self::auditAction($instance, $step, 'delegated_complete', false);
        });
    }

    /** Запись журнала: ответ согласующего сохранён в GLPI, а маршрут его не учёл. */
    public const VOTE_FAILED = 'Ответ согласующего сохранён, но маршрут его не учёл. Учтёт автоматическое действие '
        . '«Сроки этапов» или «Перенести ответ согласующего» в меню этапа.';

    /** Ответ согласующего сохранён ядром; обработчик не выпускает исключение в ядро. */
    public static function validationUpdated(CommonITILValidation $event): void
    {
        $instance = null;
        try {
            $bound = Step::ownerOf($event->getType(), $event->getID());
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
            $ok = self::voteLocked($instance, static function () use ($step, $instance, $validation): bool {
                if (Authorizer::validationError($validation, $step) !== null) {
                    return false;
                }
                return self::evaluateApproval($step, strip_tags((string) ($validation->fields['comment_validation'] ?? '')))
                    && self::auditAction($instance, $step, 'approval_result', false);
            }, $conflicted);
            if (!$ok && $conflicted) {
                $instance->getFromDB($instance->getID());
                self::hookFailed(new \RuntimeException('vote_conflict'), $instance, 'vote_failed', self::VOTE_FAILED);
            }
        } catch (\Throwable $e) {
            self::hookFailed($e, $instance, 'vote_failed', self::VOTE_FAILED);
        }
    }

    /**
     * Ответ согласующего, который решил этап, — для догоняющей сверки: строка запроса с нужным ответом
     * (ACCEPTED для «согласовано», REFUSED для «отклонено») и автором; из нескольких — последний по
     * дате ответа, затем по номеру. null — такого нет.
     *
     * @param array<int,array> $rows строки запросов этапа (roster)
     */
    public static function decisiveVote(array $rows, string $outcome): ?int
    {
        $want = $outcome === Outcome::COMPLETED ? CommonITILValidation::ACCEPTED : CommonITILValidation::REFUSED;
        $best = null;
        foreach ($rows as $id => $row) {
            if ((int) ($row['status'] ?? 0) !== $want || (int) ($row['users_id_validate'] ?? 0) <= 0) { continue; }
            $key = [(string) ($row['validation_date'] ?? ''), (int) $id];
            if ($best === null || $key > $best) { $best = $key; }
        }
        return $best === null ? null : $best[1];
    }

    /**
     * Догнать ответ согласующего: этап согласования идёт, а по его запросам решение уже есть — ответ
     * сохранён в GLPI, но обработчик ответа не завершился (блокировки при одновременных ответах).
     * Решение — тем же путём, что при ответе; источник перехода — согласующий, чей ответ решил этап.
     * Без решения (ждём ответов, у «согласовано» не отвечены вопросы этапа) ничего не делает.
     *
     * @return array{type:string,id:int,outcome:string}|null
     */
    public static function catchUpPlan(Step $step): ?array
    {
        $instance = $step->getInstance();
        if ($instance === null || $step->fields['state'] !== Step::RUNNING) { return null; }
        $structured = DefinitionSnapshot::enabled($instance);
        $delegatedVote = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
            && ($step->getStage()?->fields['department_result_source'] ?? '') === 'native_validation';
        if ($step->fields['execution_mode'] !== Stage::MODE_APPROVAL && !($structured && $delegatedVote)) { return null; }
        if ($structured && Outcome::forStep((int) $step->getID()) !== null) { return null; }
        $rows = $structured ? StructuredAccess::roster($step) : $step->validationRoster();
        $need = (int) ($step->fields['approval_percent_snapshot'] ?? 0);
        if ($rows === null || $rows === [] || $need < ($step->hasExternalBindings() ? 0 : 1) || $need > 100) { return null; }
        $outcome = StructuredAccess::decide($step, $rows, $need);
        if ($outcome === null || ($structured && $outcome === Outcome::COMPLETED && !Decision::ready($step))) { return null; }
        if (!self::answersByAddressees($rows)) { return null; }
        $id = self::decisiveVote($rows, $outcome);
        if ($id === null) { return null; }
        $type = (string) ($rows[$id]['_type'] ?? $step->fields['artifact_itemtype']);
        return in_array($type, ['TicketValidation', 'ChangeValidation'], true) ? ['type' => $type, 'id' => $id, 'outcome' => $outcome] : null;
    }

    /**
     * 1.13.2. Каждый ответ этапа дал тот, кому запрос сейчас адресован: адресат, участник группы
     * или его действующий заместитель (ValidationAuthority::addressedTo — та же проверка, что у живого
     * ответа, только без сессии). Ответ, который маршрут отклонил из-за гонки (заместителя сняли, человек
     * вышел из группы), сверка не засчитывает — этап решает администратор маршрутов («Перенести ответ
     * согласующего»).
     *
     * @param array<int,array> $rows строки запросов этапа
     */
    public static function answersByAddressees(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!in_array((int) ($row['status'] ?? 0), [CommonITILValidation::ACCEPTED, CommonITILValidation::REFUSED], true)) { continue; }
            if (!ValidationAuthority::addressedTo((int) ($row['users_id_validate'] ?? 0), $row)) { return false; }
        }
        return true;
    }

    /** Догнать один этап (catchUpPlan). true — маршрут учёл ответ. Сбой — в журнал маршрута, без исключения. */
    public static function catchUpApproval(Step $step): bool
    {
        $instance = $step->getInstance();
        $plan = self::catchUpPlan($step);
        if ($instance === null || $plan === null) { return false; }
        $vote = new $plan['type']();
        if (!$vote->getFromDB($plan['id'])) { return false; }
        $quiet = self::$quiet;
        $actor = self::$proxyActor;
        self::$quiet = true;
        if ((int) Session::getLoginUserID() <= 0) { self::$proxyActor = (int) $vote->fields['users_id_validate']; }
        try {
            $structured = DefinitionSnapshot::enabled($instance);
            $ok = self::locked($instance, static function () use ($instance, $step, $vote, $structured): bool {
                // Под блокировкой — заново: этап мог догнать обычный обработчик или человек.
                if (self::catchUpPlan($step) === null) { return false; }
                if ($structured) {
                    return StructuredAccess::context($step) !== null && self::transferStructuredVote($instance, $step, $vote)
                        && Outcome::forStep((int) $step->getID()) !== null;
                }
                return self::evaluateApproval($step, strip_tags((string) ($vote->fields['comment_validation'] ?? '')))
                    && self::auditAction($instance, $step, 'approval_result', false);
            });
            if ($ok) {
                $instance->getFromDB($instance->getID());
                Violation::record($instance, (string) $instance->fields['itemtype'], (int) $instance->fields['items_id'],
                    'vote_caught_up', false, 'Ответ согласующего учтён догоняющей сверкой.');
            }
            return $ok;
        } catch (\Throwable $e) {
            self::hookFailed($e, $instance, 'vote_failed', self::VOTE_FAILED);
            return false;
        } finally {
            self::$quiet = $quiet;
            self::$proxyActor = $actor;
        }
    }

    /**
     * Догоняющая сверка по всем идущим этапам согласования (автоматическое действие «Сроки этапов»).
     * 1.13.2: все идущие этапы, страницами по $page по возрастанию номера, — а не только первые
     * 200: этапы с большими номерами не ждут, пока впереди висят законно ждущие. Подробная сверка — только
     * у этапов, где в GLPI уже есть хотя бы один ответ на их запросы (answeredSteps, один запрос на тип
     * на страницу): без ответов решения быть не может.
     *
     * @return int сколько этапов догнано
     */
    public static function catchUpApprovals(int $page = 200): int
    {
        $done = 0;
        $after = 0;
        $page = max(1, $page);
        do {
            $rows = (new Step())->find(['state' => Step::RUNNING,
                'execution_mode' => [Stage::MODE_APPROVAL, Stage::MODE_DELEGATED], ['id' => ['>', $after]]], ['id ASC'], $page);
            if ($rows === []) { break; }
            $after = (int) max(array_keys($rows));
            foreach (self::answeredSteps($rows) as $id) {
                $step = new Step();
                if ($step->getFromDB($id) && self::catchUpPlan($step) !== null && self::catchUpApproval($step)) { $done++; }
            }
        } while (count($rows) === $page);
        return $done;
    }

    /**
     * Этапы из $rows (номер => строка этапа), у которых хотя бы на один запрос согласования в GLPI уже
     * ответили. Запросы — по строкам привязок (1.11) и по прежней строке номеров.
     *
     * @param array<int,array> $rows
     * @return list<int>
     */
    public static function answeredSteps(array $rows): array
    {
        if ($rows === []) { return []; }
        $byType = [];
        foreach ((new OutcomeValidation())->find(['plugin_itilflow_steps_id' => array_map('intval', array_keys($rows))]) as $b) {
            $byType[(string) $b['validation_itemtype']][(int) $b['validations_id']][] = (int) $b['plugin_itilflow_steps_id'];
        }
        foreach ($rows as $id => $row) {
            $step = new Step();
            $step->fields = $row + ['id' => (int) $id];
            if ($step->hasLegacyBinding()) {
                foreach ($step->validationIds() as $vid) { $byType[(string) ($row['artifact_itemtype'] ?? '')][$vid][] = (int) $id; }
            }
        }
        $out = [];
        foreach ($byType as $type => $byId) {
            if (!in_array($type, ['TicketValidation', 'ChangeValidation'], true)) { continue; }
            foreach (array_chunk(array_keys($byId), 500) as $chunk) {
                foreach ((new $type())->find(['id' => $chunk,
                    'status' => [CommonITILValidation::ACCEPTED, CommonITILValidation::REFUSED]]) as $vid => $_) {
                    foreach ($byId[(int) $vid] ?? [] as $sid) { $out[$sid] = $sid; }
                }
            }
        }
        ksort($out);
        return array_values($out);
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
                'Маршрут «%s» предназначен для %s. Его нельзя запустить на этом объекте.',
                $process->fields['name'],
                match ($process->fields['itemtype']) {
                    'Ticket' => 'заявок', 'Change' => 'изменений', 'Problem' => 'проблем',
                    default => 'другого типа объектов',
                }
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
            '<p><strong>Маршрут остановлен.</strong></p><p>Причина: %s. '
            . 'Дальше решает администратор маршрутов.</p>',
            htmlescape(rtrim(trim($reason), '.'))
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
                if (!in_array($row['state'], [Step::PENDING, Step::RUNNING, Step::DONE, Step::SKIPPED, Step::NOT_NEEDED], true)) {
                    throw new \RuntimeException('Состояние попытки требует отдельной сверки.');
                }
            }
            self::mustUpdate($instance, ['id' => $instance->getID(), 'state' => Instance::RUNNING]);
            self::advanceResuming($instance);
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

        self::advanceResuming($instance);
    }

    /** advance() команды «Возобновить»: открываемый этап, остановленный сроком, считает срок заново. */
    private static function advanceResuming(Instance $instance): void
    {
        $prev = self::$resuming;
        self::$resuming = true;
        try {
            self::advance($instance);
        } finally {
            self::$resuming = $prev;
        }
    }

    private static function abortInternal(Instance $instance, string $reason = ''): void
    {
        if (DefinitionSnapshot::enabled($instance)) { self::abortStructured($instance, $reason); return; }
        self::enter();
        try {
            foreach ($instance->getSteps() as $row) {
                if (in_array($row['state'], [Step::PENDING, Step::RUNNING], true)) {
                    self::mustUpdate((new Step()), ['id' => (int) $row['id'], 'state' => Step::SKIPPED]);
                    if ($row['state'] === Step::RUNNING) {
                        $open = new Step();
                        $open->fields = $row;
                        self::releaseInlineWork($instance, $open);
                    }
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
            '<p><strong>Маршрут прерван.</strong>%s</p>',
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

        if (!self::conditionBeforeOpen($instance, $step)) { return; }
        // Этап остановлен сроком (причина — в comment шага), администратор исправил этап и нажал
        // «Возобновить» — срок и SLA по текущей настройке этапа, не по снимку запуска.
        if (self::$resuming && trim((string) ($step->fields['comment'] ?? '')) !== '') {
            $live = new Stage();
            $stage = clone $stage;
            $stage->fields = self::resumeDeadline($stage->fields, $live->getFromDB((int) $stage->getID()) ? $live->fields : null,
                (int) ($instance->fields['plugin_itilflow_processes_id'] ?? 0));
        }
        // Проверяем сроки до RUNNING, назначения исполнителя, виз, задач и дочерних заявок.
        // Старые определения тоже проходят эту проверку: дата вычисляется при открытии.
        $due = self::computeDue($instance, $stage);
        $date_error = $due === null && (int) $stage->fields['deadline_minutes'] > 0
            ? 'Не удалось рассчитать срок этапа. Проверьте календарь маршрута.'
            : (!Deadline::storable($due)
                ? 'Срок слишком большой: этап закончится позже 18.01.2038 — такую дату база не хранит. Укажите меньше.'
                : self::delegatedDeadlineError($stage, $item));
        if ($date_error !== null) {
            self::mustUpdate($step, ['id' => $step->getID(), 'comment' => $date_error]);
            self::haltInternal($instance, $date_error);
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
                'date_due'   => $due,
                'comment'    => null,
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
            if ($ok === true && CurrentRead::load($step, (int) $step->getID())
                && $step->fields['state'] === Step::RUNNING) {
                if (DefinitionSnapshot::enabled($instance)) {
                    // Статус ставит задача itilflow_status_sync; людям об этом не сообщаем —
                    // если задача выключена, предупреждение видит администратор в списке маршрутов.
                    StatusSync::request($step, (int) $stage->fields['ticket_status'], self::sourceActor());
                } else {
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
        self::msg(sprintf('Открыт этап «%s». Исполнитель: %s.', $stage->fields['name'], $who));

        $total = count($instance->getSteps());
        $done = 0;
        foreach ($instance->getSteps() as $r) {
            if (in_array($r['state'], [Step::DONE, Step::SKIPPED], true)) {
                $done++;
            }
        }
        self::announce($instance, sprintf(
            '<p><strong>%s</strong> — в работе.</p><p>Исполнитель: %s.%s</p>%s'
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

        // Календарь может только отодвинуть конец: непомещающийся нижний предел
        // возвращаем сразу, openStep отклонит его до записи и любых эффектов.
        $earliest = date('Y-m-d H:i:s', strtotime($start) + $minutes * MINUTE_TIMESTAMP);
        if (!Deadline::storable($earliest)) { return $earliest; }
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
            return 'Для этого объекта нельзя создать задачу.';
        }
        $fk = $item::getForeignKeyField();
        $task = new $taskclass();
        // Содержимое задачи — HTML: инструкция этапа (из редактора) как есть, название — текстом.
        $content = trim((string) $stage->fields['content']) !== ''
            ? $stage->fields['content']
            : htmlescape((string) $stage->fields['name']);

        $tid = $task->add([
            $fk               => $item->getID(),
            'content'         => sprintf('<p><strong>Этап %d. %s</strong></p>%s',
                (int) $step->fields['ranking'], htmlescape((string) $stage->fields['name']), $content),
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
    /** Календарь дочерней заявки определяется её организацией, как в Ticket::getDatasToAddSLA. */
    private static function delegatedDeadlineError(Stage $stage, CommonITILObject $item): ?string
    {
        if (($stage->fields['execution_mode'] ?? '') !== Stage::MODE_DELEGATED
            || $item->getType() !== 'Ticket' || (int) $stage->fields['slas_id'] <= 0) {
            return null;
        }
        $entity = $stage->resolveEntity($item);
        $calendar = (int) \Entity::getUsedConfig('calendars_strategy', $entity, 'calendars_id', 0);
        return Deadline::slaError((int) $stage->fields['slas_id'], $calendar);
    }

    private static function createDelegated(Instance $i, Step $step, Stage $stage, CommonITILObject $item): bool|string
    {
        if (DefinitionSnapshot::enabled($i)) { return self::createStructuredChild($i, $step, $stage, $item); }
        $date_error = self::delegatedDeadlineError($stage, $item);
        if ($date_error !== null) { throw new \RuntimeException($date_error); }
        $target_entity = $stage->resolveEntity($item);

        // GLPI не проверяет это сама: модель принимает группу чужой сущности молча.
        if (!$stage->groupFitsEntity($target_entity)) {
            $g = new Group();
            $g->getFromDB((int) $stage->fields['groups_id']);
            $e = new \Entity();
            $e->getFromDB($target_entity);
            return sprintf(
                'Группа «%s» не видна в организации «%s». Исправьте этап.',
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
                : sprintf('Этап «%s» по заявке №%d.', (string) $stage->fields['name'], $item->getID()),
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
            return sprintf('Не удалось создать дочернюю заявку в организации «%s».',
                \Dropdown::getDropdownName('glpi_entities', $target_entity));
        }
        if (CurrentRead::load($child, (int) $cid)) { self::childWithoutInitiator($child, $item); }

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

    /**
     * Пользователь — инициатор заявки маршрута (участник «Инициатор»). Читается из базы, а не из кэша
     * участников объекта: внутри команды — текущим чтением.
     */
    private static function isRequester(CommonITILObject $item, int $uid): bool
    {
        $link = $uid > 0 ? $item->getActorObjectForItem(\User::class) : null;
        return is_object($link) && CurrentRead::find($link, [$link::getItilObjectForeignKey() => (int) $item->getID(),
            'users_id' => $uid, 'type' => \CommonITILActor::REQUESTER]) !== [];
    }

    /** Маршрут двигает действие самого инициатора заявки: его ответ на уточнение, отправка формы. */
    private static function initiatorActs(?CommonITILObject $item): bool
    {
        $uid = (int) Session::getLoginUserID();
        return $item !== null && $uid > 0 && self::isRequester($item, $uid);
    }

    /**
     * 1.13.2. Автор («Автор» GLPI, users_id_recipient) дочерней заявки маршрута — не инициатор
     * основной заявки. GLPI пишет автором вошедшего, а этап иногда открывает действие самого инициатора:
     * его ответ на уточнение, отправка формы. Тогда автора нет — как у дочерней заявки, открытой ответом
     * письмом: иначе по праву «Видеть мои заявки» инициатор открыл бы внутреннюю заявку отдела и получал
     * бы её уведомления автору. Только поле автора, без хуков и правил: заявка создана в этой же команде.
     */
    private static function childWithoutInitiator(CommonITILObject $child, CommonITILObject $parent): void
    {
        global $DB;
        $author = (int) ($child->fields['users_id_recipient'] ?? 0);
        if ($author <= 0 || !self::isRequester($parent, $author)) { return; }
        $DB->update($child::getTable(), ['users_id_recipient' => 0], ['id' => (int) $child->getID()]);
        if (!CurrentRead::load($child, (int) $child->getID()) || (int) $child->fields['users_id_recipient'] !== 0) {
            throw new \RuntimeException('Автор дочерней заявки не записан.');
        }
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
            return 'Для этого объекта согласование не поддерживается.';
        }
        $fk = $item::getForeignKeyField();

        $source = (string) ($stage->fields['approver_source'] ?? Stage::APPROVER_FIXED);

        // Повтор этапа после возврата на уточнение: запросы — тем же согласующим, что в прошлый раз.
        // Без этого «Согласование из формы» и «Указывает исполнитель» ждали бы администратора.
        if (in_array($source, ['form', Stage::APPROVER_RUNTIME], true) && (int) ($step->fields['pass_number'] ?? 1) > 1) {
            $plan = self::repeatPlan($step);
            if ($plan['targets'] !== []) {
                self::repeatApprovers($step, $stage, $item, $plan);
                return true;
            }
        }

        if ($source === 'form') {
            // Callback формы приходит позже ITEM_ADD; отсутствие квитанции означает ожидание.
            FormAdoption::tryForStep($step);
            return true;
        }
        // Согласующего указывает исполнитель уже по факту обращения — например
        // когда владелец ресурса известен только по внешнему реестру. Запрос
        // здесь не создаём: этап открывается и ждёт указания.
        if ($source === Stage::APPROVER_RUNTIME) {
            self::announce($i, sprintf(
                '<p><strong>%s</strong> — ждёт выбора согласующего.</p>'
                . '<p>Его выберет исполнитель заявки.</p>',
                htmlescape(self::stageLabel($step))
            ));
            return true;
        }

        // Согласующие «Указан в этапе» — списки (1.11). Не заполненные списки — прежние
        // «группа / сотрудник»: запрос получает группа, а если её нет — сотрудник, как до 1.11.
        if ((DefinitionSnapshot::load($i)['schema'] ?? 1) >= 2) {
            $roster = ApprovalRoster::previewForStage($stage->fields, $item);
            if (!$roster['ok']) {
                self::mustUpdate($step, ['id' => $step->getID(), 'approver_fallback' => DefinitionSnapshot::encode([
                    'waiting' => true, 'errors' => $roster['errors'], 'excluded' => $roster['excluded']])]);
                self::announce($i, '<p>Согласующий недоступен — замену выберет администратор маршрутов.</p>');
                return true;
            }
            self::mustUpdate($step, ['id' => $step->getID(), 'approver_fallback' => DefinitionSnapshot::encode([
                'waiting' => false, 'targets' => $roster['targets'], 'excluded' => $roster['excluded'],
                'warnings' => $roster['warnings'], 'group_mode' => $roster['group_mode']])]);
            $created = [];
            foreach ($roster['targets'] as $target) {
                $res = self::openValidation($step, $stage, $item, $target['itemtype'], $target['id']);
                if (is_string($res)) { throw new \RuntimeException($res); }
                $created[] = $res;
            }
            self::bindValidations($step, get_class($item::getValidationClassInstance()), $created);
            return true;
        }
        $lists = DefinitionSnapshot::approvers($stage->fields);
        if ($lists === null) {
            return 'Список согласующих этапа повреждён.';
        }
        $targets = [];
        if ($lists['legacy']) {
            if ((int) $stage->fields['groups_id'] > 0) {
                $targets[] = [Group::class, (int) $stage->fields['groups_id']];
            } elseif ((int) $stage->fields['users_id'] > 0) {
                $targets[] = [\User::class, (int) $stage->fields['users_id']];
            }
        } else {
            foreach ($lists['users'] as $uid) {
                $targets[] = [\User::class, $uid];
            }
            foreach ($lists['groups'] as $gid) {
                $targets[] = [Group::class, $gid];
            }
        }
        if ($targets === []) {
            return 'У этапа не указан согласующий.';
        }

        $created = [];
        foreach ($targets as [$target_type, $target_id]) {
            $res = self::openValidation($step, $stage, $item, $target_type, $target_id);
            if (is_string($res)) {
                return $res;
            }
            $created[] = $res;
        }
        self::bindValidations($step, get_class($item::getValidationClassInstance()), $created);
        return true;
    }

    /**
     * Все адресаты прохода этапа согласования: и те, чьи запросы сняты при возврате на уточнение
     * (approval_dropped), и те, кто к этому времени уже ответил (запросы прохода остаются в заявке).
     * 1.13.2: повтор спрашивает всех, как у «Задан в этапе», — прежнее «нужно одобрений»
     * считается от того же состава, а не только от тех, кто не успел ответить. Без повторов, только
     * пользователи и группы.
     *
     * @return list<array{0:string,1:int}>
     */
    public static function passTargets(Step $pass): array
    {
        $out = [];
        $add = static function (array $row) use (&$out): void {
            $type = (string) ($row['itemtype_target'] ?? ''); $id = (int) ($row['items_id_target'] ?? 0);
            if (in_array($type, [\User::class, Group::class], true) && $id > 0) { $out[$type . '#' . $id] = [$type, $id]; }
        };
        if (!$pass->isNewItem()) {
            foreach (OutcomeValidation::forStep($pass) as $row) { $add($row); }
            $type = (string) ($pass->fields['artifact_itemtype'] ?? '');
            if ($pass->hasLegacyBinding() && is_a($type, CommonITILValidation::class, true) && ($ids = $pass->validationIds()) !== []) {
                foreach ((new $type())->find(['id' => $ids]) as $row) { $add($row); }
            }
        }
        foreach (ApprovalRoster::dropped($pass->fields['approval_dropped'] ?? null) as $row) { $add($row); }
        return array_values($out);
    }

    /**
     * Повтор этапа «Согласование из формы» / «Указывает исполнитель заявки», который сам вернул заявку
     * на уточнение: запросы — всем адресатам того прохода (passTargets), с его «нужно одобрений».
     * Пусто — попытку начал не возврат с этого же этапа (другой этап, «Начать заново»), у прохода
     * не было запросов или кто-то из адресатов больше не подходит заявке: этап ждёт, как раньше
     * (указать согласующего или засчитать вручную).
     *
     * @return array{targets:list<array{0:string,1:int}>,percent:int}
     */
    public static function repeatPlan(Step $step): array
    {
        $none = ['targets' => [], 'percent' => 0];
        $instance = $step->getInstance();
        $item = $instance?->getItilItem();
        $attempt = new Attempt();
        if ($instance === null || $item === null || !$attempt->getFromDB((int) ($step->fields['attempt_id'] ?? 0))) { return $none; }
        $previous = new Attempt();
        $source = new Step();
        if (!$previous->getFromDB((int) ($attempt->fields['previous_attempt_id'] ?? 0)) || $previous->fields['state'] !== Attempt::RETURNED
            || !$source->getFromDB((int) ($attempt->fields['source_step_id'] ?? 0)) || (int) $source->getID() === (int) $step->getID()
            || (int) $source->fields['plugin_itilflow_instances_id'] !== (int) $instance->getID()
            || (int) $source->fields['plugin_itilflow_stages_id'] !== (int) $step->fields['plugin_itilflow_stages_id']) {
            return $none;
        }
        return self::planFrom($source, $item);
    }

    /**
     * Запросы повтора по проходу, который вернул на уточнение: все его адресаты — снятые и ответившие (все должны
     * по-прежнему подходить заявке) и его «нужно одобрений».
     *
     * @return array{targets:list<array{0:string,1:int}>,percent:int}
     */
    public static function planFrom(Step $source, CommonITILObject $item): array
    {
        $none = ['targets' => [], 'percent' => 0];
        $targets = self::passTargets($source);
        foreach ($targets as [$type, $id]) {
            if (($type === \User::class && !Authorizer::userFitsItem($id, $item)) || ($type === Group::class && !self::groupFitsItem($id, $item))) {
                return $none;
            }
        }
        $percent = (int) ($source->fields['approval_percent_snapshot'] ?? 0);
        return $targets === [] || $percent < 1 || $percent > 100 ? $none : ['targets' => $targets, 'percent' => $percent];
    }

    /** Запросы повтора этапа — тем же согласующим (repeatPlan), с той же долей одобрений. */
    private static function repeatApprovers(Step $step, Stage $stage, CommonITILObject $item, array $plan): void
    {
        self::mustUpdate($step, ['id' => $step->getID(), 'approval_percent_snapshot' => $plan['percent']]);
        $created = [];
        foreach ($plan['targets'] as [$type, $id]) {
            $res = self::openValidation($step, $stage, $item, $type, $id);
            if (is_string($res)) { throw new \RuntimeException($res); }
            $created[] = $res;
        }
        self::bindValidations($step, get_class($item::getValidationClassInstance()), $created);
        $users = array_values(array_map(static fn(array $t): int => $t[1], array_filter($plan['targets'], static fn(array $t): bool => $t[0] === \User::class)));
        $groups = array_values(array_map(static fn(array $t): int => $t[1], array_filter($plan['targets'], static fn(array $t): bool => $t[0] === Group::class)));
        self::mustUpdate($step, ['id' => $step->getID(),
            'groups_id' => count($groups) === 1 && $users === [] ? $groups[0] : 0, 'users_id' => count($users) === 1 ? $users[0] : 0,
            'approver_reason' => 'Те же согласующие, что до уточнения.']);
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
            return 'Не заданы согласующие или «Нужно одобрений».';
        }
        $val = $item::getValidationClassInstance();
        if ($val === null) {
            return 'Для этого объекта согласование не поддерживается.';
        }
        $fk = $item::getForeignKeyField();

        $input = [
            $fk                  => $item->getID(),
            'entities_id'        => (int) $item->fields['entities_id'],
            'itemtype_target'    => $target_type,
            'items_id_target'    => $target_id,
            // Комментарий запроса GLPI выводит как HTML: название — текстом; инструкция
            // без тегов уже безопасна (символы в ней хранятся сущностями редактора).
            // Абзац — чтобы GLPI принял текст за HTML и не раскодировал сущности.
            'comment_submission' => sprintf(
                '<p>Согласование: этап «%s».%s</p>',
                htmlescape((string) $stage->fields['name']),
                trim((string) $stage->fields['content']) !== ''
                    ? ' ' . strip_tags((string) $stage->fields['content']) : ''
            ),
        ];
        $vs = self::ladderFor($step, $percent);
        if ($vs > 0) {
            $input['_validationsteps_id'] = $vs;
        }
        // 1.13.2: запрос, который маршрут создаёт от действия самого инициатора (ответ на уточнение,
        // отправка формы), — без «Инициатора запроса на согласование», как запрос, созданный по письму. Иначе
        // GLPI записывал инициатора: ему шли уведомления об ответе и предлагалось удалить запрос.
        if (self::initiatorActs($step->getInstance()?->getItilItem())) {
            $input['_auto_update'] = true;
        }

        $vid = (int) $val->add($input);
        if (!$vid) {
            return 'Не удалось создать запрос на согласование.';
        }
        return $vid;
    }

    /**
     * Привязать к шагу созданные объекты согласования.
     *
     * Маршрут нового порядка (1.11) — строками привязок «создан маршрутом», по одной на запрос;
     * у шага — первый номер и сколько запросов в составе. Прежний порядок — строкой номеров,
     * как до 1.11.
     */
    private static function bindValidations(Step $step, string $itemtype, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if (!count($ids)) {
            return;
        }
        sort($ids);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \RuntimeException('Не удалось сохранить полный состав согласования.');
        }
        $instance = $step->getInstance();
        if ($instance !== null && DefinitionSnapshot::enabled($instance)) {
            $item = $instance->getItilItem();
            foreach ($ids as $id) {
                $v = new $itemtype();
                if ($item === null || !$v instanceof CommonITILValidation || !CurrentRead::load($v, $id)) {
                    throw new \RuntimeException('Не удалось сохранить полный состав согласования.');
                }
                OutcomeValidation::bind($step, $v, $item, OutcomeValidation::KIND_CREATED);
            }
            if (!$step->update([
                'id'                      => $step->getID(),
                'approval_expected_count' => count($ids),
                'artifact_itemtype'       => $itemtype,
                'artifact_items_id'       => $ids[0],
            ]) || !$step->getFromDB($step->getID()) || $step->validationRoster() === null) {
                throw new \RuntimeException('Не удалось закрепить состав согласования.');
            }
            return;
        }
        $csv = implode(',', $ids);
        if (strlen($csv) > 255) {
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
        if ($step->hasExternalBindings()) {
            $native = StructuredAccess::nativeAggregate($step);
            if ($native === 'unconfirmed') { return false; }
            if ($native === Outcome::COMPLETED) { return self::completeStepInternal($step, $comment); }
            if ($native === Outcome::REFUSED) { self::rejectStepInternal($step, $comment); }
            return true;
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
                'Согласовано: одобрили %d из %d (нужно %d %%). %s', $accepted, $total, $need, $comment
            ));
        }
        if ($possible_pct < $need) {
            self::rejectStepInternal($step, sprintf(
                'Отклонено: отказали %d из %d. %s',
                $refused, $total, $comment
            ));
            return true;
        }
        // Порог ещё достижим — ждём остальных.
        $left = $total - $accepted - $refused;
        self::announce($step->getInstance(), sprintf(
            '<p><strong>%s</strong> — пришёл ответ согласующего.</p>'
            . '<p>Одобрили %d из %d (нужно %d %%). Ждём ещё %d.</p>',
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
        string $reason,
        string $group_mode = 'any',
        ?int $fallback_percent = null
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

        $fallback = json_decode((string) ($step->fields['approver_fallback'] ?? ''), true);
        if (($fallback['waiting'] ?? false) === true && !Authorizer::isAdministrator()) { return false; }

        foreach (array_merge($groups_ids, $users_ids) as $id) {
            if (!is_int($id) || $id <= 0) {
                return false;
            }
        }
        $groups_ids = array_values(array_unique($groups_ids));
        $users_ids = array_values(array_unique($users_ids));
        if (!count($groups_ids) && !count($users_ids)) {
            self::msg('Укажите хотя бы одного согласующего: пользователя или группу.', ERROR);
            return false;
        }
        if (trim($reason) === '') {
            self::msg(
                'Укажите причину выбора.',
                ERROR
            );
            return false;
        }

        if (($stage->fields['approver_source'] ?? '') === 'form') {
            if ($fallback_percent === null || $fallback_percent < 1 || $fallback_percent > 100) { return false; }
            self::mustUpdate($step, ['id' => $step->getID(), 'approval_percent_snapshot' => $fallback_percent]);
        }
        $modern = DefinitionSnapshot::schemaOf($instance) >= 2;
        if ($modern) {
            $roster = ApprovalRoster::resolve(['users' => $users_ids, 'groups' => $groups_ids,
                'mode' => $group_mode, 'legacy' => false], $item);
            if (!$roster['ok']) { self::msg(implode(' ', $roster['errors']), ERROR); return false; }
            $targets = array_map(static fn(array $t): array => [$t['itemtype'], $t['id']], $roster['targets']);
        } else {
            // Идущие старые запуски сохраняют прежний выбор и режим группы.
            $targets = [];
            foreach ($users_ids as $uid) {
                if (!Authorizer::userFitsItem($uid, $item)) { return false; }
                $targets[] = [\User::class, $uid];
            }
            foreach ($groups_ids as $gid) {
                if (!self::groupFitsItem($gid, $item)) { return false; }
                $targets[] = [Group::class, $gid];
            }
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
            if ($modern) {
                self::mustUpdate($step, ['id' => $step->getID(), 'approver_fallback' => DefinitionSnapshot::encode([
                    'waiting' => false, 'targets' => $roster['targets'], 'excluded' => $roster['excluded'],
                    'warnings' => $roster['warnings'], 'group_mode' => $roster['group_mode']])]);
            }
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
            '<p><strong>%s</strong> — выбраны согласующие: %s.</p>'
            . '<p>Нужно одобрений: %d %%.</p><p>Выбрал: %s. Причина: %s</p>',
            htmlescape(self::stageLabel($step)),
            htmlescape($who),
            $need,
            htmlescape($by),
            htmlescape(trim($reason))
        ));
        return true;
    }

    /**
     * Ступень согласования GLPI для запроса этапа: у запуска нового порядка со снимком схемы 2 —
     * своя на проход этапа (ValidationLadder), у начатых раньше и у прежнего порядка — общая
     * по порогу, как до 1.11.
     */
    private static function ladderFor(Step $step, int $percent): int
    {
        $instance = $step->getInstance();
        $snapshot = $instance !== null && DefinitionSnapshot::enabled($instance) ? DefinitionSnapshot::load($instance) : null;
        if ($snapshot !== null && (int) $snapshot['schema'] >= 2) {
            return ValidationLadder::ensure((int) $step->fields['ranking'], max(1, (int) $step->fields['pass_number']),
                (int) $snapshot['run'], $percent);
        }
        return self::ensureValidationStep($percent);
    }

    /** Общая ступень согласования с нужным порогом (до 1.11); создаётся один раз и переиспользуется. */
    private static function ensureValidationStep(int $percent): int
    {
        if ($percent < 1 || $percent > 100) {
            throw new \RuntimeException('Недопустимый порог согласования.');
        }
        $name = sprintf(ValidationLadder::SHARED, $percent);
        // Историческое имя — только ключ поиска, существующие записи не переименовываем.
        $legacy_name = sprintf(self::SHARED_LEGACY, $percent);
        $vs = new ValidationStep();
        $found = $vs->find(['name' => [$name, $legacy_name], 'minimal_required_validation_percent' => $percent], [], 2);
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
            return 'Заполните «Что сделано».';
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
        if (!Decision::ready($step) && !$override) { return false; }
        if (DefinitionSnapshot::enabled($instance) && $step->fields['execution_mode'] !== Stage::MODE_INLINE) {
            if (!$override || StructuredAccess::context($step) === null) { return false; }
            // Согласующего ещё не указали — запросов нет, считать нечего: закрыть можно и так.
            if (!$step->awaitsApprover() && StructuredAccess::nativeAggregate($step) !== null) {
                // Уже сохранённый окончательный штатный ответ сначала переносится, не заменяется override.
                return false;
            }
            // applyStructuredOutcome снимает запросы один раз, после записи результата.
            // Повторное снятие до него ошибочно считало собственное удаление внешним.
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
            // Принудительно закрытое согласование: неотвеченные запросы не остаются у согласующих.
            if ($override) {
                self::dropWaitingValidations($step);
            }
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

    /**
     * Прерванный этап «Задача»: задача этапа закрывается, группа, добавленная на время этапа,
     * снимается с заявки — так же, как при завершении этапа. Своё изменение хуки не проверяют.
     */
    private static function releaseInlineWork(Instance $instance, Step $step): void
    {
        if (($step->fields['execution_mode'] ?? '') !== Stage::MODE_INLINE) { return; }
        self::enter();
        try {
            self::closeArtifact($step, self::TASK_ABORTED);
            if ((int) ($step->fields['group_attached'] ?? 0) === 1 && ($item = $instance->getItilItem()) !== null) {
                self::detachGroup($item, (int) $step->fields['groups_id']);
            }
        } finally {
            self::leave();
        }
    }

    /**
     * 1.13.2: пометка в тексте задачи прерванного этапа. Отдельного состояния «Отменено» у задачи
     * GLPI нет, поэтому задача по-прежнему «Выполнено», а из текста видно, что работу не делали.
     */
    public const TASK_ABORTED = 'Этап прерван вместе с маршрутом — задача закрыта без выполнения.';

    /** Закрыть задачу этапа; $note — пометка в конце текста задачи (прерванный этап). */
    private static function closeArtifact(Step $step, string $note = ''): void
    {
        $type = (string) $step->fields['artifact_itemtype'];
        $id = (int) $step->fields['artifact_items_id'];
        if ($type === '' || $id <= 0 || !class_exists($type)) {
            return;
        }
        if (is_a($type, \CommonITILTask::class, true)) {
            $t = new $type();
            if ($t->getFromDB($id) && (int) $t->fields['state'] !== Planning::DONE) {
                $input = ['id' => $id, 'state' => Planning::DONE];
                if ($note !== '') {
                    $input['content'] = (string) $t->fields['content'] . '<p><em>' . htmlescape($note) . '</em></p>';
                }
                self::mustUpdate($t, $input);
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
            '<p><strong>%s</strong> — отклонено.</p>%s',
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
            self::msg('Передать можно только текущий этап.', ERROR);
            return false;
        }
        if ($groups_id <= 0 && $users_id <= 0) {
            self::msg('Укажите, кому передать: группу или пользователя.', ERROR);
            return false;
        }
        if (trim($reason) === '') {
            self::msg('Укажите причину передачи.', ERROR);
            return false;
        }
        if ($users_id > 0 && !Authorizer::userFitsItem($users_id, $item)) {
            return false;
        }
        if ($groups_id > 0 && !self::groupFitsItem($groups_id, $item)) {
            self::msg('Группа не видит эту заявку: она из другой организации.', ERROR);
            return false;
        }

        $old_g = (int) $step->fields['groups_id'];
        $old_u = (int) $step->fields['users_id'];
        // Исполнителем самой заявки группу ставит только этап-задача (createInlineTask), и снимается
        // лишь то, что поставил маршрут (group_attached), — группу, назначенную человеком, не трогаем.
        // У этапа с заявкой отдела исполнители — в заявке отдела: основная заявка не меняется.
        $attached = (int) $step->fields['group_attached'] === 1;

        self::enter();
        try {
            if ($step->fields['execution_mode'] === Stage::MODE_INLINE && $groups_id !== $old_g) {
                if ($old_g > 0 && $attached) {
                    self::detachGroup($item, $old_g);
                }
                $attached = $groups_id > 0 && self::attachGroup($item, $groups_id);
            }

            self::mustUpdate($step, [
                'id'              => $step->getID(),
                'groups_id'       => $groups_id,
                'users_id'        => $users_id,
                'groups_id_from'  => $step->fields['groups_id_from'] ?: $old_g,
                'users_id_from'   => $step->fields['users_id_from'] ?: $old_u,
                'reassign_reason' => trim($reason),
                'group_attached'  => $attached ? 1 : 0,
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
            self::msg('В этом маршруте отзыв заявки выключен.', ERROR);
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
            self::abortStructured($instance, 'Заявку отозвал инициатор. Причина: ' . trim(strip_tags($reason)));
            self::solveStructured($instance->getItilItem(), 'Заявку отозвал инициатор. Причина: ' . trim(strip_tags($reason)));
            return true;
        }

        self::abortInternal($instance, '');
        self::announce($instance, sprintf(
            '<p><strong>Заявку отозвал инициатор.</strong></p><p>Причина: %s</p>',
            htmlescape(trim($reason))
        ));
        self::msg('Заявка отозвана, маршрут прерван.');
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
                    '<p><strong>Просрочен этап %s.</strong></p><p>Срок истёк %s. Исполнитель: %s.</p>',
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
            sprintf('Просрочен этап %s. Срок: %s. Исполнитель: %s.',
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
            self::msg('Маршрут: ' . $message, WARNING);
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
