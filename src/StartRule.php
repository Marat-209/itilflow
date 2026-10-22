<?php
namespace GlpiPlugin\Itilflow;

/**
 * «Запускать, если» маршрута — поле processes.start_if.
 *
 * Формат (JSON, канонический — Condition::encode):
 * - NULL — «Не запускать по форме»: маршрут запускает бизнес-правило, как до 1.13;
 * - {"version":1,"mode":"always"} — для каждой заявки назначения формы маршрута;
 * - {"version":1,"mode":"conditions","rule":R} — R в формате Condition (version, join, clauses),
 *   от 1 до 3 условий, источник каждого условия — только 'form_answer' вопроса формы маршрута.
 *
 * Какие заявки рассматриваются: форма маршрута (forms_forms_id) и его назначение
 * (forms_destinations_id; 0 — любое назначение этой формы, создающее заявку). Своей таблицы
 * привязок «маршрут ↔ несколько назначений» в 1.13 нет.
 *
 * decode/encode — формат; validate/evaluate/describe — проверка, вычисление и текст.
 */
final class StartRule
{
    public const VERSION = 1;
    public const MODE_ALWAYS = 'always';
    public const MODE_CONDITIONS = 'conditions';
    /** Признак повреждённой записи: validate() обязан вернуть ошибку, evaluate() — «неизвестно». */
    public const MODE_INVALID = 'invalid';

    /** NULL — запуск по форме выключен. Повреждённая запись не угадывается — MODE_INVALID. */
    public static function decode(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') { return null; }
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($value) || ($value['version'] ?? null) !== self::VERSION) {
            return ['version' => self::VERSION, 'mode' => self::MODE_INVALID, 'rule' => []];
        }
        $mode = $value['mode'] ?? null;
        if ($mode === self::MODE_ALWAYS && count($value) === 2) {
            return ['version' => self::VERSION, 'mode' => self::MODE_ALWAYS, 'rule' => []];
        }
        if ($mode === self::MODE_CONDITIONS && is_array($value['rule'] ?? null) && $value['rule'] !== []
            && count($value) === 3) {
            return ['version' => self::VERSION, 'mode' => self::MODE_CONDITIONS, 'rule' => $value['rule']];
        }
        return ['version' => self::VERSION, 'mode' => self::MODE_INVALID, 'rule' => []];
    }

    /** Значение для записи в processes.start_if; null — выключить запуск по форме. */
    public static function encode(?array $start): ?string
    {
        if ($start === null) { return null; }
        if (($start['mode'] ?? null) === self::MODE_ALWAYS) {
            return Condition::encode(['version' => self::VERSION, 'mode' => self::MODE_ALWAYS]);
        }
        if (($start['mode'] ?? null) === self::MODE_CONDITIONS && is_array($start['rule'] ?? null) && $start['rule'] !== []) {
            return Condition::encode(['version' => self::VERSION, 'mode' => self::MODE_CONDITIONS, 'rule' => $start['rule']]);
        }
        throw new \InvalidArgumentException('Неверный формат «Запускать, если».');
    }

    /** Запуск по форме включён у маршрута (строка processes или снимок маршрута). */
    public static function isOn(array $process): bool
    {
        return self::decode($process['start_if'] ?? null) !== null;
    }

    /**
     * Ошибки настройки словами для администратора маршрутов; [] — можно сохранить.
     * Только 'form_answer'; вопрос есть в каталоге формы маршрута ($catalog —
     * FormFacts::questionCatalog) и поддерживается; сравнения — те же, что в «Выполнять, если»
     * (Condition); MODE_INVALID — ошибка.
     *
     * @return list<string>
     */
    public static function validate(array $start, array $catalog): array
    {
        if (($start['version'] ?? null) !== self::VERSION
            || array_diff(array_keys($start), ['version', 'mode', 'rule'])) {
            return ['Условие запуска повреждено — задайте заново.'];
        }
        if (($start['mode'] ?? null) === self::MODE_ALWAYS && ($start['rule'] ?? []) === []) { return []; }
        if (($start['mode'] ?? null) !== self::MODE_CONDITIONS
            || !is_array($start['rule'] ?? null) || $start['rule'] === []) {
            return ['Для «Только если» задайте от одного до трёх условий по ответам формы.'];
        }
        $rule = $start['rule'];
        $errors = Condition::validate($rule);
        foreach (is_array($rule['clauses'] ?? null) ? $rule['clauses'] : [] as $clause) {
            if (!is_array($clause) || ($clause['source'] ?? '') !== 'form_answer') {
                $errors[] = 'Для запуска доступны только ответы выбранной формы.';
                continue;
            }
            $uuid = $clause['question_uuid'] ?? null;
            $current = is_string($uuid) ? ($catalog[$uuid] ?? null) : null;
            $frozen = $clause['question'] ?? null;
            if (!is_array($current) || empty($current['supported']) || !is_array($frozen)
                || ($current['uuid'] ?? null) !== $uuid
                || ($current['id'] ?? null) !== ($frozen['id'] ?? null)) {
                $errors[] = 'Вопрос для запуска удалён или недоступен — выберите заново.';
                continue;
            }
            if (FormFacts::validateMapping($frozen, $current) !== []) {
                $errors[] = 'Тип или варианты вопроса для запуска изменились — задайте условие заново.';
            }
        }
        return array_values(array_unique($errors));
    }

    /**
     * Чистое вычисление по фактам отправки ($facts — конверт FormFacts::submissionFacts).
     * MODE_ALWAYS — 'yes'; MODE_INVALID — 'unknown'. Условия — Condition::evaluate с контекстом
     * только ['form_facts' => $facts].
     *
     * @return array{truth:string,reasons:list<string>}
     */
    public static function evaluate(array $start, array $facts): array
    {
        // Без чтения текущей формы: факты и определения принадлежат одной отправке.
        $catalog = [];
        foreach (is_array($start['rule']['clauses'] ?? null) ? $start['rule']['clauses'] : [] as $clause) {
            if (!is_array($clause)) { continue; }
            $q = $clause['question'] ?? null;
            if (is_array($q) && is_string($q['uuid'] ?? null)) {
                $uuid = $q['uuid'];
                if (isset($catalog[$uuid]) && (($catalog[$uuid]['id'] ?? null) !== ($q['id'] ?? null)
                    || FormFacts::validateMapping($catalog[$uuid], $q) !== [])) {
                    return ['truth' => 'unknown', 'reasons' => ['conflicting_question_definition']];
                }
                $catalog[$uuid] = $q;
            }
        }
        if (self::validate($start, $catalog) !== []) {
            return ['truth' => 'unknown', 'reasons' => ['invalid_start_condition']];
        }
        if ($start['mode'] === self::MODE_ALWAYS) { return ['truth' => 'yes', 'reasons' => []]; }
        $result = Condition::evaluate($start['rule'], ['form_facts' => $facts]);
        return ['truth' => $result['truth'], 'reasons' => $result['reasons']];
    }

    /**
     * Фраза для экрана: «для каждой заявки с формы» или «если «Категория» — Вариант А».
     * Текст условий — ConditionText.
     */
    public static function describe(array $start, ?\Closure $name = null): string
    {
        return match ($start['mode'] ?? null) {
            self::MODE_ALWAYS => 'для каждой заявки с формы',
            self::MODE_CONDITIONS => is_array($start['rule'] ?? null) && $start['rule'] !== []
                ? ConditionText::short($start['rule'], [], $name) : 'условие запуска не задано',
            default => 'условие запуска повреждено — задайте заново',
        };
    }
}
