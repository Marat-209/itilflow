<?php
namespace GlpiPlugin\Itilflow;

/**
 * Ограниченные условия: три значения, точные десятичные числа, без исполняемого языка.
 *
 * 1.13: виды вопросов формы bool, choice, number, ref, text, file, date;
 * сравнения без значения filled, empty, unknown (value null); у даты — before_days / after_days
 * (целое N > 0, «раньше / позже, чем через N дней после подачи»); у ref — in со списком
 * {itemtype, id}. «Неизвестно» (unknown) само всегда известно: «да», когда ответа нет.
 * Источник ticket: category_id; условия по типу заявки (type) только читаются.
 */
final class Condition
{
    /** Виды вопроса формы в снимке условия. */
    public const QUESTION_TYPES = ['bool', 'choice', 'number', 'ref', 'text', 'file', 'date'];
    /** Сравнения без значения. */
    public const UNARY = ['filled', 'empty', 'unknown'];
    /** Самое большое N в «через N дней»: десять лет. */
    public const MAX_DAYS = 3660;

    private const BROKEN = 'Условие повреждено — задайте его заново.';
    private const REF_TYPES = ['User', 'Group', 'ITILCategory'];

    /** Допустимые сравнения: вид условия => операторы. */
    public static function operatorsFor(string $source, string $type): array
    {
        if ($source === 'decision') { return ['eq', 'ne', 'unknown']; }
        if ($source === 'stage_not_needed') { return ['eq', 'ne']; }
        if ($source === 'ticket') { return ['eq', 'ne', 'in', 'not_in']; }
        return match ($type) {
            'number' => ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'unknown'],
            'choice' => ['in', 'not_in', 'unknown'],
            'bool' => ['eq', 'ne', 'unknown'],
            'ref' => ['eq', 'ne', 'in', 'filled', 'empty', 'unknown'],
            'text', 'file' => ['filled', 'empty', 'unknown'],
            'date' => ['before_days', 'after_days', 'filled', 'empty', 'unknown'],
            default => [],
        };
    }

    public static function validate(array $rule, array $priorStageIds = []): array
    {
        if ($rule === []) { return []; }
        $errors = [];
        if (array_diff(array_keys($rule), ['version','join','clauses'])) { $errors[] = self::BROKEN; }
        if (($rule['version'] ?? null) !== 1 || !in_array($rule['join'] ?? null, ['all', 'any'], true)
            || !is_array($rule['clauses'] ?? null) || !array_is_list($rule['clauses'])
            || count($rule['clauses']) < 1 || count($rule['clauses']) > 3) { return [self::BROKEN]; }
        foreach ($rule['clauses'] as $c) {
            if (!is_array($c)) { $errors[] = self::BROKEN; continue; }
            if (array_diff(array_keys($c), ['source','stage_id','key','question_uuid','question','field','operator','value'])) { $errors[] = self::BROKEN; }
            $source = $c['source'] ?? ''; $op = $c['operator'] ?? '';
            if (!in_array($source, ['decision', 'stage_not_needed', 'form_answer', 'ticket'], true)) { $errors[] = self::BROKEN; continue; }
            $q = [];
            if (in_array($source, ['decision', 'stage_not_needed'], true)) {
                if (!is_int($c['stage_id'] ?? null) || !in_array($c['stage_id'], $priorStageIds, true)) { $errors[] = 'Условие может ссылаться только на этапы выше.'; }
                if ($source === 'decision' && (!is_string($c['key'] ?? null) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $c['key']))) { $errors[] = self::BROKEN; }
                $type = 'bool';
            } elseif ($source === 'ticket') {
                if (!in_array($c['field'] ?? null, ['category_id', 'type'], true)) { $errors[] = self::BROKEN; }
                $type = 'id';
            } else {
                if (!is_string($c['question_uuid'] ?? null) || $c['question_uuid'] === '') { $errors[] = 'Не выбран вопрос формы.'; }
                $q = $c['question'] ?? [];
                if (!is_array($q)) { $errors[] = self::BROKEN; continue; }
                if (($q['uuid'] ?? '') !== ($c['question_uuid'] ?? null)
                    || !is_int($q['id'] ?? null) || $q['id'] <= 0) { $errors[] = self::BROKEN; }
                $type = $q['type'] ?? '';
                if (!is_string($type) || !in_array($type, self::QUESTION_TYPES, true)) { $errors[] = self::BROKEN; continue; }
            }
            if (!is_string($op) || !in_array($op, self::operatorsFor($source, $type), true) || !array_key_exists('value', $c)
                || !self::expected($c['value'], $type, $op, $q)) { $errors[] = self::BROKEN; continue; }
            if ($source === 'form_answer' && $type === 'ref' && !in_array($q['itemtype'] ?? null, self::REF_TYPES, true)) { $errors[] = self::BROKEN; }
            if ($source === 'form_answer' && $type === 'choice' && $c['value'] !== null) {
                $keys = array_column(is_array($q['options'] ?? null) ? $q['options'] : [], 'key');
                foreach ((array) $c['value'] as $selected) {
                    if (!in_array($selected, $keys, true)) { $errors[] = 'Такого варианта ответа в вопросе больше нет.'; }
                }
            }
        }
        return array_values(array_unique($errors));
    }

    /** Значение условия подходит к виду и сравнению. */
    private static function expected(mixed $v, string $type, string $op, array $q): bool
    {
        if (in_array($op, self::UNARY, true)) { return $v === null; }
        if ($op === 'before_days' || $op === 'after_days') { return is_int($v) && $v >= 1 && $v <= self::MAX_DAYS; }
        if ($type === 'ref') {
            $ref = static fn(mixed $x): bool => is_array($x) && count($x) === 2 && ($x['itemtype'] ?? null) === ($q['itemtype'] ?? false)
                && is_int($x['id'] ?? null) && $x['id'] > 0;
            if ($op === 'in') {
                if (!is_array($v) || !array_is_list($v) || $v === [] || count($v) > 50) { return false; }
                $seen = [];
                foreach ($v as $x) {
                    if (!$ref($x) || isset($seen[$x['id']])) { return false; }
                    $seen[$x['id']] = true;
                }
                return true;
            }
            return $ref($v);
        }
        if ($type === 'id') {
            if (in_array($op, ['eq', 'ne'], true)) { return is_int($v) && $v >= 0; }
            return is_array($v) && array_is_list($v) && $v !== [] && count($v) <= 50
                && count(array_filter($v, static fn($x): bool => !is_int($x) || $x < 0)) === 0;
        }
        if ($type === 'choice') {
            // Одиночный ключ строкой — формат до 1.12 включительно, читается как список из одного.
            return (is_string($v) && $v !== '') || (is_array($v) && array_is_list($v) && $v !== []
                && count(array_filter($v, static fn($x): bool => !is_string($x) || $x === '')) === 0);
        }
        return self::value($v, $type);
    }

    public static function evaluate(array $rule, array $context): array
    {
        $prior = array_map('intval', array_keys(is_array($context['stages'] ?? null) ? $context['stages'] : []));
        if (self::validate($rule, $prior)) {
            return ['truth' => 'unknown', 'reasons' => ['invalid_condition'], 'input_revision' => hash('sha256', self::encode([
                $rule, $context['definition'] ?? null, $context['attempt_id'] ?? null]))];
        }
        // Ревизия включает только использованные входы и идентичность определения/попытки.
        $used = ['definition' => $context['definition'] ?? null, 'attempt_id' => $context['attempt_id'] ?? null];
        foreach ($rule['clauses'] ?? [] as $clause) {
            if (!is_array($clause)) { continue; }
            $source = $clause['source'] ?? '';
            if (in_array($source, ['decision','stage_not_needed'], true)) {
                $sid = $clause['stage_id'] ?? 0;
                $used['stages'][$sid] = $context['stages'][$sid] ?? null;
            } elseif ($source === 'ticket') {
                $field = $clause['field'] ?? '';
                $used['ticket'][$field] = $context['ticket'][$field] ?? null;
            } elseif ($source === 'form_answer') {
                $uuid = $clause['question_uuid'] ?? '';
                $used['form']['status'] = $context['form_facts']['status'] ?? null;
                $used['form']['source'] = $context['form_facts']['source'] ?? null;
                $used['form']['input_revision'] = $context['form_facts']['input_revision'] ?? null;
                $used['form']['facts'][$uuid] = $context['form_facts']['facts'][$uuid] ?? null;
            }
        }
        $revision = hash('sha256', self::encode([$rule, $used]));
        if ($rule === []) { return ['truth' => 'yes', 'reasons' => [], 'input_revision' => $revision]; }
        $results = self::results($rule, $context); $reasons = [];
        foreach ($results as $index => $result) { if ($result === null) { $reasons[] = 'unknown_clause_' . $index; } }
        // Неизвестное никогда не выключает контроль, даже рядом с известным false в «все».
        $truth = in_array(null, $results, true) ? 'unknown' : (($rule['join'] === 'all'
            ? !in_array(false, $results, true) : in_array(true, $results, true)) ? 'yes' : 'no');
        return ['truth' => $truth, 'reasons' => $reasons, 'input_revision' => $revision];
    }

    /**
     * Итог каждого условия: true, false или null — неизвестно. Повреждённое правило — все null.
     * Для причины «Не нужен по условию» (ConditionText::reason).
     *
     * @return list<?bool>
     */
    public static function clauseResults(array $rule, array $context): array
    {
        if ($rule === []) { return []; }
        $prior = array_map('intval', array_keys(is_array($context['stages'] ?? null) ? $context['stages'] : []));
        if (self::validate($rule, $prior)) {
            return array_fill(0, is_array($rule['clauses'] ?? null) ? count($rule['clauses']) : 0, null);
        }
        return self::results($rule, $context);
    }

    /** @return list<?bool> правило уже проверено validate */
    private static function results(array $rule, array $context): array
    {
        $out = [];
        foreach ($rule['clauses'] as $c) { $out[] = self::clause($c, $context); }
        return $out;
    }

    /**
     * Факт одного проверенного условия: known, value, type. У вопроса этапа ещё state —
     * состояние этапа в этой попытке (для причины «нет ответа, этап не нужен по условию»).
     */
    public static function fact(array $c, array $context): array
    {
        if ($c['source'] === 'decision' || $c['source'] === 'stage_not_needed') {
            $stage = $context['stages'][$c['stage_id']] ?? null;
            $own = is_array($stage) && (($stage['attempt_id'] ?? null) === ($context['attempt_id'] ?? null) || ($stage['retained'] ?? false) === true);
            if ($c['source'] === 'stage_not_needed') {
                return ['known' => $own && in_array($stage['state'] ?? '', [Step::DONE, Step::NOT_NEEDED], true),
                    'value' => ($stage['state'] ?? '') === Step::NOT_NEEDED, 'type' => 'bool'];
            }
            $value = $stage['answers'][$c['key']] ?? null;
            return ['known' => $own && ($stage['state'] ?? '') === Step::DONE && is_bool($value), 'value' => $value, 'type' => 'bool',
                'state' => $own ? (string) ($stage['state'] ?? '') : ''];
        }
        if ($c['source'] === 'ticket') {
            $value = $context['ticket'][$c['field']] ?? null;
            return ['known' => is_int($value) && $value >= 0, 'value' => $value, 'type' => 'id'];
        }
        $type = $c['question']['type'];
        $form = $context['form_facts'] ?? [];
        $fact = $form['facts'][$c['question_uuid']] ?? [];
        $value = $fact['value'] ?? null;
        $known = ($form['status'] ?? '') === 'final' && ($fact['status'] ?? '') === 'known' && ($fact['type'] ?? '') === $type
            && self::factValue($value, $type, $c['question']);
        return ['known' => $known, 'value' => $value, 'type' => $type];
    }

    private static function clause(array $c, array $context): ?bool
    {
        $op = $c['operator'];
        $fact = self::fact($c, $context);
        if ($op === 'unknown') { return !$fact['known']; }
        if (!$fact['known']) { return null; }
        $value = $fact['value']; $type = $fact['type'];
        if ($op === 'filled' || $op === 'empty') {
            $filled = in_array($type, ['text', 'file'], true) ? $value === true : $value !== null;
            return $op === 'filled' ? $filled : !$filled;
        }
        if ($op === 'before_days' || $op === 'after_days') {
            if ($value === null) { return false; }
            $days = self::daysAfter($context['form_facts']['source']['submitted_at'] ?? null, $value);
            if ($days === null) { return null; }
            return $op === 'before_days' ? $days < $c['value'] : $days > $c['value'];
        }
        if ($type === 'ref') {
            // До 1.13 пустой ответ-объект был неизвестен: прежние «равно / не равно» так и читаются.
            if ($value === null) { return $op === 'in' ? false : null; }
            $list = $op === 'in' ? $c['value'] : [$c['value']];
            $hit = false;
            foreach ($list as $x) { $hit = $hit || ($x['itemtype'] === $value['itemtype'] && $x['id'] === $value['id']); }
            return $op === 'ne' ? !$hit : $hit;
        }
        if ($type === 'choice') {
            $hit = array_intersect(is_array($value) ? $value : [$value], (array) $c['value']) !== [];
            return $op === 'in' ? $hit : !$hit;
        }
        if ($type === 'id' && in_array($op, ['in','not_in'], true)) {
            $hit = in_array($value, $c['value'], true);
            return $op === 'in' ? $hit : !$hit;
        }
        $cmp = $type === 'number' ? self::decimalCompare($value, $c['value']) : ($value <=> $c['value']);
        return match ($op) { 'eq' => $cmp === 0, 'ne' => $cmp !== 0, 'lt' => $cmp < 0, 'lte' => $cmp <= 0, 'gt' => $cmp > 0, 'gte' => $cmp >= 0, default => null };
    }

    /** Значение факта ответа формы своего вида; пустой ответ — false (текст, файл) или null. */
    private static function factValue(mixed $v, string $type, array $q): bool
    {
        if ($type === 'ref') {
            return $v === null || (is_array($v) && count($v) === 2 && in_array($v['itemtype'] ?? null, self::REF_TYPES, true)
                && ($v['itemtype'] ?? null) === ($q['itemtype'] ?? null) && is_int($v['id'] ?? null) && $v['id'] > 0);
        }
        if ($type === 'choice') {
            $keys = array_column(is_array($q['options'] ?? null) ? $q['options'] : [], 'key');
            if (!self::value($v, 'choice')) { return false; }
            foreach (is_array($v) ? $v : [$v] as $selected) { if (!in_array($selected, $keys, true)) { return false; } }
            return true;
        }
        if ($type === 'date') { return $v === null || self::dateTime($v) !== null; }
        return self::value($v, $type);
    }

    private static function value(mixed $v, string $type): bool
    {
        return match ($type) {
            'bool', 'text', 'file' => is_bool($v),
            'number' => is_string($v) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $v) === 1 && strlen($v) <= 256,
            'choice' => (is_string($v) && $v !== '') || (is_array($v) && array_is_list($v)
                && count(array_filter($v, static fn($x): bool => !is_string($x) || $x === '')) === 0),
            default => false,
        };
    }

    /** Дата факта или подачи — строго Y-m-d H:i:s. */
    public static function dateTime(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $v) !== 1) { return null; }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $v, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d H:i:s') === $v ? $d : null;
    }

    /** Сколько календарных дней от подачи до даты ответа; меньше нуля — раньше подачи. null — нельзя посчитать. */
    public static function daysAfter(mixed $submitted, mixed $date): ?int
    {
        $from = self::dateTime($submitted); $to = self::dateTime($date);
        if ($from === null || $to === null) { return null; }
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $from->format('Y-m-d'), new \DateTimeZone('UTC'));
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $to->format('Y-m-d'), new \DateTimeZone('UTC'));
        return intdiv($b->getTimestamp() - $a->getTimestamp(), 86400);
    }

    public static function decimalCompare(string $a, string $b): int
    {
        $parts = static function (string $s): array {
            $negative = str_starts_with($s, '-'); $p = explode('.', ltrim($s, '-'), 2);
            $integer = ltrim($p[0], '0') ?: '0'; $fraction = rtrim($p[1] ?? '', '0');
            return [$negative && ($integer !== '0' || $fraction !== ''), $integer, $fraction];
        };
        [$an,$ai,$af] = $parts($a); [$bn,$bi,$bf] = $parts($b);
        if ($an !== $bn) { return $an ? -1 : 1; }
        $cmp = strlen($ai) <=> strlen($bi);
        if ($cmp === 0) { $cmp = strcmp($ai, $bi) <=> 0; }
        if ($cmp === 0) { $n = max(strlen($af), strlen($bf)); $cmp = strcmp(str_pad($af,$n,'0'), str_pad($bf,$n,'0')) <=> 0; }
        return $an ? -$cmp : $cmp;
    }

    /** Пересказ условий для экрана — ConditionText (названиями, не номерами). */
    public static function describeWithStages(array $rule, array $stages): string
    {
        return $rule === [] ? 'Всегда' : ConditionText::body($rule, $stages);
    }

    /** Прежняя подпись: подписи этапов и вопросов этапа — массивами по номеру этапа. */
    public static function describe(array $rule, array $labels = [], array $decisionLabels = []): string
    {
        if ($rule === []) { return 'Всегда'; }
        $stages = [];
        foreach (array_unique(array_merge(array_keys($labels), array_keys($decisionLabels))) as $id) {
            $items = [];
            foreach ($decisionLabels[$id] ?? [] as $key => $label) { $items[] = ['key' => (string) $key, 'label' => (string) $label]; }
            $stages[] = ['id' => (int) $id, 'name' => (string) ($labels[$id] ?? ''), 'decision_items' => $items];
        }
        return ConditionText::body($rule, $stages);
    }

    public static function encode(mixed $value): string
    {
        $canonical = static function (mixed $v) use (&$canonical): mixed {
            if (!is_array($v)) { return $v; }
            if (!array_is_list($v)) { ksort($v, SORT_STRING); }
            return array_map($canonical, $v);
        };
        return json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
