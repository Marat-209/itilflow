<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use Glpi\Application\View\TemplateRenderer;
use Group;
use Session;

/**
 * Вкладка маршрута: одна таблица этапов. Итог и действие — в строке своего этапа;
 * человек видит только то, что может сделать сам. Логика переходов — в Engine.
 */
final class Ui
{
    private const OUTCOME = [
        'done' => ['Выполнено', 'bg-green-lt'], 'refused' => ['Отказ', 'bg-red-lt'],
        'clarification' => ['Возврат на уточнение', 'bg-yellow-lt'], 'withdrawn' => ['Отозвано', 'bg-secondary-lt'],
        'aborted' => ['Прервано', 'bg-secondary-lt'], 'skipped' => ['Пропущен', 'bg-yellow-lt'],
        'running' => ['Сейчас', 'bg-blue-lt'], 'waiting_input' => ['Ждём уточнения', 'bg-orange-lt'],
        'pending' => ['Впереди', 'bg-secondary-lt'], 'notrun' => ['Не выполнялся', 'bg-secondary-lt'],
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
        $title = sprintf('Этап %d. %s', (int) $current->fields['ranking'], (string) $current->fields['stage_name']);
        if (Session::getCurrentInterface() === 'central' && Authorizer::stepError($current, 'complete') === null) {
            return ['command' => self::commandFields($instance), 'kind' => 'complete', 'steps_id' => $current->getID(),
                'title' => $title, 'instruction' => (string) $stage->fields['content'],
                'comment' => (string) $stage->fields['completion_comment'], 'is_optional' => (int) $stage->fields['is_optional']];
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
        if (Authorizer::itemError($item, READ) !== null) {
            return;
        }
        $instance = Instance::getForItem($item);
        $child_step = StructuredUi::childStep($item);
        if ($child_step !== null && ($parent = $child_step->getInstance()) !== null) {
            self::render($item, $parent, $child_step);
        }
        if ($instance === null) {
            if ($child_step !== null) { return; }
            $available = [];
            foreach ((new Process())->find([
                'is_active' => 1,
                'itemtype'  => $item->getType(),
            ] + getEntitiesRestrictCriteria(Process::getTable(), '', $item->fields['entities_id'], true)) as $id => $p) {
                if (Authorizer::startError($item, (int) $id) === null) {
                    $available[$id] = $p['name'];
                }
            }
            TemplateRenderer::getInstance()->display('@itilflow/flow_start.html.twig', [
                'item' => $item, 'processes' => $available, 'can_start' => count($available) > 0,
            ]);
            return;
        }
        self::render($item, $instance, null);
    }

    private static function render(CommonITILObject $item, Instance $instance, ?Step $only): void
    {
        if (Authorizer::instanceError($instance, 'read') !== null && $only === null) {
            return;
        }
        $staff = Session::getCurrentInterface() === 'central';
        $admin = $staff && Authorizer::isAdministrator();
        $process = $instance->getProcess();
        $parent = $instance->getItilItem();
        $rows = self::rows($instance, $staff, $admin, $only);

        $header_menu = [];
        if ($only === null && $staff && Authorizer::instanceError($instance, 'abort') === null) {
            $header_menu[] = ['action' => 'abort', 'label' => 'Прервать маршрут…'];
        }
        $can_resume = $only === null && $staff && Authorizer::instanceError($instance, 'resume') === null
            && (!DefinitionSnapshot::enabled($instance) || Attempt::current($instance)?->fields['state'] === Attempt::RUNNING);

        TemplateRenderer::getInstance()->display('@itilflow/flow_tab.html.twig', [
            'command'      => self::commandFields($instance),
            'me'           => (int) Session::getLoginUserID(),
            'step_url'     => \Plugin::getWebDir('itilflow') . '/front/step.form.php',
            'outcome_url'  => \Plugin::getWebDir('itilflow') . '/front/outcome.form.php',
            'is_staff'     => $staff,
            'is_admin'     => $admin,
            'only'         => $only !== null,
            'instance'     => $instance,
            'process_name' => $process ? $process->fields['name'] : '',
            'parent_link'  => $only !== null && $parent !== null && $parent->can($parent->getID(), READ) ? $parent->getLink() : '',
            'enforcement'  => $admin && $process ? (Process::getEnforcementLabels()[$process->fields['enforcement']] ?? '') : '',
            'state'        => self::instanceState($instance, $parent),
            'rows'         => $rows,
            'header_menu'  => $header_menu,
            'can_resume'   => $can_resume,
            'withdraw'     => $only === null && Authorizer::instanceError($instance, 'withdraw') === null,
            'sheet_url'    => $staff && $only === null ? \Plugin::getWebDir('itilflow') . '/front/sheet.php?id=' . $instance->getID() : '',
            'violations'   => $admin && $only === null && Session::haveRight('plugin_itilflow_process', READ) ? self::violations($instance) : [],
        ]);
    }

    /** Общее состояние маршрута одной фразой для человека. */
    private static function instanceState(Instance $instance, ?CommonITILObject $item): array
    {
        $solved = $item !== null && in_array((int) $item->fields['status'],
            array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true);
        $attempt = DefinitionSnapshot::enabled($instance) ? Attempt::current($instance) : null;
        return match (true) {
            $instance->fields['state'] === Instance::DONE => ['Пройден', 'bg-green-lt', $solved ? 'Все этапы пройдены, заявка решена.' : 'Все этапы пройдены.'],
            $instance->fields['state'] === Instance::ABORTED && self::withdrawal($instance) !== null => ['Отозвана', 'bg-secondary-lt', 'Заявка отозвана заявителем.'],
            $instance->fields['state'] === Instance::ABORTED && $attempt?->fields['state'] === Attempt::REFUSED => ['Отказ', 'bg-red-lt', $solved ? 'Отказ — ответ дан решением заявки.' : 'Отказ на одном из этапов.'],
            $instance->fields['state'] === Instance::ABORTED => ['Прерван', 'bg-secondary-lt', 'Маршрут прерван.'],
            $attempt?->fields['state'] === Attempt::WAITING_INPUT => ['Ждём уточнения', 'bg-orange-lt', 'Ждём уточнения от заявителя — ответ в комментарии к этой заявке.'],
            $instance->fields['state'] === Instance::HALTED => ['Приостановлен', 'bg-yellow-lt', 'Маршрут приостановлен; заявку ведёт ответственный за процесс.'],
            default => ['В работе', 'bg-blue-lt', ''],
        };
    }

    /** Отзыв заявителем: запись журнала действий; null — отзыва не было. */
    private static function withdrawal(Instance $instance): ?string
    {
        foreach ((new Violation())->find(['plugin_itilflow_instances_id' => $instance->getID(), 'action' => 'action_withdraw',
            'was_blocked' => 0], ['id DESC'], 1) as $row) {
            $msg = json_decode((string) $row['message'], true);
            return is_array($msg) ? trim((string) ($msg['reason'] ?? '')) : '';
        }
        return null;
    }

    private static function rows(Instance $instance, bool $staff, bool $admin, ?Step $only): array
    {
        $structured = DefinitionSnapshot::enabled($instance);
        $attempt = $structured ? Attempt::current($instance) : null;
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
            if ($only !== null && (int) $latest['id'] !== (int) $only->getID()) {
                continue;
            }
            $step = new Step();
            if (!$step->getFromDB((int) $latest['id'])) { continue; }
            $is_current = $current !== null && (int) $current->getID() === (int) $latest['id'];
            $result = self::result($instance, $step, $latest, $staff, [
                'current' => $is_current, 'waiting' => $waiting_target === (int) $stage_id && $latest['state'] === Step::PENDING,
                'withdrawn' => $withdrawn, 'abort_source' => $abort_source,
            ]);
            $history = [];
            foreach (array_slice($passes, 0, -1) as $old) {
                $os = new Step();
                if ($os->getFromDB((int) $old['id'])) {
                    $history[] = ['try' => (int) $old['pass_number']] + self::result($instance, $os, $old, $staff,
                        ['current' => false, 'waiting' => false, 'withdrawn' => null, 'abort_source' => $abort_source]);
                }
            }
            $mode = self::MODE[$latest['execution_mode']] ?? [$latest['execution_mode'], 'bg-secondary-lt'];
            $duration = $structured ? Step::elapsedSeconds($latest) : (int) $latest['duration'];
            [$actions, $menu] = $only !== null && !$is_current ? [[], []] : self::actions($instance, $step, $staff, $admin, $is_current,
                $waiting_target === (int) $stage_id && $latest['state'] === Step::PENDING);
            if ($result['kind'] === 'running' && array_intersect(array_column($actions, 'action'), ['outcome', 'visa', 'set_approver', 'complete'])) {
                $result['summary'] = 'Ждём вашего действия — кнопка в этой строке.';
            }
            $out[] = [
                'rank' => (int) $latest['ranking'], 'step_id' => (int) $latest['id'], 'name' => (string) $latest['stage_name'],
                'mode_label' => $mode[0], 'mode_class' => $mode[1],
                'owner' => self::owner($step, $latest), 'artifact' => $staff && $only === null ? self::artifactLink($step, $latest) : '',
                'current' => $is_current || $result['kind'] === 'waiting_input',
                'try' => count($history) > 0 ? (int) $latest['pass_number'] : 0,
                'result' => $result, 'history' => $history,
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

    /** Итог строки: метка, одна строка результата, автор. */
    private static function result(Instance $instance, Step $step, array $row, bool $staff, array $ctx): array
    {
        $kind = 'pending'; $summary = ''; $author = 0; $date = ''; $internal = '';
        $outcome = DefinitionSnapshot::enabled($instance) ? Outcome::forStep((int) $row['id']) : null;
        if ($outcome !== null) {
            $kind = match ($outcome->fields['outcome']) { Outcome::COMPLETED => 'done', Outcome::REFUSED => 'refused', default => 'clarification' };
            $summary = trim((string) $outcome->fields['public_summary']);
            $author = (int) $outcome->fields['users_id'];
            $date = (string) $outcome->fields['date_creation'];
            $reason = trim((string) $outcome->fields['reason']);
            $internal = $staff && $reason !== '' && $reason !== $summary
                ? ($outcome->fields['source_kind'] === 'native_validation' ? 'Комментарий согласующего: ' : 'Внутренний комментарий: ') . $reason : '';
            if ($kind === 'done' && $row['state'] !== Step::DONE) { $kind = $row['state'] === Step::RUNNING ? 'running' : 'notrun'; }
        } else {
            switch ($row['state']) {
                case Step::DONE: $kind = 'done'; break;
                case Step::SKIPPED: $kind = 'skipped'; break;
                case Step::REJECTED: $kind = 'refused'; break;
                case Step::RUNNING: $kind = 'running'; break;
                case Step::SUPERSEDED:
                    $kind = $ctx['withdrawn'] !== null && (int) $row['id'] === $ctx['abort_source'] ? 'withdrawn'
                        : ((int) $row['id'] === $ctx['abort_source'] && $instance->fields['state'] === Instance::ABORTED ? 'aborted' : 'notrun');
                    break;
                default:
                    $kind = $ctx['waiting'] ? 'waiting_input'
                        : (in_array($instance->fields['state'], [Instance::DONE, Instance::ABORTED], true) ? 'notrun' : 'pending');
            }
            if (in_array($kind, ['done', 'skipped', 'refused'], true)) {
                $summary = trim(strip_tags((string) ($row['comment'] ?? '')));
                $author = (int) ($row['users_id_actual'] ?? 0);
                $date = (string) ($row['date_end'] ?? '');
            }
            if ($kind === 'withdrawn') { $summary = (string) $ctx['withdrawn']; }
        }
        if ($kind === 'running') { $summary = self::waitingText($step, $row, $staff); }
        if ($kind === 'waiting_input') {
            $summary = $staff ? 'Заявитель отвечает комментарием в этой заявке; затем исполнитель продолжает маршрут.'
                : 'Ответьте, пожалуйста, комментарием в этой заявке — маршрут продолжится после вашего уточнения.';
        }
        [$label, $class] = self::OUTCOME[$kind];
        return ['kind' => $kind, 'label' => $label, 'class' => $class, 'summary' => $summary, 'internal' => $internal,
            'author' => $author > 0 ? getUserName($author) : '', 'date' => $date !== '' ? \Html::convDateTime($date) : ''];
    }

    /** Чего ждём на текущем этапе — простыми словами. */
    private static function waitingText(Step $step, array $row, bool $staff): string
    {
        if ($row['execution_mode'] === Stage::MODE_APPROVAL) {
            if ($step->awaitsApprover()) {
                return 'Ждём: исполнитель заявки укажет согласующего.';
            }
            $names = [];
            foreach (self::validations($step) as $v) {
                if ((int) $v['status'] === \CommonITILValidation::WAITING) { $names[] = self::targetName($v); }
            }
            return $names ? 'Ждём согласования: ' . implode(', ', array_unique($names)) . '.' : 'Ждём ответа согласующего.';
        }
        $owner = self::owner($step, $row);
        return $row['execution_mode'] === Stage::MODE_DELEGATED
            ? 'Ждём результата отдела: ' . $owner . '.'
            : 'Ждём: ' . $owner . '.';
    }

    private static function owner(Step $step, array $row): string
    {
        if ($row['execution_mode'] === Stage::MODE_APPROVAL) {
            $names = array_map([self::class, 'targetName'], self::validations($step));
            if ($names) { return implode(', ', array_unique($names)); }
            if (($step->getStage()?->fields['approver_source'] ?? '') === Stage::APPROVER_RUNTIME) { return 'Согласующий по заявке'; }
        }
        if ((int) $row['groups_id'] > 0) {
            $g = new Group();
            return $g->getFromDB((int) $row['groups_id']) ? (string) $g->fields['name'] : '—';
        }
        return (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '—';
    }

    /** Визы этапа без строгих проверок — только для показа. */
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
            ? sprintf('<a href="%s" title="%s">заявка отдела #%d</a>', htmlescape($a->getLinkURL()), htmlescape((string) $a->fields['name']), $aid) : '';
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
        if ($current && $instance->fields['state'] === Instance::RUNNING) {
            if ($structured && $step->fields['execution_mode'] === Stage::MODE_DELEGATED
                && Outcome::forStep($step->getID()) === null && StructuredAccess::answerError($step) === null) {
                $acts[] = ['action' => 'outcome', 'label' => 'Отметить результат',
                    'can_return' => count($targets) > 0 && StructuredAccess::answerError($step, true) === null, 'targets' => $targets];
            }
            if ($step->fields['execution_mode'] === Stage::MODE_APPROVAL && !$step->awaitsApprover()) {
                foreach (self::validations($step) as $v) {
                    $vo = new ($step->fields['artifact_itemtype'])();
                    if ((int) $v['status'] === \CommonITILValidation::WAITING && $vo->getFromDB((int) $v['id'])
                        && ValidationAuthority::canAnswer($vo) && $vo->can($vo->getID(), UPDATE)) {
                        $acts[] = ['action' => 'visa', 'label' => 'Согласовать или отказать', 'validation_id' => (int) $v['id'],
                            'validation_url' => $vo::getFormURL(),
                            'can_return' => $structured && count($targets) > 0 && StructuredAccess::answerError($step, true) === null,
                            'targets' => $targets];
                        break;
                    }
                }
            }
            if ($staff && Authorizer::stepError($step, 'set_approver') === null) {
                $acts[] = ['action' => 'set_approver', 'label' => 'Указать согласующего'];
            }
            if ($staff && Authorizer::stepError($step, 'complete') === null) {
                $stage = $step->getStage();
                $acts[] = ['action' => 'complete', 'label' => 'Завершить этап',
                    'comment' => (string) ($stage?->fields['completion_comment'] ?? 'optional'), 'optional' => (int) ($stage?->fields['is_optional'] ?? 0)];
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
                        $menu[] = ['action' => 'reconcile_outcome', 'label' => 'Сверить ответ согласующего',
                            'validation_id' => (int) $v['id'], 'validation_type' => (string) $step->fields['artifact_itemtype']];
                        break;
                    }
                }
            }
        }
        if ($waiting && $structured && $staff && StructuredAccess::activateError($instance) === null) {
            $acts[] = ['action' => 'activate', 'label' => 'Продолжить после уточнения'];
        }
        if ($structured && ($outcome = Outcome::forStep($step->getID())) !== null && !empty($outcome->fields['explanation_pending'])
            && StructuredAccess::explanationError($outcome, $step) === null) {
            $positive = $outcome->fields['outcome'] === Outcome::COMPLETED;
            if (!$positive || Engine::canPublishPositive($outcome, $step)) {
                $acts[] = ['action' => $positive ? 'publish_positive_result' : 'explain_outcome', 'label' => 'Пояснить заявителю',
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
                $menu[] = ['action' => 'new_attempt', 'label' => 'Новая попытка…', 'targets' => $targets];
            }
        }
        return [$acts, $menu];
    }

    /** Длительность коротко и по-русски, независимо от языка учётки. */
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

    /** Разрешённые точки возврата этапа: id этапа => имя. */
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

    private static function violations(Instance $instance): array
    {
        $out = [];
        // Записи «action_…» — журнал обычных действий маршрута; здесь только нарушения порядка.
        foreach ((new Violation())->find(['plugin_itilflow_instances_id' => $instance->getID(),
            'NOT' => ['action' => ['LIKE', 'action%']]], ['date DESC'], 20) as $row) {
            $row['user_name'] = (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '—';
            $out[] = $row;
        }
        return $out;
    }
}
