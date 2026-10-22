<?php
namespace GlpiPlugin\Itilflow;

/**
 * Человеческий текст условий — одна функция на все экраны:
 * колонка «Когда» и «Что дальше», строка этапа на заявке и лист согласования,
 * схема, «Запускать, если».
 *
 * Названия вместо номеров: этапы — «20», вопросы и варианты — по сохранённым подписям,
 * объекты GLPI — через $name (по умолчанию self::name). Удалённый объект — «удалён (№ N)».
 * $stages — этапы маршрута или снимка (id, ranking, name, decision_items).
 *
 * Тексты:
 * brief — кратко («20: «…» — да»), detail — для фразы под условием («на этапе 20 ответили…»).
 */
final class ConditionText
{
    /** @var array<string,string> названия объектов GLPI за запрос */
    private static array $names = [];

    /** Кратко для таблицы и строки заявки: «если 20: «Нужно дополнительное согласование» — да». [] — ''. */
    public static function short(array $rule, array $stages = [], ?\Closure $name = null): string
    {
        return $rule === [] ? '' : 'если ' . self::body($rule, $stages, $name);
    }

    /** Условия кратко без «если»: «20: «…» — да и категория заявки — Оборудование». */
    public static function body(array $rule, array $stages = [], ?\Closure $name = null): string
    {
        $parts = [];
        foreach (is_array($rule['clauses'] ?? null) ? $rule['clauses'] : [] as $clause) {
            $parts[] = (is_array($clause) ? self::brief($clause, $stages, $name) : null) ?? 'условие повреждено';
        }
        return $parts ? implode(($rule['join'] ?? 'all') === 'any' ? ' или ' : ' и ', $parts) : 'условие повреждено';
    }

    /**
     * Фраза под условием в редакторе: SCOPE_STAGE — «Этап выполняется, если …»,
     * SCOPE_START — «Маршрут запускается, если …».
     */
    public static function phrase(array $clause, string $scope, array $stages = [], ?\Closure $name = null): string
    {
        $detail = self::detail($clause, $stages, $name);
        if ($detail === null) { return ''; }
        return ($scope === ConditionEditor::SCOPE_START ? 'Маршрут запускается, если ' : 'Этап выполняется, если ') . $detail . '.';
    }

    /**
     * Причина «Не нужен по условию»: факты условий, которые дали «нет», —
     * «20: «Нужно дополнительное согласование» — нет»; $context — ConditionalFlow::conditionContext.
     */
    public static function reason(array $rule, array $context, array $stages = [], ?\Closure $name = null): string
    {
        $clauses = is_array($rule['clauses'] ?? null) ? $rule['clauses'] : [];
        if ($clauses === []) { return ''; }
        $results = Condition::clauseResults($rule, $context);
        if (count($results) !== count($clauses) || !array_filter($clauses, 'is_array')) { return 'условие повреждено'; }
        $chosen = array_keys(array_filter($results, static fn(?bool $r): bool => $r === false));
        if ($chosen === []) { $chosen = array_keys($clauses); }
        $out = [];
        foreach ($chosen as $i) {
            $out[] = is_array($clauses[$i]) ? self::fact($clauses[$i], $context, $stages, $name) : 'условие повреждено';
        }
        return implode('; ', $out);
    }

    /** Название объекта GLPI (User, Group, ITILCategory) по номеру; нет записи — «удалён (№ N)». */
    public static function name(string $itemtype, int $id): string
    {
        if ($id <= 0) { return 'не выбрано'; }
        $key = $itemtype . ':' . $id;
        if (isset(self::$names[$key])) { return self::$names[$key]; }
        global $DB;
        if (!$DB instanceof \DBmysql || !in_array($itemtype, ['User', 'Group', 'ITILCategory'], true)) { return '№ ' . $id; }
        try {
            $item = new $itemtype();
            if (!$item->getFromDB($id)) {
                $text = 'удалён (№ ' . $id . ')';
            } elseif ($itemtype === 'User') {
                $text = trim((string) $item->getFriendlyName());
            } else {
                $text = trim((string) ($item->fields['completename'] ?? '')) ?: trim((string) ($item->fields['name'] ?? ''));
            }
        } catch (\Throwable) {
            TransactionScope::assertHealthy();
            return '№ ' . $id;
        }
        return self::$names[$key] = $text !== '' ? $text : '№ ' . $id;
    }

