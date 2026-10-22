<?php

namespace GlpiPlugin\Itilflow;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** Только представление: схема не меняет маршрут и не вычисляет условия. */
final class Scheme extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return $item instanceof Process && $item->can($item->getID(), READ)
            ? self::createTabEntry('Схема') : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Process || !$item->can($item->getID(), READ)) {
            return false;
        }
        $stages = $item->getStages();
        $options = ['actor_labels' => self::actors($stages), 'urls' => [], 'problems' => [],
            'day_minutes' => Deadline::dayMinutes((int) ($item->fields['calendars_id'] ?? 0)),
            'subtitle' => 'Версия ' . (int) ($item->fields['version'] ?? 0)];
        foreach ($stages as $s) {
            if ($item->can($item->getID(), UPDATE)) {
                $options['urls'][(int) $s['id']] = Stage::getFormURL() . '?id=' . (int) $s['id'];
            }
        }
        $readiness = Readiness::check($item);
        foreach ($readiness['problems'] as $problem) {
            $options['problems'][(int) ($problem['stage'] ?? 0)][] = $problem['text'];
        }
        $model = self::build($item->fields, $stages, $options);
        $model['notices'] = array_merge($model['notices'], $options['problems'][0] ?? []);
        TemplateRenderer::getInstance()->display('@itilflow/scheme.html.twig', ['scheme' => $model]);
        return true;
    }

    /** Проверяется и у кнопки, и при прямом открытии адреса. */
    public static function canViewInstance(Instance $instance): bool
    {
        if (!Authorizer::isAdministrator()
            || ($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central'
            || Authorizer::instanceError($instance, 'read') !== null) {
            return false;
        }
        $item = $instance->getItilItem();
        return $item !== null;
    }

    public static function forInstance(Instance $instance, array $uiRows = []): array
    {
        if (!self::canViewInstance($instance)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $snapshot = DefinitionSnapshot::load($instance);
        if ($snapshot === null) {
            $model = self::build(['name' => 'Схема маршрута'], []);
            $model['notices'][] = 'Не удалось прочитать настройки, с которыми начался маршрут. Обратитесь к администратору маршрутов.';
            return $model;
        }
        $states = [];
        $stepsById = [];
        $history = [];
        // Последний проход каждого этапа сохраняет выполненный префикс прежней попытки.
        foreach ($instance->getSteps() as $step) {
            $stepsById[(int) $step['id']] = $step;

            $id = (int) $step['plugin_itilflow_stages_id'];
            $state = (string) $step['state'];
            $label = self::stateLabel($state);
            // Этап согласования: «Согласовано», как в строке этапа и в листе (Sheet::resultLabel).
            if ($state === Step::DONE && ($step['execution_mode'] ?? '') === Stage::MODE_APPROVAL) {
                $label = Sheet::resultLabel($step, Outcome::COMPLETED, false, Outcome::forStep((int) $step['id'])?->fields['source_kind'] ?? null);
            }
            $states[$id] = ['state' => $state, 'label' => $label . ', попытка ' . (int) $step['pass_number'],
                'is_current' => $state === 'running'
                    && $id === (int) $instance->fields['plugin_itilflow_stages_id_current']];
        }
        $urls = [];
        foreach ($uiRows as $r) {
            foreach ($snapshot['stages'] as $stage) {
                if ((int) $stage['ranking'] !== (int) $r['rank']) { continue; }
                $id = (int) $stage['id'];
                $states[$id] = ['state' => $r['result']['kind'],
                    // «Сейчас» у текущего этапа карточка пишет сама («▶Сейчас ·») — без повтора.
                    'label' => self::sentences([$r['current'] && $r['result']['label'] === Terms::NOW
                            ? ((int) $r['try'] > 0 ? 'Попытка ' . (int) $r['try'] : '')
                            : $r['result']['label'] . ((int) $r['try'] > 0 ? ', попытка ' . (int) $r['try'] : ''),
                        StepLine::withoutRepeat($r['result']['label'], $r['result']['summary']),
                        $r['result']['date'] !== '' ? 'Дата: ' . $r['result']['date'] : '',
                        $r['due'] !== '' ? 'Срок: ' . $r['due'] : '', $r['overdue'] ? 'Просрочен' : '']),
                    'is_current' => $r['current']];
                $urls[$id] = '#itilflow-row-' . (int) $r['step_id'];
            }
        }
        foreach ((new Attempt())->find(['plugin_itilflow_instances_id' => $instance->getID()], ['number ASC']) as $a) {
            $source = $stepsById[(int) $a['source_step_id']] ?? null;
            if ($source !== null && (int) $a['target_stage_id'] > 0) {
                $history[] = ['from_id' => (int) $source['plugin_itilflow_stages_id'],
                    'to_id' => (int) $a['target_stage_id'], 'label' => 'Выполненный возврат: попытка ' . (int) $a['number']];
            }
        }
        $model = self::build($snapshot['process'], $snapshot['stages'], [
            'states' => $states, 'urls' => $urls, 'runtime' => true, 'history' => $history,
            'actor_labels' => self::actors($snapshot['stages']),
            // Срок этапа — как в настройке («3 р. д.»), по календарю маршрута запуска.
            'day_minutes' => Deadline::dayMinutes((int) ($snapshot['process']['calendars_id'] ?? 0)),
            'subtitle' => !empty($instance->fields['date_start']) ? 'Начат ' . \Html::convDateTime((string) $instance->fields['date_start']) : '',
        ]);
        $currentProcess = new Process();
        if ($currentProcess->getFromDB((int) $instance->fields['plugin_itilflow_processes_id'])
            && (int) $currentProcess->fields['version'] !== (int) $instance->fields['process_version']) {
            $model['notices'][] = 'Показана версия на момент запуска.';
        }
        return $model;
    }

    private static function stateLabel(string $state): string
    {
        return ['done' => 'Выполнено', 'skipped' => 'Пропущен', 'not_needed' => 'Не нужен по условию',
            'rejected' => 'Отклонено', 'running' => 'В работе', 'pending' => 'Впереди',
            'superseded' => 'Прежняя попытка', 'waiting_input' => 'Ждём ответа инициатора'][$state]
            ?? 'Состояние не распознано';
    }

    /** Состав берётся из переданной версии этапов; подписи объектов — общий словарь GLPI. */
    public static function actors(array $stages, ?\Closure $name = null): array
    {
        $name ??= ConditionText::name(...);
        $out = [];
        foreach ($stages as $s) {
            $labels = [];
            if (($s['execution_mode'] ?? '') === 'approval') {
                $selection = DefinitionSnapshot::approvers($s);
                if ($selection === null) {
                    $out[(int) $s['id']] = 'Проверьте список согласующих в настройке этапа';
                    continue;
                }
                // Старый смешанный выбор исполнялся как группа; схема сохраняет это правило.
                $groups = $selection['groups'];
                $users = $selection['legacy'] && $groups ? [] : $selection['users'];
            } else {
                $groups = (int) ($s['groups_id'] ?? 0) > 0 ? [(int) $s['groups_id']] : [];
                $users = (int) ($s['users_id'] ?? 0) > 0 ? [(int) $s['users_id']] : [];
            }
            foreach (array_unique($groups) as $gid) { $labels[] = 'Группа «' . $name('Group', $gid) . '»'; }
            foreach (array_unique($users) as $uid) { $labels[] = 'Пользователь «' . $name('User', $uid) . '»'; }
            if ($groups && isset($selection)) {
                $labels[] = $selection['mode'] === 'each' ? 'каждый в группе' : 'любой из группы';
            }
            if ($labels) { $out[(int) $s['id']] = implode('; ', $labels); }
            unset($selection);
        }
        return $out;
    }

    /**
     * Чистая модель: геометрия только из индексов строк, без запросов и вычисления условий.
     * options.conditions[id] = {supported: true, text: string} — пересказ от будущего
     * общего читателя условий; только подтверждённый формат даёт обход при ЛОЖЬ.
     * Здесь нельзя передавать сырой JSON как text или превращать UNKNOWN в ЛОЖЬ.
     * options.history = [{from_id, to_id, label}] — уже выполненные возвраты.
     * day_minutes задаётся только когда известна длительность рабочего дня;
     * у снимка схемы 1 её нет, поэтому исторические сроки остаются в часах/минутах.
     */
    public static function build(array $process, array $stages, array $options = []): array
    {
        usort($stages, static fn(array $a, array $b): int => [(int) ($a['ranking'] ?? 0), (int) ($a['id'] ?? 0)]
            <=> [(int) ($b['ranking'] ?? 0), (int) ($b['id'] ?? 0)]);
        $model = ['title' => (string) ($process['name'] ?? 'Схема маршрута'),
            'subtitle' => (string) ($options['subtitle'] ?? ''), 'summary' => [], 'rows' => [],
            'edges' => [], 'left_lanes' => 0, 'right_lanes' => 0, 'hidden_edges' => 0, 'notices' => []];
        $indices = [];
        foreach ($stages as $i => $s) { $indices[(int) ($s['id'] ?? 0)] = $i; }
        $structured = ($process['outcome_contract'] ?? '') === 'structured_v1';
        $rules = [];
        $edges = [];
        $knownConditions = [];
        foreach ($stages as $i => $s) {
            $id = (int) ($s['id'] ?? 0);
            $mode = (string) ($s['execution_mode'] ?? '');
            $problems = $options['problems'][$id] ?? [];
            $next = isset($stages[$i + 1]) ? 'этап ' . (int) $stages[$i + 1]['ranking'] : 'маршрут завершён';
            $text = 'После выполнения: ' . $next . '.';
            $rule = '';
            if ($mode !== 'inline') {
                $policy = (string) ($s[$structured ? 'negative_policy' : 'on_reject'] ?? '');
                $rule = match ($policy) {
                    'abort' => $structured ? 'При отказе: отказ инициатору заявки.' : 'При отказе: прервать маршрут.',
                    'block' => 'При отказе: остановить маршрут и ждать решения.',
                    'back' => 'При отказе: вернуть на этап ' . (isset($indices[(int) ($s['plugin_itilflow_stages_id_reject'] ?? 0)])
                        ? (int) $stages[$indices[(int) $s['plugin_itilflow_stages_id_reject']]]['ranking'] : '?') . '.',
                    default => 'Правило отказа не распознано.',
                };
                if (!$structured && $policy === 'back') {
                    self::edge($edges, $indices, $i, (int) ($s['plugin_itilflow_stages_id_reject'] ?? 0), 'reject', $rule, $problems);
                }
                if ($structured) {
                    $targets = $s['return_targets'] ?? '[]';
                    $targets = is_string($targets) ? json_decode($targets, true) : null;
                    if (!is_array($targets) || !array_is_list($targets)) {
                        $problems[] = 'Настройки этапов для возврата повреждены.';
                        $targets = [];
                    }
                    $names = [];
                    foreach ($targets as $target) {
                        if (!is_int($target) || !isset($indices[$target]) || $indices[$target] > $i) {
                            $problems[] = 'Этап для возврата недоступен.'; continue;
                        }
                        $names[] = $target === $id ? 'этот же этап' : 'этап ' . (int) $stages[$indices[$target]]['ranking'];
                        self::edge($edges, $indices, $i, $target, 'return', 'На уточнение', $problems);
                    }
                    $rule .= ' Уточнение: ' . ($names ? implode(', ', $names) : 'возврат не предусмотрен') . '.';
                }
                $text .= ' ' . $rule;
                $rules[$rule] = ($rules[$rule] ?? 0) + 1;
            }
            // Формат будущего условия не угадывается и не исполняется этим представлением.
            $presentation = $options['conditions'][$id] ?? null;
            $branch = BranchConfiguration::decode($s['run_if'] ?? null);
            if ($branch !== null && $branch !== [] && !Condition::validate($branch, array_map('intval', array_keys($indices)))) {
                $presentation = ['supported' => true, 'text' => ConditionText::short($branch, $stages)];
            }
            $knownCondition = is_array($presentation) && ($presentation['supported'] ?? false) === true
                && is_string($presentation['text'] ?? null) && trim($presentation['text']) !== '';
            // Карточка и обход используют один проверенный пересказ, включая штатный run_if схемы 3.
            $knownConditions[$id] = $knownCondition;
            $conditional = $knownCondition || isset($s['run_if']) && $s['run_if'] !== '' && $s['run_if'] !== null && $s['run_if'] !== [];
            $condition = $knownCondition ? $presentation['text']
                : ($conditional ? 'Условие не читается — откройте этап.' : '');
            if ($knownCondition) {
                $text .= ' Если условие не выполнено: «Не нужен по условию», далее ' . $next . '.';

            }
            $actor = (string) ($options['actor_labels'][$id] ?? '');
            if (($s['approver_source'] ?? '') === 'runtime') { $actor = 'Указывает исполнитель заявки'; }
            if (($s['approver_source'] ?? '') === 'form') { $actor = 'Согласующие из формы'; }
            if ($actor === '') {
                $actor = 'Исполнитель не указан';
            }
            $meta = [];
            if (!empty($s['is_optional']) && $mode === 'inline') { $meta[] = 'Необязательная задача'; }
            if ($mode === 'approval' || ($s['department_result_source'] ?? '') === 'native_validation') {
                $meta[] = 'Нужно одобрений: ' . (int) ($s['approval_percent'] ?? 100) . ' %';
            }
            if (!empty($s['decision_items'])) {
                $decisions = BranchConfiguration::decisionItems($s['decision_items']);
                $meta[] = $decisions === null ? 'Вопросы этапа: проверьте настройки'
                    : 'Вопросы этапа: ' . implode('; ', array_column($decisions, 'label'));
            }
            if (($s['approver_source'] ?? '') === 'form') { $meta[] = 'Согласование из формы'; }
            $url = $options['urls'][$id] ?? null;
            if (!is_string($url) || !(str_starts_with($url, '/') || preg_match('/^#itilflow-row-[1-9][0-9]*$/D', $url)) || str_starts_with($url, '//')
                || str_contains($url, '\\') || preg_match('/[\x00-\x20]/', $url)) { $url = null; }
            $state = $options['states'][$id] ?? [];
            $minutes = max(0, (int) ($s['deadline_minutes'] ?? 0));
            $model['rows'][] = ['id' => $id, 'ranking' => (int) ($s['ranking'] ?? 0), 'name' => (string) ($s['name'] ?? ''),
                'mode' => $mode, 'mode_label' => ['inline' => 'Задача', 'approval' => 'Согласование', 'delegated' => 'Дочерняя заявка'][$mode] ?? 'Неизвестный тип этапа',
                'actor_label' => $actor, 'state' => (string) ($state['state'] ?? ''), 'state_label' => (string) ($state['label'] ?? ''),
                'is_current' => !empty($state['is_current']), 'is_conditional' => $conditional, 'condition_text' => $condition,
                'transition_text' => $text, 'exception_text' => $rule, 'deadline_label' => self::deadlineLabel($minutes, (int) ($options['day_minutes'] ?? 0)),
                'meta' => $meta, 'problems' => array_values(array_unique($problems)), 'url' => $url];
        }
        // Последовательность условных этапов имеет один общий обход «все ложны».
        for ($i = 0; $i < count($stages); $i++) {
            if (!($knownConditions[(int) $stages[$i]['id']] ?? false)) { continue; }
            $start = $i;
            while (isset($stages[$i + 1])) {
                if (!($knownConditions[(int) $stages[$i + 1]['id']] ?? false)) { break; }
                $i++;
            }
            if ($start > 0 && isset($stages[$i + 1])) {
                $edges[] = ['from_index' => $start - 1, 'to_index' => $i + 1, 'side' => 'right', 'kind' => 'bypass',
                    'label' => 'Если этапы ' . (int) $stages[$start]['ranking'] . '–' . (int) $stages[$i]['ranking'] . ' не нужны'];
            }
        }
        if ($rules) {
            arsort($rules);
            $common = (string) array_key_first($rules);
            if ($rules[$common] > 1) {
                $model['summary'][] = 'Общее правило для ' . $rules[$common] . ' этапов: ' . $common;
                foreach ($model['rows'] as &$row) { if ($row['exception_text'] === $common) { $row['exception_text'] = ''; } }
                unset($row);
            }
        }
        if (!empty($options['runtime'])) {
            $edges = array_values(array_filter($edges, static function (array $edge) use ($model): bool {
                $row = $model['rows'][$edge['from_index']];
                return $row['is_current'] || in_array($row['state'], ['', 'pending', 'waiting_input'], true);
            }));
        }
        foreach ($options['history'] ?? [] as $h) {
            if (!isset($indices[$h['from_id']], $indices[$h['to_id']])) { continue; }
            $from = $indices[$h['from_id']]; $to = $indices[$h['to_id']];
            $label = (string) $h['label'] . ' → этап ' . $model['rows'][$to]['ranking'];
            $model['rows'][$from]['transition_text'] .= ' ' . $label . '.';
            if ($from !== $to) {
                $edges[] = ['from_index' => $from, 'to_index' => $to, 'side' => 'left', 'kind' => 'history', 'label' => $label];
            }
        }
        // Общая цель слева / начало справа объединяют дуги одной семантики.
        $buses = [];
        foreach ($edges as $edge) {
            $key = $edge['side'] . ':' . $edge['kind'] . ':'
                . ($edge['side'] === 'left' ? $edge['to_index'] : $edge['from_index']);
            $lo = min($edge['from_index'], $edge['to_index']);
            $hi = max($edge['from_index'], $edge['to_index']);
            if (!isset($buses[$key])) {
                $buses[$key] = ['lo' => $lo, 'hi' => $hi, 'side' => $edge['side'], 'edges' => []];
            }
            $buses[$key]['lo'] = min($buses[$key]['lo'], $lo);
            $buses[$key]['hi'] = max($buses[$key]['hi'], $hi);
            $buses[$key]['edges'][] = $edge;
        }
        usort($buses, static fn(array $a, array $b): int => [$a['hi'] - $a['lo'], $a['lo'], $a['hi']]
            <=> [$b['hi'] - $b['lo'], $b['lo'], $b['hi']]);
        $lanes = ['left' => [], 'right' => []];
        foreach ($buses as $bus) {
            for ($lane = 0; $lane < 4; $lane++) {
                $free = true;
                foreach ($lanes[$bus['side']][$lane] ?? [] as [$a, $b]) {
                    if ($bus['lo'] <= $b && $bus['hi'] >= $a) { $free = false; break; }
                }
                if (!$free) { continue; }
                $lanes[$bus['side']][$lane][] = [$bus['lo'], $bus['hi']];
                foreach ($bus['edges'] as $edge) { $edge['lane'] = $lane; $model['edges'][] = $edge; }
                $model[$bus['side'] . '_lanes'] = max($model[$bus['side'] . '_lanes'], $lane + 1);
                break;
            }
            if ($lane === 4) { $model['hidden_edges'] += count($bus['edges']); }
        }
        if (!$stages) { $model['notices'][] = 'Этапы отсутствуют.'; }
        return $model;
    }

    /** Части подписи карточки — предложениями через точку, без пустых и без «..» на стыке. */
    public static function sentences(array $parts): string
    {
        $parts = array_values(array_filter(array_map(static fn($p): string => rtrim(trim((string) $p), '. '), $parts),
            static fn(string $p): bool => $p !== ''));
        return $parts === [] ? '' : implode('. ', $parts) . '.';
    }

    /** Без сохранённой длительности рабочего дня нельзя пересчитать историю в дни. */
    private static function deadlineLabel(int $minutes, int $day): string
    {
        if ($minutes <= 0) { return 'Без срока'; }
        if ($day > 0) { return Deadline::label($minutes, $day); }
        return $minutes % 60 === 0 ? intdiv($minutes, 60) . ' ч' : $minutes . ' мин';
    }

    private static function edge(array &$edges, array $indices, int $from, int $target, string $kind, string $label, array &$problems): void
    {
        if (!isset($indices[$target]) || $indices[$target] > $from) { $problems[] = 'Этап для возврата недоступен.'; return; }
        if ($indices[$target] === $from) { return; }
        $edges[] = ['from_index' => $from, 'to_index' => $indices[$target], 'side' => 'left', 'kind' => $kind, 'label' => $label];
    }
}
