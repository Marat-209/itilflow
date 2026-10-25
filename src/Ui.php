<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use Glpi\Application\View\TemplateRenderer;
use Group;
use Session;

/**
 * Вкладка маршрута: одна таблица этапов. Результат и действие — в строке своего этапа;
 * человек видит только то, что может сделать сам. Логика переходов — в Engine.
 *
 * Кто что видит: инициатор заявки не видит строк «Не нужен по условию»,
 * вопросов этапа и условий; остальные видят «если …» у будущих условных этапов и причину
 * у «Не нужен по условию». Схема — только у администратора маршрутов.
 */
final class Ui
{
    private const OUTCOME = [
        'done' => [Terms::DONE, 'bg-green-lt'], 'refused' => [Terms::REFUSAL, 'bg-red-lt'],
        'clarification' => [Terms::ON_CLARIFICATION, 'bg-yellow-lt'], 'withdrawn' => ['Отозвано', 'bg-secondary-lt'],
        'aborted' => ['Прервано', 'bg-secondary-lt'], 'skipped' => [Terms::SKIPPED, 'bg-yellow-lt'],
        'running' => [Terms::NOW, 'bg-blue-lt'], 'waiting_input' => [Terms::WAITING_INITIATOR, 'bg-orange-lt'],
        'pending' => [Terms::AHEAD, 'bg-secondary-lt'], 'notrun' => ['Не выполнялся', 'bg-secondary-lt'],
        'unfinished' => [Terms::UNFINISHED, 'bg-secondary-lt'], 'previous' => [Terms::PREVIOUS_ATTEMPT, 'bg-secondary-lt'],
        'not_needed' => [Terms::NOT_NEEDED, 'bg-secondary-lt'], 'condition' => ['Выполнять этап?', 'bg-yellow-lt'],
    ];
    private const MODE = [Stage::MODE_DELEGATED => ['Дочерняя заявка', 'bg-azure-lt'],
        Stage::MODE_APPROVAL => ['Согласование', 'bg-purple-lt'], Stage::MODE_INLINE => ['Задача', 'bg-teal-lt']];

    /**
     * Действие текущего этапа, которое можно выполнить с заявки (для меню «Ответ» в ленте).
     *
     * @return array<string, mixed>|null
     */
    public static function currentAction(CommonITILObject $item): ?array
    {
        $instance = Instance::getForItem($item);
        if ($instance === null || $instance->fields['state'] !== Instance::RUNNING) {
            return null;
        }
        $current = $instance->getCurrentStep();
        $stage = $current?->getStage();
        if ($current === null || $stage === null) {
            return null;
        }
        $title = '«' . (string) $current->fields['stage_name'] . '»';
        if (Session::getCurrentInterface() === 'central' && Authorizer::stepError($current, 'complete') === null) {
            return ['command' => self::commandFields($instance), 'kind' => 'complete', 'steps_id' => $current->getID(),
                'title' => $title, 'instruction' => (string) $stage->fields['content'],
                'comment' => (string) $stage->fields['completion_comment'], 'is_optional' => (int) $stage->fields['is_optional']]
                + self::decisionFields($current);
        }
        if (Session::getCurrentInterface() === 'central' && Authorizer::stepError($current, 'set_approver') === null) {
            return ['command' => self::commandFields($instance), 'kind' => 'approver', 'steps_id' => $current->getID(), 'title' => $title];
        }
        return null;
    }

    private static function commandFields(Instance $instance): ?array
    {
        return DefinitionSnapshot::enabled($instance)
            ? ['key' => bin2hex(random_bytes(32)), 'attempt_id' => (int) $instance->fields['current_attempt_id']]
            : null;
    }

    /** Вкладка маршрута на заявке (или на дочерней заявке отдела). */
    public static function showFlowTab(CommonITILObject $item): void
    {
        if (Authorizer::itemError($item, READ) !== null && !self::trashReadable(Instance::getForItem($item), $item)) {
            return;
        }
        $instance = Instance::getForItem($item);
        $child_step = StructuredUi::childStep($item);
        if ($child_step !== null && ($parent = $child_step->getInstance()) !== null) {
            self::render($item, $parent, $child_step);
        }
        if ($instance === null) {
            if ($child_step !== null) { return; }
            $available = []; $active = 0;
            foreach ((new Process())->find([
                'is_active' => 1,
                'itemtype'  => $item->getType(),
            ] + getEntitiesRestrictCriteria(Process::getTable(), '', $item->fields['entities_id'], true)) as $id => $p) {
                $active++;
                if (Authorizer::startError($item, (int) $id) === null) {
                    $available[$id] = $p['name'];
                }
            }
            // Маршруты есть, но этот человек их не запускает (инициатор, исполнитель) — «не запущен»,
            // а не «не описано ни одного маршрута».
            TemplateRenderer::getInstance()->display('@itilflow/flow_start.html.twig', [
                'item' => $item, 'processes' => $available, 'can_start' => count($available) > 0, 'active' => $active,
            ]);
            return;
        }
        self::render($item, $instance, null);
    }

    /**
     * Заявка в корзине: GLPI показывает её сотруднику с правом чтения, вкладка
     * показывает её маршрут. Это только чтение: каждое действие сервер проверяет как обычно.
     */
    public static function trashReadable(?Instance $instance, ?CommonITILObject $item): bool
    {
        return $instance !== null && $item !== null && !empty($item->fields['is_deleted'])
            && Session::getCurrentInterface() === 'central' && (int) Session::getLoginUserID() > 0
            && $instance->fields['itemtype'] === $item->getType() && (int) $instance->fields['items_id'] === (int) $item->getID()
            && (int) $instance->fields['entities_id'] === (int) $item->fields['entities_id']
            && Session::haveAccessToEntity((int) $item->fields['entities_id'])
            && $item->can($item->getID(), READ);
    }