    /** Кратко одно условие. null — условие не заполнено или повреждено. */
    public static function brief(array $c, array $stages = [], ?\Closure $name = null): ?string
    {
        $op = $c['operator'] ?? ''; $value = $c['value'] ?? null;
        switch ($c['source'] ?? '') {
            case 'decision':
                $n = self::stageNumber($c['stage_id'] ?? 0, $stages); $q = self::decisionLabel($c, $stages);
                if ($op === 'unknown') { return $n . ': «' . $q . '» — нет ответа'; }
                $yes = self::positive($op, $value);
                return $yes === null ? null : $n . ': «' . $q . '» — ' . ($yes ? 'да' : 'нет');
            case 'stage_not_needed':
                $yes = self::positive($op, $value);
                return $yes === null ? null : self::stageNumber($c['stage_id'] ?? 0, $stages) . ($yes ? ' не нужен по условию' : ' выполнялся');
            case 'ticket':
                $field = ($c['field'] ?? '') === 'type' ? 'тип заявки' : 'категория заявки';
                $vals = self::ticketValues($c, $name);
                if ($vals === []) { return null; }
                return in_array($op, ['in', 'eq'], true) ? $field . ' — ' . self::list($vals, 'или')
                    : $field . ' — не ' . self::list($vals, 'и не');
            case 'form_answer':
                return self::formBrief($c, $name);
        }
        return null;
    }

    /** Подробно одно условие — для фразы под условием. null — не заполнено. */
    public static function detail(array $c, array $stages = [], ?\Closure $name = null): ?string
    {
        $op = $c['operator'] ?? ''; $value = $c['value'] ?? null;
        switch ($c['source'] ?? '') {
            case 'decision':
                $n = self::stageNumber($c['stage_id'] ?? 0, $stages); $q = self::decisionLabel($c, $stages);
                if ($op === 'unknown') { return 'на этапе ' . $n . ' нет ответа на «' . $q . '»'; }
                $yes = self::positive($op, $value);
                return $yes === null ? null : 'на этапе ' . $n . ' ответили «' . ($yes ? 'да' : 'нет') . '» на «' . $q . '»';
            case 'stage_not_needed':
                $yes = self::positive($op, $value);
                return $yes === null ? null : 'этап ' . self::stageTitle($c['stage_id'] ?? 0, $stages) . ($yes ? ' не нужен по условию' : ' выполнялся');
            case 'ticket':
                $field = ($c['field'] ?? '') === 'type' ? 'тип заявки' : 'категория заявки';
                $vals = self::ticketValues($c, $name);
                if ($vals === []) { return null; }
                if (in_array($op, ['in', 'eq'], true)) {
                    return $field . ' — ' . (count($vals) === 1 ? $vals[0] : 'одна из: ' . implode(', ', $vals));
                }
                return $field . ' — не ' . self::list($vals, 'и не');
            case 'form_answer':
                return self::formDetail($c, $name);
        }
        return null;
    }

    /** Факт одного условия для причины: «20: «…» — нет», «Вид работ» — Ремонт». */
    public static function fact(array $c, array $context, array $stages = [], ?\Closure $name = null): string
    {
        $prior = array_map('intval', array_keys(is_array($context['stages'] ?? null) ? $context['stages'] : []));
        if (Condition::validate(['version' => 1, 'join' => 'all', 'clauses' => [$c]], $prior) !== []) { return 'условие повреждено'; }
        $f = Condition::fact($c, $context);
        switch ($c['source']) {
            case 'decision':
                $t = !$f['known'] ? ('нет ответа' . (($f['state'] ?? '') === Step::NOT_NEEDED ? ', этап не нужен по условию' : ''))
                    : ($f['value'] ? 'да' : 'нет');
                return self::stageNumber($c['stage_id'], $stages) . ': «' . self::decisionLabel($c, $stages) . '» — ' . $t;
            case 'stage_not_needed':
                return 'этап ' . self::stageNumber($c['stage_id'], $stages)
                    . (!$f['known'] ? ' ещё не пройден' : ($f['value'] ? ' не нужен по условию' : ' выполнялся'));
            case 'ticket':
                $field = $c['field'] === 'type' ? 'тип заявки' : 'категория заявки';
                if (!$f['known']) { return $field . ' — не указана'; }
                if ($c['field'] === 'type') { return $field . ' — ' . self::ticketType($f['value']); }
                return $field . ' — ' . ($f['value'] === 0 ? 'без категории' : self::objectName('ITILCategory', $f['value'], $name));
        }
        $q = self::questionLabel($c);
        if (!$f['known']) { return '«' . $q . '» — нет ответа'; }
        $v = $f['value'];
        $t = match ($f['type']) {
            'bool' => $v ? 'отмечен' : 'не отмечен',
            'text' => $v ? 'заполнено' : 'не заполнено',
            'file' => $v ? 'приложен' : 'не приложен',
            'ref' => $v === null ? 'не заполнено' : self::objectName($v['itemtype'], $v['id'], $name),
            'date' => $v === null ? 'не заполнено' : self::dateFact($v, $context['form_facts']['source']['submitted_at'] ?? null),
            'choice' => implode(', ', self::optionLabels($c, (array) $v)),
            default => (string) $v,
        };
        return '«' . $q . '» — ' . $t;
    }

