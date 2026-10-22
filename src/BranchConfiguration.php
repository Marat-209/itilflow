<?php

namespace GlpiPlugin\Itilflow;

/** Настройка ветвлений: один серверный контракт для формы, снимка и переноса. */
final class BranchConfiguration
{
    /** Имя полей редактора «Когда выполнять» на форме этапа (ConditionEditor, prefix). */
    public const EDITOR = 'run_if_editor';

    public static function decode(mixed $value): ?array
    {
        if ($value === null || $value === '') { return []; }
        if (is_array($value)) { return $value; }
        if (!is_string($value)) { return null; }
        try { $data = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { return null; }
        return is_array($data) ? $data : null;
    }

    /** Стабильный ключ сохраняется при изменении подписи, но не угадывается по ней. */
    public static function decisionItems(mixed $raw): ?array
    {
        $items = self::decode($raw);
        if ($items === null || !array_is_list($items) || count($items) > 4) { return null; }
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['key', 'label'])
                || !is_string($item['key'] ?? null) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $item['key'])
                || isset($seen[$item['key']]) || !is_string($item['label'] ?? null)
                || trim($item['label']) === '' || mb_strlen($item['label']) > 255) { return null; }
            $seen[$item['key']] = true;
        }
        return $items;
    }

    /** У этапа «Согласование из формы» условия нет: запрос уходит при отправке формы. */
    public static function formApproval(array $stage): bool
    {
        return ($stage['execution_mode'] ?? '') === Stage::MODE_APPROVAL && ($stage['approver_source'] ?? '') === Stage::APPROVER_FORM;
    }

    public static function validateSnapshotStage(array $stage, array $stages, array $process): array
    {
        $errors = [];
        $condition = self::decode($stage['run_if'] ?? null);
        if ($condition === null) { return ['Условие повреждено — задайте заново.']; }
        if (!empty($condition['clauses']) && self::formApproval($stage)) {
            $errors[] = 'У этапа «Согласование из формы» условие не допускается: согласование уже запросила форма.';
        }
        if (self::decisionItems($stage['decision_items'] ?? null) === null) { $errors[] = 'Вопросов этапа — не больше четырёх, без повторов.'; }
        if (!in_array($stage['unknown_policy'] ?? 'run', ['run', 'ask'], true)) { $errors[] = 'Выберите, что делать, если ответа нет.'; }
        $prior = []; $byId = [];
        foreach ($stages as $other) {
            $byId[(int) $other['id']] = $other;
            if ((int) $other['ranking'] < (int) $stage['ranking']) { $prior[(int) $other['id']] = $other; }
        }
        // Ссылки на этапы — с названием этапа: «ссылается на этап 30 «…», а он идёт ниже».
        $refs = [];
        foreach (is_array($condition['clauses'] ?? null) ? $condition['clauses'] : [] as $i => $clause) {
            if (!is_array($clause) || !in_array($clause['source'] ?? '', ['decision', 'stage_not_needed'], true)) { continue; }
            $sid = $clause['stage_id'] ?? null;
            $pre = 'Условие ' . ((int) $i + 1) . ': ';
            if (!is_int($sid) || !isset($byId[$sid])) { $refs[] = $pre . 'ссылается на удалённый этап. Выберите заново.'; continue; }
            if (!isset($prior[$sid])) {
                $refs[] = $pre . 'ссылается на этап ' . self::title($byId[$sid]) . ', а он идёт ниже. Можно только на этапы выше.';
                continue;
            }
            if (($clause['source'] ?? '') === 'decision') {
                $items = self::decisionItems($prior[$sid]['decision_items'] ?? null) ?? [];
                if (!in_array($clause['key'] ?? '', array_column($items, 'key'), true)) {
                    $refs[] = $pre . 'у этапа ' . self::title($prior[$sid]) . ' больше нет вопроса, на который ссылается условие.';
                }
            }
        }
        $model = Condition::validate($condition, array_keys($prior));
        if ($refs) { $model = array_values(array_filter($model, static fn(string $e): bool => !str_contains($e, 'этапы выше'))); }
        $errors = array_merge($errors, $refs, $model);
        if ($errors) { return array_values(array_unique($errors)); }
        foreach ($condition['clauses'] ?? [] as $clause) {
            if (($clause['source'] ?? '') === 'form_answer' && (int) ($process['forms_forms_id'] ?? 0) <= 0) {
                $errors[] = 'Чтобы условия видели ответы формы, выберите форму маршрута.';
            }
            if (($clause['source'] ?? '') === 'form_answer' && ($clause['question']['type'] ?? '') === 'ref'
                && in_array($clause['operator'] ?? '', ['eq', 'ne'], true)
                && ($clause['question']['itemtype'] ?? null) !== ($clause['value']['itemtype'] ?? null)) {
                $errors[] = 'Значение условия не подходит к вопросу формы — выберите заново.';
            }
        }
        return array_values(array_unique($errors));
    }

    /** При сохранении сверяются и другие этапы: перестановка или удаление ключа не ломает ссылки молча. */
    public static function validateRoute(array $process, array $stages): array
    {
        $errors = [];
        foreach ($stages as $stage) {
            foreach (self::validateSnapshotStage($stage, $stages, $process) as $error) {
                $errors[] = self::title($stage, 'Этап ') . ': ' . $error;
            }
        }
        return array_values(array_unique($errors));
    }

    /** «30 «Выдача»»; с $prefix — «Этап 30 «Выдача»». */
    private static function title(array $stage, string $prefix = ''): string
    {
        $name = trim((string) ($stage['name'] ?? ''));
        return $prefix . (int) ($stage['ranking'] ?? 0) . ($name !== '' ? ' «' . $name . '»' : '');
    }

    /**
     * Данные блоков «Когда выполнять» и «Вопросы этапа» формы этапа.
     * editor — ConditionEditor::view; unknown_possible — есть ли условия, где ответа может не быть.
     */
    public static function editor(array $process, array $stage, array $stages): array
    {
        $condition = self::decode($stage['run_if'] ?? null) ?? [];
        // У «Согласования из формы» условия нет: редактор скрыт и отправляет «Всегда».
        if (self::formApproval($stage)) { $condition = []; }
        $mode = ($condition['clauses'] ?? []) ? ConditionEditor::MODE_IF : ConditionEditor::MODE_ALWAYS;
        $view = ConditionEditor::view(ConditionEditor::SCOPE_STAGE, self::EDITOR, $mode, $condition, $process, $stage, $stages);
        // На какие ответы этого этапа опираются этапы ниже: «На этот ответ опирается этап 25».
        $refs = [];
        foreach ($stages as $other) {
            if ((int) ($stage['id'] ?? 0) <= 0 || (int) ($other['id'] ?? 0) === (int) $stage['id']) { continue; }
            foreach ((self::decode($other['run_if'] ?? null) ?? [])['clauses'] ?? [] as $c) {
                if (is_array($c) && ($c['source'] ?? '') === 'decision' && (int) ($c['stage_id'] ?? 0) === (int) $stage['id']) {
                    $refs[(string) ($c['key'] ?? '')][] = (int) $other['ranking'];
                }
            }
        }
        $refs = array_map(static fn(array $r): string => implode(', ', array_unique($r)), $refs);
        return ['editor' => $view, 'refs' => $refs,
            'items' => self::decisionItems($stage['decision_items'] ?? null) ?? [],
            'unknown_policy' => $stage['unknown_policy'] ?? 'run',
            'unknown_possible' => self::unknownPossible($condition),
            'form_approval' => self::formApproval($stage),
            'has_form' => ConditionEditor::hasForm($process)];
    }

    /** «Если ответа нет» нужно, только когда в условиях есть ответы формы или вопросы этапов. */
    public static function unknownPossible(array $condition): bool
    {
        foreach (is_array($condition['clauses'] ?? null) ? $condition['clauses'] : [] as $c) {
            if (is_array($c) && in_array($c['source'] ?? '', ['form_answer', 'decision'], true)) { return true; }
        }
        return false;
    }

    /** Метаданные нового определения берутся с сервера. Исторические снимки не обновляются. */
    public static function freezeForSave(array $condition, array $process): array
    {
        $uuids = [];
        foreach ($condition['clauses'] ?? [] as $c) { if (($c['source'] ?? '') === 'form_answer') { $uuids[] = $c['question_uuid'] ?? ''; } }
        if (!$uuids) { return $condition; }
        $frozen = FormFacts::freezeDefinitions((int) ($process['forms_forms_id'] ?? 0), $uuids, true);
        if ($frozen['errors']) { throw new \InvalidArgumentException($frozen['errors'][0]); }
        foreach ($condition['clauses'] as &$c) {
            if (($c['source'] ?? '') === 'form_answer') { $c['question'] = $frozen['definitions'][$c['question_uuid']]; }
        }
        unset($c);
        return $condition;
    }

    /**
     * Новые условия по типу заявки не сохраняются (1.13): тип заявки убран из «Что проверяем».
     * Прежние — те, что уже есть в сохранённом условии этапа, — остаются.
     */
    public static function newTicketTypeClauses(array $condition, array $stored): bool
    {
        $old = array_map([Condition::class, 'encode'], array_filter(is_array($stored['clauses'] ?? null) ? $stored['clauses'] : [], 'is_array'));
        foreach (is_array($condition['clauses'] ?? null) ? $condition['clauses'] : [] as $c) {
            if (is_array($c) && ($c['source'] ?? '') === 'ticket' && ($c['field'] ?? '') === 'type' && !in_array(Condition::encode($c), $old, true)) {
                return true;
            }
        }
        return false;
    }

    /** Предупреждение о контроле инициатора не запрещает публикацию маршрута. */
    public static function applicantControls(array $condition): bool
    {
        $clauses = $condition['clauses'] ?? [];
        $external = array_filter($clauses, static fn(array $c): bool => in_array($c['source'] ?? '', ['form_answer', 'ticket'], true));
        if (!$external) { return false; }
        $department = array_filter($clauses, static fn(array $c): bool => ($c['source'] ?? '') === 'decision');
        return ($condition['join'] ?? 'all') === 'all' || !$department;
    }

    /**
     * Покрытие только чистых условий по одному вопросу: смешанные условия не выдают ложной гарантии.
     * $start — правило «Запускать, если» маршрута: вариант, при котором маршрут не запускается,
     * этапом не должен покрываться.
     */
    public static function uncoveredOptions(array $stages, array $start = []): array
    {
        $groups = []; $first = [];
        foreach ($stages as $stage) {
            $condition = self::decode($stage['run_if'] ?? null) ?? [];
            $clauses = $condition['clauses'] ?? [];
            if (!$clauses || count(array_filter($clauses, static fn(array $c): bool => ($c['source'] ?? '') !== 'form_answer'
                || ($c['question']['type'] ?? '') !== 'choice' || !in_array($c['operator'] ?? '', ['in', 'not_in'], true))) > 0) { continue; }
            $uuids = array_unique(array_column($clauses, 'question_uuid'));
            if (count($uuids) !== 1) { continue; }
            $groups[reset($uuids)][] = $condition;
            $first[reset($uuids)] ??= isset($stage['id']) ? (int) $stage['id'] : null;
        }
        $out = [];
        foreach ($groups as $uuid => $rules) {
            $question = $rules[0]['clauses'][0]['question']; $missing = [];
            foreach ($question['options'] ?? [] as $option) {
                $context = ['attempt_id' => 1, 'stages' => [], 'ticket' => [], 'form_facts' => ['status' => 'final', 'facts' => [
                    $uuid => ['status' => 'known', 'type' => 'choice', 'value' => $option['key']]]]];
                if ($start !== [] && Condition::evaluate($start, $context)['truth'] === 'no') { continue; }
                $covered = false;
                foreach ($rules as $rule) { if (Condition::evaluate($rule, $context)['truth'] === 'yes') { $covered = true; break; } }
                if (!$covered) { $missing[] = $option['label']; }
            }
            if ($missing) { $out[] = ['question' => $question['label'], 'options' => $missing, 'stage' => $first[$uuid] ?? null]; }
        }
        return $out;
    }

    /** Формовый ввод никогда не принимает присланные клиентом метаданные вопроса. */
    public static function fromEditor(array $input, array $process, array $stage, array $stages): array
    {
        $parsed = ConditionEditor::parse(is_array($input[self::EDITOR] ?? null) ? $input[self::EDITOR] : [],
            ConditionEditor::SCOPE_STAGE, $process, $stage, $stages);
        if ($parsed['errors']) { throw new \InvalidArgumentException($parsed['errors'][0]); }
        $items = self::decisionRows($input['_decision_rows'] ?? []);
        $policy = $input['_branch_unknown'] ?? ($stage['unknown_policy'] ?? 'run');
        if (!in_array($policy, ['run', 'ask'], true)) { throw new \InvalidArgumentException('Выберите, что делать, если ответа нет.'); }
        return ['run_if' => $parsed['rule'] ? DefinitionSnapshot::encode($parsed['rule']) : null,
            'decision_items' => $items ? DefinitionSnapshot::encode($items) : null,
            'unknown_policy' => $policy];
    }

    /** «Вопросы этапа» из формы: пустые строки не сохраняются, ключ прежнего вопроса остаётся. */
    public static function decisionRows(mixed $rows): array
    {
        if (!is_array($rows) || count($rows) > 4) { throw new \InvalidArgumentException('Вопросов этапа — не больше четырёх.'); }
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['label'] ?? '') || !is_string($row['key'] ?? '')) { throw new \InvalidArgumentException('Вопрос этапа повреждён — задайте заново.'); }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') { continue; }
            $key = (string) ($row['key'] ?? '');
            if ($key === '') { $key = 'q_' . bin2hex(random_bytes(8)); }
            $items[] = ['key' => $key, 'label' => $label];
        }
        if (self::decisionItems($items) === null) { throw new \InvalidArgumentException('Вопросов этапа — не больше четырёх, без повторов, до 255 знаков.'); }
        return $items;
    }
}