    private static function render(CommonITILObject $item, Instance $instance, ?Step $only): void
    {
        $parent = $instance->getItilItem();
        $trashed = $parent !== null && !empty($parent->fields['is_deleted']);
        if (Authorizer::instanceError($instance, 'read') !== null && $only === null
            && !($trashed && self::trashReadable($instance, $parent))) {
            return;
        }
        $staff = Session::getCurrentInterface() === 'central';
        $admin = $staff && Authorizer::isAdministrator();
        $initiator = $parent !== null ? self::seesAsInitiator($parent, $staff) : !$staff;
        $process = $instance->getProcess();
        $rows = self::rows($instance, $staff, $admin, $only, $initiator);

        $header_menu = [];
        if ($only === null && $staff && Authorizer::instanceError($instance, 'abort') === null) {
            $header_menu[] = ['action' => 'abort', 'label' => 'Прервать маршрут…'];
        }
        $can_resume = $only === null && $staff && Authorizer::instanceError($instance, 'resume') === null
            && (!DefinitionSnapshot::enabled($instance) || Attempt::current($instance)?->fields['state'] === Attempt::RUNNING);

        $scheme = $only === null && Scheme::canViewInstance($instance) && DefinitionSnapshot::load($instance) !== null
            ? Scheme::forInstance($instance, $rows) : null;

        TemplateRenderer::getInstance()->display('@itilflow/flow_tab.html.twig', [
            'command'      => self::commandFields($instance),
            'me'           => (int) Session::getLoginUserID(),
            'step_url'     => \Plugin::getWebDir('itilflow') . '/front/step.form.php',
            'outcome_url'  => \Plugin::getWebDir('itilflow') . '/front/outcome.form.php',
            'decision_url' => \Plugin::getWebDir('itilflow') . '/front/decision.form.php',
            'is_staff'     => $staff,
            'is_admin'     => $admin,
            'only'         => $only !== null,
            'instance'     => $instance,
            'process_name' => $process ? $process->fields['name'] : '',
            'parent_link'  => $only !== null && $parent !== null && $parent->can($parent->getID(), READ) ? $parent->getLink() : '',
            'state'        => self::instanceState($instance, $parent, $trashed, $initiator),
            'rows'         => $rows,
            // Скрытые от инициатора этапы и чужие этапы дочерней заявки не раскрываются сводкой.
            'required_progress' => $staff && $only === null ? Sheet::progressFor($instance) : null,
            'header_menu'  => $header_menu,
            'can_resume'   => $can_resume,
            'withdraw'     => $only === null && Authorizer::instanceError($instance, 'withdraw') === null,
            'scheme'       => $scheme,
            'scheme_hint'  => self::schemeHint($only === null, $staff, $admin, $scheme !== null,
                (bool) Session::haveRight('plugin_itilflow_process', READ)),
            'sheet_url'    => $staff && $only === null ? \Plugin::getWebDir('itilflow') . '/front/sheet.php?id=' . $instance->getID() : '',
            'violations'   => $admin && $only === null && Session::haveRight('plugin_itilflow_process', READ) ? self::violations($instance) : [],
        ]);
    }

    /**
     * Строка «Схема — у администратора маршрутов» под таблицей: только сотруднику с правом
     * чтения маршрутов, которому схема на заявке не показана. Инициатору, согласующему
     * и исполнителю без права на маршруты она ничего не даёт; администратор видит саму схему.
     */
    public static function schemeHint(bool $whole, bool $staff, bool $admin, bool $hasScheme, bool $canReadRoutes): bool
    {
        return $whole && $staff && !$admin && !$hasScheme && $canReadRoutes;
    }

    /**
     * Видит ли текущий человек вкладку так, как её видит инициатор заявки: без строк
     * «Не нужен по условию», вопросов этапа и условий. В основном интерфейсе — нет. В самообслуживании —
     * да, кроме согласующего этой заявки: у него есть запрос на согласование — ему, его группе или ему
     * как действующему заместителю. Это проверка ядра GLPI (CommonITILValidation::canValidate),
     * по ней же GLPI открывает ему заявку. $canValidate — та же проверка без базы (юнит-тест).
     */
    public static function seesAsInitiator(CommonITILObject $item, bool $central, ?\Closure $canValidate = null): bool
    {
        if ($central) { return StepLine::asInitiator(true, false); }
        $canValidate ??= static function (CommonITILObject $item): bool {
            $class = $item::getValidationClassName();
            return $class !== null && (int) Session::getLoginUserID() > 0 && $class::canValidate((int) $item->getID());
        };
        return StepLine::asInitiator(false, (bool) $canValidate($item));
    }

    /** Слово состояния маршрута — то же, что на вкладке заявки (лист согласования). */
    public static function stateWord(Instance $instance, ?CommonITILObject $item): string
    {
        return self::instanceState($instance, $item)[0];
    }

    /** Общее состояние маршрута одной фразой для человека. $initiator — так видит инициатор заявки. */
    private static function instanceState(Instance $instance, ?CommonITILObject $item, bool $trashed = false, bool $initiator = false): array
    {
        $solved = $item !== null && in_array((int) $item->fields['status'],
            array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true);
        $attempt = DefinitionSnapshot::enabled($instance) ? Attempt::current($instance) : null;
        $state = match (true) {
            $instance->fields['state'] === Instance::DONE => [Terms::PASSED, 'bg-green-lt', $solved ? 'Все этапы пройдены, заявка решена.' : 'Все этапы пройдены.'],
            $instance->fields['state'] === Instance::ABORTED && self::withdrawal($instance) !== null => ['Отозвана', 'bg-secondary-lt', 'Заявку отозвал инициатор.'],
            $instance->fields['state'] === Instance::ABORTED && $attempt?->fields['state'] === Attempt::REFUSED => [Terms::REFUSAL, 'bg-red-lt', $solved ? 'Причина — в решении заявки.' : 'Отказ на одном из этапов.'],
            $instance->fields['state'] === Instance::ABORTED => [Terms::ABORTED, 'bg-secondary-lt', 'Маршрут прерван.'],
            // Инициатору — от первого лица (1.13.2): «Ждём ответа инициатора» читалось как о ком-то другом.
            $attempt?->fields['state'] === Attempt::WAITING_INPUT => [$initiator ? Terms::WAITING_YOUR_ANSWER : Terms::WAITING_INITIATOR, 'bg-orange-lt',
                StepLine::waitingHeader($initiator, (string) ($attempt->fields['subject_summary'] ?? ''))],
            $instance->fields['state'] === Instance::HALTED => [Terms::STOPPED, 'bg-yellow-lt', 'Решает администратор маршрутов.'],
            default => [Terms::IN_PROGRESS, 'bg-blue-lt', ''],
        };
        if ($trashed && in_array($instance->fields['state'], [Instance::RUNNING, Instance::HALTED], true)) {
            $state[2] = trim($state[2] . ' Заявка в корзине: действия недоступны.');
        }
        return $state;
    }

    /** Отзыв инициатором: запись журнала действий; null — отзыва не было. */
    private static function withdrawal(Instance $instance): ?string
    {
        foreach ((new Violation())->find(['plugin_itilflow_instances_id' => $instance->getID(), 'action' => 'action_withdraw',
            'was_blocked' => 0], ['id DESC'], 1) as $row) {
            $msg = json_decode((string) $row['message'], true);
            return is_array($msg) ? trim((string) ($msg['reason'] ?? '')) : '';
        }
        return null;
    }