    private static function formBrief(array $c, ?\Closure $name): ?string
    {
        $q = self::questionLabel($c); $op = $c['operator'] ?? ''; $type = $c['question']['type'] ?? '';
        if ($op === 'unknown') { return 'нет ответа на «' . $q . '»'; }
        if ($op === 'filled' || $op === 'empty') {
            $word = $type === 'file' ? 'приложен' : 'заполнено';
            return ($op === 'filled' ? $word : 'не ' . $word) . ' «' . $q . '»';
        }
        if ($op === 'before_days' || $op === 'after_days') {
            $days = self::days($c['value'] ?? null);
            return $days === null ? null : '«' . $q . '» — ' . self::daysPhrase($op, $days);
        }
        if ($type === 'bool') {
            $yes = self::positive($op, $c['value'] ?? null);
            return $yes === null ? null : ($yes ? 'отмечен «' : 'не отмечен «') . $q . '»';
        }
        if ($type === 'number') {
            $word = self::numberWord($op);
            return $word === null || !is_string($c['value'] ?? null) ? null : '«' . $q . '» ' . $word . ' ' . $c['value'];
        }
        $vals = self::formValues($c, $name);
        if ($vals === []) { return null; }
        if ($op === 'in' || $op === 'eq') { return '«' . $q . '» — ' . self::list($vals, 'или'); }
        return '«' . $q . '» — не ' . self::list($vals, 'и не');
    }

    private static function formDetail(array $c, ?\Closure $name): ?string
    {
        $q = self::questionLabel($c); $op = $c['operator'] ?? ''; $type = $c['question']['type'] ?? '';
        if ($op === 'unknown') { return 'в форме нет ответа на «' . $q . '» (заявка не из формы или вопрос был скрыт)'; }
        if ($op === 'filled' || $op === 'empty') {
            $not = $op === 'empty' ? 'не ' : '';
            return $type === 'file' ? 'в форме ' . $not . 'приложен файл «' . $q . '»' : 'в форме ' . $not . 'заполнено «' . $q . '»';
        }
        if ($op === 'before_days' || $op === 'after_days') {
            $days = self::days($c['value'] ?? null);
            return $days === null ? null : 'в форме «' . $q . '» — ' . self::daysPhrase($op, $days);
        }
        if ($type === 'bool') {
            $yes = self::positive($op, $c['value'] ?? null);
            return $yes === null ? null : 'в форме ' . ($yes ? 'отмечен «' : 'не отмечен «') . $q . '»';
        }
        if ($type === 'number') {
            $word = self::numberWord($op);
            return $word === null || !is_string($c['value'] ?? null) ? null : 'в форме «' . $q . '» ' . $word . ' ' . $c['value'];
        }
        $vals = self::formValues($c, $name);
        if ($vals === []) { return null; }
        if ($op === 'in' || $op === 'eq') {
            return 'в форме «' . $q . '» — ' . (count($vals) === 1 ? $vals[0] : 'одно из: ' . implode(', ', $vals));
        }
        if ($op === 'ne' && $type === 'ref') { return 'в форме «' . $q . '» — не ' . $vals[0]; }
        return 'в форме «' . $q . '» — что угодно, кроме ' . self::list($vals, 'и');
    }

    /** «да» у вопроса этапа, флажка и «не нужен по условию»: eq true / ne false. */
    private static function positive(string $op, mixed $value): ?bool
    {
        if (!is_bool($value) || !in_array($op, ['eq', 'ne'], true)) { return null; }
        return $op === 'eq' ? $value : !$value;
    }

    private static function numberWord(string $op): ?string
    {
        return ['eq' => 'равно', 'ne' => 'не равно', 'lt' => 'меньше', 'lte' => 'не больше', 'gt' => 'больше', 'gte' => 'не меньше'][$op] ?? null;
    }

    private static function days(mixed $n): ?int
    {
        return is_int($n) && $n > 0 ? $n : null;
    }

    private static function daysPhrase(string $op, int $n): string
    {
        return ($op === 'before_days' ? 'раньше' : 'позже') . ', чем через ' . $n . ' ' . self::plural($n, 'день', 'дня', 'дней') . ' после подачи';
    }

    private static function dateFact(string $value, mixed $submitted): string
    {
        $days = Condition::daysAfter($submitted, $value);
        if ($days === null) {
            $d = Condition::dateTime($value);
            return $d === null ? 'не заполнено' : $d->format('d.m.Y');
        }
        if ($days === 0) { return 'в день подачи'; }
        if ($days < 0) { return 'за ' . -$days . ' ' . self::plural(-$days, 'день', 'дня', 'дней') . ' до подачи'; }
        return 'через ' . $days . ' ' . self::plural($days, 'день', 'дня', 'дней') . ' после подачи';
    }

