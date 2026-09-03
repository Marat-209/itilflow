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
    /** Защита от рекурсии: объекты, которые создаёт сам движок, не должны его же и триггерить. */
    private static bool $inside = false;

    public static function isInside(): bool
    {
        return self::$inside;
    }

    private static function enter(): void
    {
        self::$inside = true;
    }

    private static function leave(): void
    {
        self::$inside = false;
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
        self::$inside = true;
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

    // ================================================================= запуск

    /** Запустить маршрут на заявке. Возвращает экземпляр или null. */
    public static function start(CommonITILObject $item, int $processes_id): ?Instance
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
            ]);
            if (!$iid) {
                return null;
            }
            $instance->getFromDB($iid);

            // Снимок определения: правка маршрута не переписывает историю.
            foreach ($stages as $s) {
                (new Step())->add([
                    'plugin_itilflow_instances_id' => $iid,
                    'plugin_itilflow_stages_id'    => (int) $s['id'],
                    'stage_name'                   => (string) $s['name'],
                    'ranking'                      => (int) $s['ranking'],
                    'execution_mode'               => (string) $s['execution_mode'],
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
    public static function advance(Instance $instance): void
    {
        if ($instance->fields['state'] !== Instance::RUNNING) {
            return;
        }
        if ($instance->getCurrentStep() !== null) {
            return; // текущий шаг ещё не закрыт
        }

        $next = null;
        foreach ($instance->getSteps() as $row) {
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
        self::enter();
        try {
            $instance->update([
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

    public static function halt(Instance $instance, string $reason): void
    {
        self::enter();
        try {
            $instance->update([
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

    public static function resume(Instance $instance): void
    {
        if ($instance->fields['state'] !== Instance::HALTED) {
            return;
        }
        self::enter();
        try {
            $instance->update(['id' => $instance->getID(), 'state' => Instance::RUNNING]);
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
                self::reopenFrom($instance, (int) $rejected['plugin_itilflow_stages_id']);
                return;
            }
        }

        self::advance($instance);
    }

    public static function abort(Instance $instance, string $reason = ''): void
    {
        self::enter();
        try {
            foreach ($instance->getSteps() as $row) {
                if (in_array($row['state'], [Step::PENDING, Step::RUNNING], true)) {
                    (new Step())->update(['id' => (int) $row['id'], 'state' => Step::SKIPPED]);
                }
            }
            $instance->update([
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
            self::halt($instance, 'Не найден этап или заявка.');
            return;
        }

        $ok = 'Не удалось открыть этап.';
        self::enter();
        try {
            $step->update([
                'id'         => $step->getID(),
                'state'      => Step::RUNNING,
                'date_start' => self::now(),
                'date_due'   => self::computeDue($instance, $stage),
                'is_escalated' => 0,
            ]);
            $instance->update([
                'id'                                => $instance->getID(),
                'plugin_itilflow_stages_id_current' => $stage->getID(),
            ]);

            $ok = match ($stage->fields['execution_mode']) {
                Stage::MODE_DELEGATED => self::createDelegated($instance, $step, $stage, $item),
                Stage::MODE_APPROVAL  => self::createApproval($instance, $step, $stage, $item),
                default               => self::createInlineTask($instance, $step, $stage, $item),
            };
        } finally {
            self::leave();
        }

        $instance->getFromDB($instance->getID());

        if ($ok !== true) {
            self::halt($instance, (string) $ok);
            return;
        }

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
            '<p><strong>%s</strong> — в работе.</p><p>Ответственный: %s.%s</p>'
            . '<p><em>Пройдено этапов: %d из %d.</em></p>',
            htmlescape(self::stageLabel($step)),
            htmlescape($who),
            trim(strip_tags((string) $stage->fields['content'])) !== ''
                ? ' ' . htmlescape(trim(strip_tags((string) $stage->fields['content'])))
                : '',
            $done,
            $total
        ));
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
        $step->update([
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

        $step->update([
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

        $input = [
            $fk                  => $item->getID(),
            'entities_id'        => (int) $item->fields['entities_id'],
            'itemtype_target'    => $target_type,
            'items_id_target'    => $target_id,
            'comment_submission' => sprintf(
                'Согласование этапа %d «%s».%s',
                (int) $step->fields['ranking'],
                $stage->fields['name'],
                trim((string) $stage->fields['content']) !== '' ? ' ' . strip_tags((string) $stage->fields['content']) : ''
            ),
        ];
        $vs = self::ensureValidationStep((int) $stage->fields['approval_percent']);
        if ($vs > 0) {
            $input['_validationsteps_id'] = $vs;
        }

        return self::openValidation($step, $stage, $item, $target_type, $target_id);
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
    ): bool|string {
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
        $vs = self::ensureValidationStep((int) $stage->fields['approval_percent']);
        if ($vs > 0) {
            $input['_validationsteps_id'] = $vs;
        }

        $vid = $val->add($input);
        if (!$vid) {
            return 'Не удалось создать запрос на согласование.';
        }
        $step->update([
            'id'                => $step->getID(),
            'artifact_itemtype' => get_class($val),
            'artifact_items_id' => (int) $vid,
        ]);
        return true;
    }

    /**
     * Указать согласующего на этапе, который его ждёт.
     *
     * Нужно там, где согласующий известен только по факту обращения: например
     * владелец сетевого ресурса числится во внешнем реестре, и диспетчер
     * находит его сам. Основание выбора обязательно и остаётся в истории.
     */
    public static function designateApprover(
        Step $step,
        int $groups_id,
        int $users_id,
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
        if ($groups_id <= 0 && $users_id <= 0) {
            self::msg('Укажите согласующего: группу или сотрудника.', ERROR);
            return false;
        }
        if ($groups_id > 0 && $users_id > 0) {
            self::msg('Укажите либо группу, либо сотрудника, но не обоих.', ERROR);
            return false;
        }
        if (trim($reason) === '') {
            self::msg(
                'Укажите основание выбора согласующего — оно попадёт в лист согласования.',
                ERROR
            );
            return false;
        }

        $target_type = $groups_id > 0 ? Group::class : \User::class;
        $target_id = $groups_id > 0 ? $groups_id : $users_id;

        self::enter();
        try {
            $res = self::openValidation($step, $stage, $item, $target_type, $target_id);
            if (is_string($res)) {
                self::msg($res, ERROR);
                return false;
            }
            $step->update([
                'id'              => $step->getID(),
                'groups_id'       => $groups_id,
                'users_id'        => $users_id,
                'approver_set_by' => (int) (Session::getLoginUserID() ?: 0),
                'approver_reason' => trim($reason),
            ]);
        } finally {
            self::leave();
        }

        $step->getFromDB($step->getID());
        $who = self::nameOf($groups_id, $users_id);
        $by = Session::getLoginUserID()
            ? getUserName((int) Session::getLoginUserID()) : 'система';
        self::msg(sprintf('Согласующий указан: %s.', $who));
        self::announce($instance, sprintf(
            '<p><strong>%s</strong> — согласующий указан.</p><p>Согласует: %s.</p>'
            . '<p>Указал: %s. Основание: %s</p>',
            htmlescape(self::stageLabel($step)),
            htmlescape($who),
            htmlescape($by),
            htmlescape(trim($reason))
        ));
        return true;
    }

    /** Ступень согласования с нужным порогом; создаётся один раз и переиспользуется. */
    private static function ensureValidationStep(int $percent): int
    {
        $percent = max(0, min(100, $percent));
        $name = sprintf('Этап маршрута — порог %d%%', $percent);
        $vs = new ValidationStep();
        $found = $vs->find(['name' => $name], [], 1);
        if (count($found)) {
            return (int) array_key_first($found);
        }
        $id = $vs->add([
            'name' => $name,
            'minimal_required_validation_percent' => $percent,
            'is_default' => 0,
        ]);
        return (int) ($id ?: 0);
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
    public static function completeStep(Step $step, string $comment = '', bool $skipped = false): bool
    {
        $instance = $step->getInstance();
        if ($instance === null || $step->fields['state'] !== Step::RUNNING) {
            return false;
        }
        $start = strtotime((string) ($step->fields['date_start'] ?? self::now()));
        $dur = max(0, time() - ($start ?: time()));

        self::enter();
        try {
            $step->update([
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
                $t->update(['id' => $id, 'state' => Planning::DONE]);
            }
        }
    }

    /** Согласование отклонено. */
    public static function rejectStep(Step $step, string $comment = ''): void
    {
        $instance = $step->getInstance();
        $stage = $step->getStage();
        if ($instance === null || $stage === null) {
            return;
        }

        self::enter();
        try {
            $step->update([
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
                self::abort($instance, 'этап «' . $stage->fields['name'] . '» отклонён');
                break;

            case Stage::REJECT_BACK:
                $back = (int) $stage->fields['plugin_itilflow_stages_id_reject'];
                self::reopenFrom($instance, $back);
                break;

            case Stage::REJECT_BLOCK:
            default:
                self::halt($instance, 'этап «' . $stage->fields['name'] . '» отклонён согласующим');
                break;
        }
    }

    /** Вернуть маршрут на указанный этап: создаём новый проход. */
    public static function reopenFrom(Instance $instance, int $stages_id): void
    {
        $target = null;
        foreach ($instance->getSteps() as $row) {
            if ((int) $row['plugin_itilflow_stages_id'] === $stages_id) {
                $target = $row;
            }
        }
        if ($target === null) {
            self::halt($instance, 'этап для возврата не найден');
            return;
        }

        $pass = 0;
        foreach ($instance->getSteps() as $row) {
            $pass = max($pass, (int) $row['pass_number']);
        }
        if ($pass >= 10) {
            self::halt($instance, 'превышено число возвратов на доработку');
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
                (new Step())->add([
                    'plugin_itilflow_instances_id' => $instance->getID(),
                    'plugin_itilflow_stages_id'    => (int) $s['id'],
                    'stage_name'                   => (string) $s['name'],
                    'ranking'                      => (int) $s['ranking'],
                    'execution_mode'               => (string) $s['execution_mode'],
                    'state'                        => Step::PENDING,
                    'pass_number'                  => $pass + 1,
                    'users_id'                     => (int) $s['users_id'],
                    'groups_id'                    => (int) $s['groups_id'],
                ]);
            }
            // Остальные шаги текущего прохода помечаем пропущенными.
            foreach ($instance->getSteps() as $row) {
                if ((int) $row['pass_number'] <= $pass && $row['state'] === Step::PENDING) {
                    (new Step())->update(['id' => (int) $row['id'], 'state' => Step::SKIPPED]);
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
    public static function reassign(Step $step, int $groups_id, int $users_id, string $reason): bool
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
        if ($groups_id > 0 && !self::groupFitsItem($groups_id, $item)) {
            self::msg('Выбранная группа не видит эту заявку: она из другой организационной единицы.', ERROR);
            return false;
        }

        $old_g = (int) $step->fields['groups_id'];
        $old_u = (int) $step->fields['users_id'];

        self::enter();
        try {
            $step->update([
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
                    $t->update(['id' => $aid, 'groups_id_tech' => $groups_id, 'users_id_tech' => $users_id]);
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
        if (!$g->getFromDB($groups_id)) {
            return false;
        }
        $ge = (int) $g->fields['entities_id'];
        $ie = (int) $item->fields['entities_id'];
        if ($ge === $ie) {
            return true;
        }
        return (bool) $g->fields['is_recursive']
            && in_array($ge, getAncestorsOf('glpi_entities', $ie), true);
    }

    // ================================================================== отзыв

    /** Инициатор отзывает заявку: маршрут прекращается, заявку можно закрыть. */
    public static function withdraw(Instance $instance, string $reason): bool
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

        $who = Session::getLoginUserID() ? getUserName((int) Session::getLoginUserID()) : 'инициатор';
        self::abort($instance, '');
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
        if ($instance === null || $item === null) {
            return false;
        }
        self::enter();
        try {
            $step->update(['id' => $step->getID(), 'is_escalated' => 1]);
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
        return Session::haveRight('plugin_itilflow_bypass', READ);
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