    /** Этапы снимка запуска — для текста условий (названия этапов и вопросов). */
    public static function snapshotStages(Instance $instance): array
    {
        return DefinitionSnapshot::load($instance)['stages'] ?? [];
    }

    /**
     * Условие «Выполнять, если» этапа, с которым идёт запуск; без условий или повреждённое — [].
     * Запуски, начатые до условий этапов, условий не проверяют — и на экране их нет.
     */
    private static function stageRule(Step $step): array
    {
        $instance = $step->getInstance();
        if ($instance === null || DefinitionSnapshot::schemaOf($instance) < 3) { return []; }
        $raw = $step->getStage()?->fields['run_if'] ?? null;
        $rule = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        return is_array($rule) && is_array($rule['clauses'] ?? null) && $rule['clauses'] !== [] ? $rule : [];
    }

    /**
     * Строка «Не нужен по условию»: причина — факты условия, которые дали «нет»,
     * или выбор человека: его причина, кто и когда. Для строк, которые заменила новая попытка,
     * факты сейчас уже другие, поэтому их причина не пересчитывается.
     *
     * @return array{label:string,summary:string,author:?string,date:?string,visible:bool}
     */
    public static function notNeededLine(Instance $instance, Step $step, array $row, bool $initiator, array $stages): array
    {
        $note = json_decode((string) ($row['condition_note'] ?? ''), true);
        $note = is_array($note) ? $note : [];
        $manual = is_array($note['manual'] ?? null) && ($note['manual']['choice'] ?? '') === 'skip' ? $note['manual'] : null;
        $reason = ''; $author = null; $date = null;
        if ($manual !== null) {
            $author = (int) ($manual['actor'] ?? 0) > 0 ? (string) getUserName((int) $manual['actor']) : null;
            $date = is_string($manual['at'] ?? null) && $manual['at'] !== '' ? (string) \Html::convDateTime($manual['at']) : null;
        } elseif (!$initiator && empty($row['superseded_by_attempt_id']) && ($rule = self::stageRule($step)) !== []) {
            try {
                $reason = ConditionText::reason($rule, Engine::conditionContext($step), $stages);
            } catch (\Throwable $e) {
                // Строка заявки не падает из-за текста причины: метка остаётся, причины нет.
                $reason = '';
            }
        }
        return StepLine::notNeeded($note, $reason, $author, $date, $initiator);
    }

    /** Будущий этап: «Впереди» и для условного этапа — «если …» (у инициатора — без условия). */
    public static function aheadLine(Step $step, bool $initiator, array $stages): array
    {
        $rule = $initiator ? [] : self::stageRule($step);
        return StepLine::ahead($rule === [] ? '' : ConditionText::short($rule, $stages), $initiator);
    }