    /** Значения ответа формы — подписи вариантов из снимка или названия объектов. */
    private static function formValues(array $c, ?\Closure $name): array
    {
        $value = $c['value'] ?? null;
        if (($c['question']['type'] ?? '') === 'choice') {
            return $value === null || $value === [] ? [] : self::optionLabels($c, (array) $value);
        }
        if (($c['question']['type'] ?? '') === 'ref') {
            $list = ($c['operator'] ?? '') === 'in' ? (is_array($value) && array_is_list($value) ? $value : []) : [$value];
            $out = [];
            foreach ($list as $ref) {
                if (is_array($ref) && is_string($ref['itemtype'] ?? null) && is_int($ref['id'] ?? null)) {
                    $out[] = self::objectName($ref['itemtype'], $ref['id'], $name);
                }
            }
            return $out;
        }
        return [];
    }

    /** Только сохранённые подписи: переименованный справочник не переписывает историю. */
    private static function optionLabels(array $c, array $keys): array
    {
        $labels = [];
        foreach (is_array($c['question']['options'] ?? null) ? $c['question']['options'] : [] as $option) {
            if (is_array($option) && is_string($option['key'] ?? null) && is_string($option['label'] ?? null)) {
                $labels[$option['key']] = $option['label'];
            }
        }
        $out = [];
        foreach ($keys as $key) { $out[] = is_string($key) && isset($labels[$key]) ? $labels[$key] : 'вариант удалён'; }
        return $out;
    }

    private static function ticketValues(array $c, ?\Closure $name): array
    {
        $out = [];
        foreach ((array) ($c['value'] ?? []) as $id) {
            if (!is_int($id)) { continue; }
            if (($c['field'] ?? '') === 'type') { $out[] = self::ticketType($id); continue; }
            $out[] = $id === 0 ? 'без категории' : self::objectName('ITILCategory', $id, $name);
        }
        return $out;
    }

    private static function ticketType(mixed $id): string
    {
        return [1 => 'Инцидент', 2 => 'Запрос'][$id] ?? 'неизвестный тип';
    }

    private static function objectName(string $itemtype, int $id, ?\Closure $name): string
    {
        return $name !== null ? (string) $name($itemtype, $id) : self::name($itemtype, $id);
    }

    private static function questionLabel(array $c): string
    {
        $label = is_array($c['question'] ?? null) ? trim((string) ($c['question']['label'] ?? '')) : '';
        return $label !== '' ? $label : 'вопрос формы';
    }

    private static function stage(mixed $id, array $stages): ?array
    {
        foreach ($stages as $key => $stage) {
            if (is_array($stage) && (int) ($stage['id'] ?? $key) === (int) $id) { return $stage; }
        }
        return null;
    }

    /** Номер этапа «20»; без номера — его название; удалённый — «удалённый этап». */
    private static function stageNumber(mixed $id, array $stages): string
    {
        $stage = self::stage($id, $stages);
        if ($stage === null) { return 'удалённый этап'; }
        if ((int) ($stage['ranking'] ?? 0) > 0) { return (string) (int) $stage['ranking']; }
        $name = trim((string) ($stage['name'] ?? ''));
        return $name !== '' ? '«' . $name . '»' : 'этап без номера';
    }

    private static function stageTitle(mixed $id, array $stages): string
    {
        $stage = self::stage($id, $stages);
        if ($stage === null) { return 'удалённый'; }
        $name = trim((string) ($stage['name'] ?? ''));
        $number = (int) ($stage['ranking'] ?? 0) > 0 ? (string) (int) $stage['ranking'] : '';
        return trim($number . ($name !== '' ? ' «' . $name . '»' : ''));
    }

    private static function decisionLabel(array $c, array $stages): string
    {
        $stage = self::stage($c['stage_id'] ?? 0, $stages);
        foreach (BranchConfiguration::decisionItems($stage['decision_items'] ?? null) ?? [] as $item) {
            if ($item['key'] === ($c['key'] ?? null)) { return trim($item['label']) !== '' ? $item['label'] : 'вопрос без текста'; }
        }
        return 'удалённый вопрос';
    }

    /** «A, B или C». */
    private static function list(array $items, string $last): string
    {
        $items = array_values($items);
        if (count($items) <= 1) { return implode('', $items); }
        return implode(', ', array_slice($items, 0, -1)) . ' ' . $last . ' ' . $items[count($items) - 1];
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $n10 = $n % 10; $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) { return $one; }
        return $n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14) ? $few : $many;
    }
}