    private static function rows(Instance $instance, bool $staff, bool $admin, ?Step $only, bool $initiator): array
    {
        $structured = DefinitionSnapshot::enabled($instance);
        $attempt = $structured ? Attempt::current($instance) : null;
        $stages = self::snapshotStages($instance);
        $groups = [];
        foreach ($instance->getSteps() as $row) {
            $groups[(int) $row['plugin_itilflow_stages_id']][] = $row;
        }
        uasort($groups, fn($a, $b) => (int) $a[0]['ranking'] <=> (int) $b[0]['ranking']);
        $current = $instance->getCurrentStep();
        $waiting_target = $attempt?->fields['state'] === Attempt::WAITING_INPUT ? (int) $attempt->fields['target_stage_id'] : 0;
        $withdrawn = $instance->fields['state'] === Instance::ABORTED ? self::withdrawal($instance) : null;
        $abort_source = $instance->fields['state'] === Instance::ABORTED ? (int) ($attempt?->fields['source_step_id'] ?? 0) : 0;

        $out = [];
        foreach ($groups as $stage_id => $passes) {
            usort($passes, fn($a, $b) => [(int) $a['pass_number'], (int) $a['id']] <=> [(int) $b['pass_number'], (int) $b['id']]);
            $latest = end($passes);
            if ($initiator && $latest['state'] === Step::NOT_NEEDED) { continue; }
            if ($only !== null && (int) $latest['id'] !== (int) $only->getID()) {
                continue;
            }
            $step = new Step();
            if (!$step->getFromDB((int) $latest['id'])) { continue; }
            $is_current = $current !== null && (int) $current->getID() === (int) $latest['id'];
            $result = self::result($instance, $step, $latest, $staff, [
                'current' => $is_current, 'waiting' => $waiting_target === (int) $stage_id && $latest['state'] === Step::PENDING,
                'withdrawn' => $withdrawn, 'abort_source' => $abort_source, 'initiator' => $initiator, 'stages' => $stages,
            ]);
            $history = [];
            foreach (array_slice($passes, 0, -1) as $old) {
                if ($initiator && $old['state'] === Step::NOT_NEEDED) { continue; }
                $os = new Step();
                if ($os->getFromDB((int) $old['id'])) {
                    $history[] = ['try' => (int) $old['pass_number']] + self::result($instance, $os, $old, $staff,
                        ['current' => false, 'waiting' => false, 'withdrawn' => null, 'abort_source' => $abort_source,
                            'initiator' => $initiator, 'stages' => $stages]);
                }
            }
            $mode = self::MODE[$latest['execution_mode']] ?? [$latest['execution_mode'], 'bg-secondary-lt'];
            $duration = $structured ? Step::elapsedSeconds($latest) : (int) $latest['duration'];
            [$actions, $menu] = $only !== null && !$is_current ? [[], []] : self::actions($instance, $step, $staff, $admin, $is_current,
                $waiting_target === (int) $stage_id && $latest['state'] === Step::PENDING);
            // В самообслуживании «Вопросы этапа» получает только адресат запроса на согласование (1.13.3) — его очередь.
            if ($result['kind'] === 'running' && self::yourTurn(array_column($actions, 'action'),
                $step->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups())
                    || (!$staff && in_array('decision', array_column($actions, 'action'), true)))) {
                $result['summary'] = 'Ждём вашего действия.';
            }
            $out[] = [
                'rank' => (int) $latest['ranking'], 'step_id' => (int) $latest['id'], 'name' => (string) $latest['stage_name'],
                'mode_label' => $mode[0], 'mode_class' => $mode[1],
                'approval' => self::approvalInfo($step, $initiator),
                'decisions' => self::decisionSummary($step, $initiator),
                'owner' => self::owner($step, $latest), 'artifact' => $staff && $only === null ? self::artifactLink($step, $latest) : '',
                'current' => $is_current || $result['kind'] === 'waiting_input',
                // Инициатору — без номеров попыток и истории этапа: это служебное.
                'try' => !$initiator && count($history) > 0 ? (int) $latest['pass_number'] : 0,
                'result' => $result, 'history' => $initiator ? [] : $history,
                'due' => in_array($latest['state'], [Step::RUNNING, Step::PENDING], true) && $latest['date_due']
                    ? \Html::convDateTime((string) $latest['date_due']) : '',
                'overdue' => Sheet::wasOverdue($latest),
                'took' => in_array($latest['state'], [Step::DONE, Step::SKIPPED, Step::REJECTED], true) && $duration !== null && $duration > 0
                    ? self::took($duration) : '',
                'actions' => $actions, 'menu' => $menu,
            ];
        }
        return $out;
    }

    /**
     * «Ждём вашего действия» — только тому, чей это шаг: исполнителю этапа, согласующему, исполнителю
     * заявки, который указывает согласующего, исполнителю дочерней заявки. Администратор маршрутов,
     * который может закрыть чужую задачу, этой строки не получает.
     *
     * @param list<string> $actions
     */
    public static function yourTurn(array $actions, bool $stageOwner): bool
    {
        return array_intersect($actions, ['visa', 'set_approver', 'outcome']) !== []
            || ($stageOwner && array_intersect($actions, ['complete', 'decision']) !== []);
    }

    /** «Нужно N» — от исходного списка согласующих, а не от оставшихся после снятия запросов. */
    public static function approvalSummary(array $p): string
    {
        if (!empty($p['errors'])) { return implode(' ', $p['errors']); }
        $text = sprintf('Одобрили %d из %d, нужно %d.', $p['accepted'], $p['total'], $p['needed']);
        if ($p['refused'] > 0) { $text .= ' Отказали: ' . $p['refused'] . '.'; }
        if ($p['dropped'] > 0) { $text .= ' Снято без ответа: ' . $p['dropped'] . '.'; }
        if (!empty($p['stalled'])) { $text .= ' GLPI не подвёл итог — этап закроет администратор маршрутов.'; }
        return $text;
    }

    /**
     * Сохранённая причина и предупреждения отправки, без пересчёта живого списка.
     * Пропущенный согласующий — по имени ($name, по умолчанию ConditionText::name).
     */
    public static function approvalNotes(mixed $json, ?\Closure $name = null): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) { return []; }
        $name ??= static fn(string $itemtype, int $id): string => ConditionText::name($itemtype, $id);
        $out = [];
        foreach (['errors', 'warnings'] as $key) {
            foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $text) {
                if (is_string($text) && $text !== '') { $out[] = $text; }
            }
        }
        foreach (is_array($data['excluded'] ?? null) ? $data['excluded'] : [] as $row) {
            if (is_array($row) && is_string($row['reason'] ?? null)) {
                $out[] = $name('User', (int) ($row['id'] ?? 0)) . ': ' . $row['reason'];
            }
        }
        return array_values(array_unique($out));
    }
    /** Текущие заместители — подсказка, а не выдача полномочия. */
    public static function deputyText(string $target, array $names): string
    {
        return $names ? 'Ждём: ' . $target . ' или заместитель ' . implode(', ', array_unique($names)) . '.' : '';
    }

    private static function waitingDeputies(Step $step): array
    {
        $item = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
            ? StructuredAccess::child($step) : $step->getInstance()?->getItilItem();
        if ($item === null || Authorizer::itemError($item, READ) !== null) { return []; }
        $rows = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
            ? StructuredAccess::roster($step) : self::validations($step);
        $out = [];
        foreach ($rows ?? [] as $row) {
            if ((int) $row['status'] !== \CommonITILValidation::WAITING) { continue; }
            $users = [];
            if ($row['itemtype_target'] === 'User') { $users[] = (int) $row['items_id_target']; }
            elseif ($row['itemtype_target'] === 'Group') {
                foreach ((new \Group_User())->find(['groups_id' => (int) $row['items_id_target']]) as $member) { $users[] = (int) $member['users_id']; }
            }
            $names = [];
            foreach (array_unique($users) as $uid) {
                $state = Authorizer::approvalUserState($uid, (int) $item->fields['entities_id'], $item->getType());
                foreach ($state['substitutes'] as $deputy) { $names[] = (string) getUserName($deputy); }
            }
            $text = self::deputyText(self::targetName($row), $names);
            if ($text !== '') { $out[] = $text; }
        }
        return $out;
    }
    /**
     * Строки о согласовании под исполнителем. Инициатору — без счёта «Одобрили N из M, нужно K»:
     * кого ждём, сказано в строке результата («В ожидании согласования: …»).
     */
    public static function approvalInfo(Step $step, bool $initiator = false): array
    {
        if ($step->fields['execution_mode'] !== Stage::MODE_APPROVAL
            && ($step->getStage()?->fields['department_result_source'] ?? '') !== 'native_validation') { return []; }
        // У этапа, который не нужен по условию или так и не начался, согласующих не выбирают.
        if ($step->fields['state'] === Step::NOT_NEEDED
            || ($step->fields['state'] === Step::SUPERSEDED && empty($step->fields['date_start']))) { return []; }
        if ($initiator) {
            return $step->awaitsApprover() ? ['Согласование ждёт выбора или замены согласующего.'] : [];
        }
        $item = $step->getInstance()?->getItilItem();
        $operator = $item !== null && Authorizer::itemError($item, READ) === null
            && (Authorizer::isAdministrator() || Authorizer::isAssignee($item));
        // Предупреждения о согласующих — только у этапов, которые впереди или идут.
        $open = StepLine::rosterNotesVisible((string) $step->fields['state']);
        $notes = $operator && $open ? self::approvalNotes($step->fields['approver_fallback'] ?? null) : [];
        if ($operator && ($formReason = FormAdoption::reasonForStep($step)) !== null) { $notes[] = $formReason; }
        if (!$operator && $step->awaitsApprover()) { $notes = ['Согласование ждёт выбора или замены согласующего.']; }
        if ((int) ($step->fields['approval_expected_count'] ?? 0) === 0) {
            return $notes ?: StepLine::approversNotChosen((string) $step->fields['state']);
        }
        $p = ApprovalRoster::forStep($step);
        $out = array_merge([self::approvalSummary($p)], $notes);
        if ($operator && $step->fields['state'] === Step::RUNNING) {
            $out = array_merge($out, self::waitingDeputies($step));
        }
        return $out;
    }
    /** Результат строки: метка, одна строка пояснения, автор. */
    private static function result(Instance $instance, Step $step, array $row, bool $staff, array $ctx): array
    {
        $initiator = (bool) ($ctx['initiator'] ?? !$staff);
        $stages = $ctx['stages'] ?? [];
        $kind = 'pending'; $summary = ''; $author = ''; $date = ''; $internal = '';
        $outcome = DefinitionSnapshot::enabled($instance) ? Outcome::forStep((int) $row['id']) : null;
        if ($outcome !== null) {
            $kind = match ($outcome->fields['outcome']) { Outcome::COMPLETED => 'done', Outcome::REFUSED => 'refused', default => 'clarification' };
            $summary = trim((string) $outcome->fields['public_summary']);
            $author = (int) $outcome->fields['users_id'] > 0 ? (string) getUserName((int) $outcome->fields['users_id']) : '';
            $date = (string) $outcome->fields['date_creation'] !== '' ? (string) \Html::convDateTime((string) $outcome->fields['date_creation']) : '';
            $reason = trim((string) $outcome->fields['reason']);
            $internal = $staff && $reason !== '' && $reason !== $summary
                ? ($outcome->fields['source_kind'] === 'native_validation' ? 'Комментарий согласующего: ' : 'Приватный комментарий: ') . $reason : '';
            if ($kind === 'done' && $row['state'] !== Step::DONE) { $kind = $row['state'] === Step::RUNNING ? 'running' : 'notrun'; }
        } else {
            switch ($row['state']) {
                case Step::DONE: $kind = 'done'; break;
                case Step::SKIPPED: $kind = 'skipped'; break;
                case Step::NOT_NEEDED:
                    $kind = 'not_needed';
                    $line = self::notNeededLine($instance, $step, $row, $initiator, $stages);
                    $summary = $line['summary']; $author = (string) ($line['author'] ?? ''); $date = (string) ($line['date'] ?? '');
                    break;
                case Step::REJECTED: $kind = 'refused'; break;
                case Step::RUNNING: $kind = 'running'; break;
                case Step::SUPERSEDED:
                    $kind = match (true) {
                        $ctx['withdrawn'] !== null && (int) $row['id'] === $ctx['abort_source'] => 'withdrawn',
                        (int) $row['id'] === $ctx['abort_source'] && $instance->fields['state'] === Instance::ABORTED => 'aborted',
                        empty($row['date_start']) => 'notrun',
                        !empty($row['superseded_by_attempt_id']) => 'previous',
                        default => 'unfinished',
                    };
                    break;
                default:
                    $kind = $ctx['waiting'] ? 'waiting_input'
                        : (in_array($instance->fields['state'], [Instance::DONE, Instance::ABORTED], true) ? 'notrun' : 'pending');
            }
            if (in_array($kind, ['done', 'skipped', 'refused'], true)) {
                $summary = trim(strip_tags((string) ($row['comment'] ?? '')));
                $author = (int) ($row['users_id_actual'] ?? 0) > 0 ? (string) getUserName((int) $row['users_id_actual']) : '';
                $date = (string) ($row['date_end'] ?? '') !== '' ? (string) \Html::convDateTime((string) $row['date_end']) : '';
            }
            if ($kind === 'withdrawn') { $summary = (string) $ctx['withdrawn']; }
        }
        if ($kind === 'running') { $summary = self::waitingText($step, $row, $staff); }
        if ($kind === 'pending') { $summary = self::aheadLine($step, $initiator, $stages)['summary']; }
        $condition = json_decode((string) ($row['condition_note'] ?? ''), true);
        if ($staff && $row['state'] === Step::PENDING) {
            if (!empty(FormFacts::load($instance)['recovery_required'])) {
                $kind = 'condition'; $summary = 'Не удалось прочитать ответы формы. Решите, выполнять ли этап.';
            } elseif (in_array('awaiting_form_callback', $condition['reasons'] ?? [], true)) {
                $summary = 'Ждём завершения отправки формы.';
            } elseif (($condition['truth'] ?? '') === 'unknown') {
                $kind = 'condition'; $summary = 'Нет ответа для условия — решите, выполнять ли этап.';
            }
        }
        if ($kind === 'waiting_input') {
            $summary = StepLine::waitingInput($staff, Engine::answerContinues($instance));
        }
        // Этап не открылся (например, срок дальше 2038 года) — движок оставил его «Впереди»,
        // причину записал в комментарий этапа и остановил маршрут. Сотрудник видит причину в строке.
        $problem = false;
        if ($staff && $row['state'] === Step::PENDING && empty($row['date_start'])
            && ($why = StepLine::notStarted(strip_tags((string) ($row['comment'] ?? '')))) !== '') {
            $summary = $why; $problem = true;
        }
        [$label, $class] = self::OUTCOME[$kind];
        if ($kind === 'waiting_input' && $initiator) { $label = Terms::WAITING_YOUR_ANSWER; }
        // У этапа согласования — «Согласовано» / «Отклонено», те же слова, что в листе (Sheet::resultLabel).
        if (in_array($kind, ['done', 'refused'], true)) {
            $label = Sheet::resultLabel($row, $kind === 'done' ? Outcome::COMPLETED : Outcome::REFUSED, false,
                $outcome?->fields['source_kind'] ?? null);
        }
        // «Согласовано» / «Согласовано.»: пояснение, которое повторяет метку, не показываем.
        $summary = StepLine::withoutRepeat($label, $summary);
        return ['kind' => $kind, 'label' => $label, 'class' => $class, 'summary' => $summary, 'internal' => $internal,
            'author' => $author, 'date' => $date, 'problem' => $problem];
    }

    /** Чего ждём на текущем этапе — простыми словами. */
    private static function waitingText(Step $step, array $row, bool $staff): string
    {
        if (Decision::items($step) !== [] && !Decision::ready($step)) {
            return $staff ? 'Ждём ответы на вопросы этапа.' : 'Этап в работе.';
        }
        if ($row['execution_mode'] === Stage::MODE_APPROVAL) {
            if ($step->awaitsFormRequests()) {
                return $staff ? 'Согласование из формы не найдено — засчитайте его вручную.' : 'Ждём согласования.';
            }
            if ($step->awaitsApprover()) {
                return ($step->getStage()?->fields['approver_source'] ?? '') === Stage::APPROVER_RUNTIME
                    ? 'Ждём: исполнитель заявки укажет согласующего.'
                    : 'Согласующие недоступны. Замену выберет администратор маршрутов.';
            }
            $names = [];
            foreach (self::validations($step) as $v) {
                if ((int) $v['status'] === \CommonITILValidation::WAITING) { $names[] = self::targetName($v); }
            }
            return $names ? Terms::WAITING_APPROVAL . ': ' . implode(', ', array_unique($names)) . '.' : Terms::WAITING_APPROVAL . '.';
        }
        $owner = self::owner($step, $row);
        return $row['execution_mode'] === Stage::MODE_DELEGATED
            ? 'В работе у отдела: ' . $owner . '.'
            : 'В работе: ' . $owner . '.';
    }

    public static function owner(Step $step, array $row): string
    {
        if ($row['execution_mode'] === Stage::MODE_APPROVAL) {
            $names = [];
            if ((int) ($row['approval_expected_count'] ?? 0) > 0) {
                foreach (ApprovalRoster::forStep($step)['targets'] as $target) {
                    $names[] = self::targetName(['itemtype_target' => $target['itemtype'], 'items_id_target' => $target['id']]);
                }
            } else {
                $names = array_map([self::class, 'targetName'], self::validations($step));
            }
            if ($names) { return implode(', ', array_unique($names)); }
            $stage = $step->getStage();
            $source = (string) ($stage?->fields['approver_source'] ?? '');
            // Повтор после уточнения: запрос уйдёт тем же согласующим — их и называем.
            if (in_array($source, ['form', Stage::APPROVER_RUNTIME], true) && in_array($row['state'] ?? '', [Step::PENDING, Step::RUNNING], true)
                && (int) ($row['pass_number'] ?? 1) > 1 && !$step->isNewItem()
                && ($plan = Engine::repeatPlan($step))['targets'] !== []) {
                return implode(', ', array_unique(array_map(static fn(array $t): string =>
                    self::targetName(['itemtype_target' => $t[0], 'items_id_target' => $t[1]]), $plan['targets'])));
            }
            if ($source === Stage::APPROVER_RUNTIME) { return 'Согласующего укажет исполнитель'; }
            if ($source === 'form') { return 'Согласующие из формы'; }
            if ($stage !== null) { return Stage::approvalNames($stage->fields); }
        }
        if ((int) $row['groups_id'] > 0) {
            $g = new Group();
            return $g->getFromDB((int) $row['groups_id']) ? (string) $g->fields['name'] : '—';
        }
        return (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '—';
    }

    /** Запросы на согласование этапа без строгих проверок — только для показа. */
    private static function validations(Step $step): array
    {
        $type = (string) ($step->fields['artifact_itemtype'] ?? '');
        $ids = $step->validationIds();
        if ($step->fields['execution_mode'] !== Stage::MODE_APPROVAL || !is_a($type, \CommonITILValidation::class, true) || !$ids) {
            return [];
        }
        return array_values((new $type())->find(['id' => $ids]));
    }

    private static function targetName(array $v): string
    {
        if (($v['itemtype_target'] ?? '') === 'Group') {
            $g = new Group();
            return $g->getFromDB((int) $v['items_id_target']) ? (string) $g->fields['name'] : '—';
        }
        return getUserName((int) ($v['items_id_target'] ?? $v['users_id_validate'] ?? 0));
    }

    private static function artifactLink(Step $step, array $row): string
    {
        $type = (string) ($row['artifact_itemtype'] ?? '');
        $aid = (int) $row['artifact_items_id'];
        if ($row['execution_mode'] !== Stage::MODE_DELEGATED || $aid <= 0 || !is_a($type, CommonITILObject::class, true)) {
            return '';
        }
        $a = new $type();
        return $a->getFromDB($aid) && $a->can($aid, READ)
            ? self::childLink($a->getLinkURL(), (string) $a->fields['name'], $aid) : '';
    }

    /**
     * Ссылка на дочернюю заявку рядом со значком «Дочерняя заявка»: только номер, без повтора
     * слов значка; название заявки — во всплывающей подсказке.
     */
    public static function childLink(string $url, string $name, int $id): string
    {
        return sprintf('<a href="%s" title="%s">№%d</a>', htmlescape($url), htmlescape($name), $id);
    }

    /**
     * Кнопки человека в строке этапа и пункты маленького меню. Показывается только то,
     * что сервер разрешит этому человеку: проверки те же, что в Engine.
     *
     * @return array{0: array, 1: array}
     */
    private static function actions(Instance $instance, Step $step, bool $staff, bool $admin, bool $current, bool $waiting): array
    {
        $acts = []; $menu = [];
        $structured = DefinitionSnapshot::enabled($instance);
        $targets = $structured ? self::targets($instance, $step) : [];
        if ($staff && $structured && Engine::conditionCanChoose($step)) {
            $note = Engine::conditionEvaluation($step);
            $acts[] = ['action' => 'condition', 'label' => 'Выполнять этап?',
                'input_revision' => (string) ($note['input_revision'] ?? ''), 'can_skip' => Engine::conditionCanChoose($step, true)];
        }
        if ($current && $instance->fields['state'] === Instance::RUNNING) {
            $decision = null;
            // 1.13.3: и в самообслуживании — адресату запроса на согласование этапа (Decision::canWrite):
            // вопросы этапа и «Сохранить и одобрить», как в основном интерфейсе.
            if (Decision::canWrite($step)) {
                $approveId = 0;
                foreach (StructuredAccess::roster($step) ?? [] as $v) {
                    $type = $v['_type']; $vo = new $type(); $vo->fields = $v;
                    if ((int) $v['status'] === \CommonITILValidation::WAITING && ValidationAuthority::canAnswer($vo)
                        && $vo->can($vo->getID(), UPDATE)) { $approveId = (int) $vo->getID(); break; }
                }
                $decision = ['action' => 'decision', 'label' => Terms::STAGE_QUESTIONS, 'can_approve' => $approveId > 0,
                    'validation_id' => $approveId] + self::decisionFields($step);
            }
            if ($structured && $step->fields['execution_mode'] === Stage::MODE_DELEGATED
                && Outcome::forStep($step->getID()) === null && StructuredAccess::answerError($step) === null) {
                $acts[] = ['action' => 'outcome', 'label' => 'Отметить результат',
                    'can_return' => count($targets) > 0 && StructuredAccess::answerError($step, true) === null, 'targets' => $targets];
            }
            if ($step->fields['execution_mode'] === Stage::MODE_APPROVAL && !$step->awaitsApprover() && !$step->awaitsFormRequests()) {
                foreach (self::validations($step) as $v) {
                    $vo = new ($step->fields['artifact_itemtype'])();
                    if ((int) $v['status'] === \CommonITILValidation::WAITING && $vo->getFromDB((int) $v['id'])
                        && ValidationAuthority::canAnswer($vo) && $vo->can($vo->getID(), UPDATE)) {
                        $acts[] = ['action' => 'visa', 'label' => 'Одобрить или отказать', 'validation_id' => (int) $v['id'],
                            'validation_url' => $vo::getFormURL(),
                            'can_return' => $structured && count($targets) > 0 && StructuredAccess::answerError($step, true) === null,
                            'targets' => $targets];
                        break;
                    }
                }
            }
            if ($staff && Authorizer::stepError($step, 'set_approver') === null) {
                $acts[] = ['action' => 'set_approver', 'label' => 'Указать согласующего',
                    'form_fallback' => ($step->getStage()?->fields['approver_source'] ?? '') === 'form',
                    'fallback_percent' => (int) ($step->fields['approval_percent_snapshot'] ?? 100)];
            }
            if ($staff && $step->awaitsExistingRequests() && Authorizer::formAdoptionError($step) === null) {
                $answeredHidden = false;
                $groups = self::formRequestChoices($instance, $step, $answeredHidden);
                // 1.13.2: если засчитать нечего — кто поможет (Ui::nothingToAdopt).
                $acts[] = ['action' => 'adopt_existing', 'label' => 'Засчитать согласование', 'groups' => $groups,
                    'reason' => FormAdoption::reasonForStep($step), 'empty_text' => $groups === [] ? self::nothingToAdopt($answeredHidden,
                        in_array('set_approver', array_column($acts, 'action'), true)) : ''];
            }
            if ($staff && Authorizer::stepError($step, 'complete') === null) {
                $stage = $step->getStage();
                $acts[] = ['action' => 'complete', 'label' => 'Завершить этап',
                    'comment' => (string) ($stage?->fields['completion_comment'] ?? 'optional'), 'optional' => (int) ($stage?->fields['is_optional'] ?? 0)];
            }
            // Ответы этапа у задачи и у дочерней заявки — в «Завершить этап» и «Отметить результат»;
            // отдельное «Сохранить ответы» остаётся там, где своей формы результата нет (согласование).
            if ($decision !== null && array_intersect(array_column($acts, 'action'), ['outcome', 'complete']) === []) {
                array_unshift($acts, $decision);
            }
            if ($staff && Authorizer::stepError($step, 'reassign') === null) {
                $menu[] = ['action' => 'reassign', 'label' => 'Передать этап…'];
            }
            if ($staff && Authorizer::stepError($step, 'override') === null && ($admin || Authorizer::mayBypass())) {
                $menu[] = ['action' => 'override', 'label' => 'Закрыть принудительно…'];
            }
            if ($admin && $structured && StructuredAccess::manageError($instance) === null) {
                if (($step->fields['status_sync_state'] ?? '') === StatusSync::FAILED) {
                    $menu[] = ['action' => 'retry_status_sync', 'label' => 'Повторить смену статуса'];
                }
                foreach (self::validations($step) as $v) {
                    if (Outcome::forStep($step->getID()) === null
                        && in_array((int) $v['status'], [\CommonITILValidation::ACCEPTED, \CommonITILValidation::REFUSED], true)) {
                        $menu[] = ['action' => 'reconcile_outcome', 'label' => 'Перенести ответ согласующего',
                            'validation_id' => (int) $v['id'], 'validation_type' => (string) $step->fields['artifact_itemtype']];
                        break;
                    }
                }
            }
        }
        if ($waiting && $structured && $staff && StructuredAccess::activateError($instance) === null) {
            $acts[] = ['action' => 'activate', 'label' => 'Продолжить'];
        }
        if ($structured && ($outcome = Outcome::forStep($step->getID())) !== null && !empty($outcome->fields['explanation_pending'])
            && StructuredAccess::explanationError($outcome, $step) === null) {
            $positive = $outcome->fields['outcome'] === Outcome::COMPLETED;
            if (!$positive || Engine::canPublishPositive($outcome, $step)) {
                $acts[] = ['action' => $positive ? 'publish_positive_result' : 'explain_outcome', 'label' => Terms::ANSWER_INITIATOR,
                    'outcome_id' => (int) $outcome->getID()];
            }
        }
        if ($admin && $structured && StructuredAccess::manageError($instance) === null && !$current
            && in_array($step->fields['state'], [Step::REJECTED, Step::DONE, Step::SKIPPED, Step::SUPERSEDED], true)) {
            $attempt = Attempt::current($instance);
            $source = (int) ($attempt?->fields['source_step_id'] ?? 0);
            $terminal = $attempt !== null && in_array($attempt->fields['state'], [Attempt::REFUSED, Attempt::ABORTED, Attempt::DONE], true)
                && ((int) $step->getID() === $source || ($source === 0 && $step->fields['state'] === Step::REJECTED)
                    || ($attempt->fields['state'] === Attempt::DONE && self::isLastOfAttempt($instance, $step)));
            if ($terminal && $targets) {
                // Один этап для начала — он в самом пункте меню: «Начать заново с этапа 20 «Подготовить»».
                $only = count($targets) === 1 ? DefinitionSnapshot::stage($instance, (int) array_key_first($targets)) : null;
                $menu[] = ['action' => 'new_attempt', 'targets' => $targets, 'label' => $only !== null
                    ? StepLine::restartFrom((int) $only->fields['ranking'], (string) $only->fields['name']) : Terms::RESTART_FROM];
            }
        }
        foreach ($acts as &$action) {
            if (in_array($action['action'], ['outcome', 'complete', 'visa'], true)) { $action += self::decisionFields($step); }
        }
        unset($action);
        return [$acts, $menu];
    }

    /** Один контракт для строки и формы завершения в ленте. */
    public static function decisionFields(Step $step): array
    {
        $decision = Decision::read($step);
        return ['decision_items' => Decision::items($step), 'decision_answers' => $decision['answers'],
            'decision_revision' => $decision['revision']];
    }

    /**
     * Ответы на вопросы этапа одной строкой — у начатых этапов и не у инициатора.
     * Отсутствие ответа не показывается как «нет».
     */
    public static function decisionSummary(Step $step, bool $initiator = false): array
    {
        if ($initiator || in_array($step->fields['state'] ?? '', [Step::PENDING, Step::NOT_NEEDED], true)) { return []; }
        $line = StepLine::answers(Decision::items($step), Decision::read($step)['answers'], false);
        return $line === '' ? [] : [$line];
    }

    /**
     * Текст, когда засчитать нечего: что произошло и к кому идти. $answeredHidden — в заявке есть уже отвеченное
     * согласование, а засчитать его может только администратор маршрутов; $canSetApprover — у человека
     * есть «Указать согласующего».
     */
    public static function nothingToAdopt(bool $answeredHidden, bool $canSetApprover): string
    {
        return 'В заявке нет подходящего согласования.' . match (true) {
            $answeredHidden => ' Уже отвеченное согласование засчитывает администратор маршрутов.',
            $canSetApprover => ' Укажите согласующего — ему уйдёт запрос.',
            default => ' Обратитесь к администратору маршрутов.',
        };
    }

    /**
     * Показывается весь этап согласования GLPI; права и список снова проверяются под блокировкой POST.
     * $answeredHidden — есть уже отвеченное согласование, которое этому человеку засчитать нельзя.
     */
    private static function formRequestChoices(Instance $instance, Step $step, bool &$answeredHidden = false): array
    {
        global $DB;
        $item = $instance->getItilItem();
        if (!$item instanceof \Ticket || Authorizer::formAdoptionError($step) !== null) { return []; }
        $groups = [];
        foreach ($DB->request(['FROM' => \TicketValidation::getTable(), 'WHERE' => ['tickets_id' => (int) $item->getID()], 'ORDER' => 'id']) as $row) {
            $id = (int) $row['itils_validationsteps_id'];
            if ($id <= 0) { continue; }
            $groups[$id]['rows'][] = $row;
        }
        $out = [];
        foreach ($groups as $id => $group) {
            $plan = OutcomeValidation::adoptionPlan($group['rows'], ['ticket_id' => (int) $item->getID(), 'entity_id' => (int) $item->fields['entities_id']]);
            if ($plan === null || count($plan['ids']) > 50 || array_filter(Step::ownersMap('TicketValidation', $plan['ids']))) { continue; }
            $native = $item::getValidationStepInstance();
            if ($native === null || !$native->getFromDB($id) || $native->fields['itemtype'] !== 'Ticket' || (int) $native->fields['items_id'] !== (int) $item->getID()) { continue; }
            $answered = false; $valid = true; $ids = []; $labels = [];
            foreach ($group['rows'] as $row) {
                $ids[] = (int) $row['id'];
                if ((int) $row['entities_id'] !== (int) $item->fields['entities_id']) { $valid = false; }
                $answered = $answered || (int) $row['status'] !== \CommonITILValidation::WAITING;
                if (!in_array((int) $row['status'], [\CommonITILValidation::WAITING, \CommonITILValidation::ACCEPTED, \CommonITILValidation::REFUSED], true)) { $valid = false; }
                if ((new OutcomeValidation())->find(['validation_itemtype' => 'TicketValidation', 'validations_id' => (int) $row['id']], [], 1) !== []) { $valid = false; }
                $labels[] = self::targetName($row) . ' — ' . match ((int) $row['status']) { 2 => 'ждёт ответа', 3 => 'одобрил', 4 => 'отказал', default => 'недоступен' }
                    . ((int) $row['status'] !== 2 ? ', ответил ' . ManualAdoptionConfirmation::answerDate($row) : '');
            }
            if (!$valid) { continue; }
            if (Authorizer::formAdoptionError($step, $answered) !== null) { $answeredHidden = $answeredHidden || $answered; continue; }
            $name = \Dropdown::getDropdownName(\ValidationStep::getTable(), (int) $native->fields['validationsteps_id']);
            $dates = array_values(array_unique(array_map([ManualAdoptionConfirmation::class, 'answerDate'], array_filter($group['rows'], static fn(array $r): bool => (int) $r['status'] !== 2))));
            $out[] = ['ids' => $ids, 'label' => 'Этап согласования «' . $name . '», нужно ' . (int) $native->fields['minimal_required_validation_percent'] . ' % одобрений', 'requests' => $labels,
                'answered' => $answered, 'confirmation' => ManualAdoptionConfirmation::digest($group['rows'], $native->fields),
                'confirmation_label' => 'Ответ уже дан ' . implode(', ', $dates) . ' — засчитать его?'];
        }
        return $out;
    }

    /** Длительность коротко и по-русски, независимо от языка учётки («2 мин», а не «2 минут» GLPI). */
    public static function duration(int $s): string
    {
        return self::took($s);
    }

    private static function took(int $s): string
    {
        $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
        return match (true) {
            $d > 0 => $d . ' д' . ($h ? ' ' . $h . ' ч' : ''),
            $h > 0 => $h . ' ч' . ($m ? ' ' . $m . ' мин' : ''),
            $m > 0 => $m . ' мин',
            default => 'меньше минуты',
        };
    }

    private static function isLastOfAttempt(Instance $instance, Step $step): bool
    {
        $rows = $instance->getAttemptSteps();
        return $rows && (int) end($rows)['id'] === (int) $step->getID();
    }

    /** Этапы, на которые можно вернуть: id этапа => «номер. название». */
    private static function targets(Instance $instance, Step $step): array
    {
        $stage = $step->getStage();
        $out = [];
        foreach ($stage !== null ? (DefinitionSnapshot::targets($stage->fields['return_targets']) ?? []) : [] as $id) {
            $target = DefinitionSnapshot::stage($instance, $id);
            if ($target !== null) { $out[$id] = sprintf('%d. %s', (int) $target->fields['ranking'], $target->fields['name']); }
        }
        return $out;
    }

    /** Журнал маршрута; отметка «запрещено / разрешено» — только у действий не по порядку этапов. */
    private static function violations(Instance $instance): array
    {
        $out = [];
        // Записи «action_…» — журнал обычных действий маршрута; здесь их нет.
        foreach ((new Violation())->find(['plugin_itilflow_instances_id' => $instance->getID(),
            'NOT' => ['action' => ['LIKE', 'action%']]], ['date DESC'], 20) as $row) {
            $row['user_name'] = (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '—';
            $row['out_of_order'] = !in_array((string) $row['action'], ['halt', 'deadline', 'form_cleanup_skipped', 'initiator_answered', 'continue_failed', 'vote_failed', 'vote_caught_up', 'answer_caught_up', 'answer_catch_up_failed'], true);
            $out[] = $row;
        }
        return $out;
    }
}
