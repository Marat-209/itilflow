<?php

namespace GlpiPlugin\Itilflow;

/**
 * Какие поля формы этапа видны — по режиму этапа и порядку исходов маршрута.
 *
 * Одни и те же правила служат серверу (что скрыть при выводе) и скрипту формы
 * (что скрыть при смене режима): шаблон переносит их в атрибуты data-itilflow-*.
 * Скрытое поле отключается и не отправляется, поэтому его значение в базе
 * не меняется. Поля другого порядка исходов не выводятся вовсе.
 *
 * m — режимы, в которых поле видно; o — порядок исходов (new — новый,
 * legacy — прежний); c — условие на значения формы; i — тип объекта маршрута.
 */
final class StageForm
{
    /**
     * «Что дальше» после этапа: условные этапы ниже с «если …»
     * до ближайшего этапа без условия; отказ; на какие этапы вернуть после уточнения. Названиями.
     */
    public static function whatNext(array $stage, array $stages): string
    {
        usort($stages, static fn(array $a, array $b): int => [(int) $a['ranking'], (int) $a['id']] <=> [(int) $b['ranking'], (int) $b['id']]);
        $labels = [];
        foreach ($stages as $candidate) {
            $labels[(int) $candidate['id']] = (int) $candidate['ranking'] . ' «' . (string) $candidate['name'] . '»';
        }
        $parts = [self::doneNext($stage, $stages)];
        if (in_array($stage['execution_mode'] ?? '', [Stage::MODE_DELEGATED, Stage::MODE_APPROVAL], true)) {
            $parts[] = (($stage['execution_mode'] ?? '') === Stage::MODE_APPROVAL ? 'Отклонено' : 'Отказ') . ' → ' . match ($stage['negative_policy'] ?? '') {
                Stage::REJECT_ABORT => 'отказ инициатору, маршрут закончен',
                Stage::REJECT_BLOCK => 'остановка маршрута до решения администратора маршрутов',
                default => 'не выбрано',
            };
            $targets = DefinitionSnapshot::targets($stage['return_targets'] ?? null);
            $returns = [];
            foreach ($targets ?? [] as $id) {
                $returns[] = $id === (int) ($stage['id'] ?? -1) ? 'этот этап' : (isset($labels[$id]) ? 'этап ' . $labels[$id] : 'удалённый этап');
            }
            if ($targets === null) { $parts[] = 'Уточнение → список повреждён — отметьте заново'; }
            elseif ($returns) { $parts[] = 'Уточнение → ' . implode(' или ', $returns); }
        }
        return implode(' · ', $parts);
    }

    /**
     * «После этапа N — сюда попадают так»: предыдущий этап и что после него.
     * null — этап первый.
     *
     * @return array{label:string,text:string}|null
     */
    public static function reachedFrom(array $stage, array $stages): ?array
    {
        $previous = null;
        foreach ($stages as $candidate) {
            if ((int) $candidate['id'] === (int) ($stage['id'] ?? -1) || (int) $candidate['ranking'] >= (int) ($stage['ranking'] ?? 0)) { continue; }
            if ($previous === null || [(int) $candidate['ranking'], (int) $candidate['id']] > [(int) $previous['ranking'], (int) $previous['id']]) {
                $previous = $candidate;
            }
        }
        if ($previous === null) { return null; }
        return ['label' => 'После этапа ' . (int) $previous['ranking'] . ' «' . (string) $previous['name'] . '» — сюда попадают так',
            'text' => self::doneNext($previous, $stages)];
    }

    /** «Выполнено → …»: условные этапы ниже с «если …» до ближайшего этапа без условия. */
    public static function doneNext(array $stage, array $stages): string
    {
        usort($stages, static fn(array $a, array $b): int => [(int) $a['ranking'], (int) $a['id']] <=> [(int) $b['ranking'], (int) $b['id']]);
        $next = []; $reachedAlways = false; $labels = [];
        foreach ($stages as $candidate) {
            $labels[(int) $candidate['id']] = (int) $candidate['ranking'] . ' «' . (string) $candidate['name'] . '»';
        }
        foreach ($stages as $candidate) {
            if ((int) $candidate['ranking'] <= (int) ($stage['ranking'] ?? 0)) { continue; }
            $label = 'этап ' . $labels[(int) $candidate['id']];
            $condition = BranchConfiguration::decode($candidate['run_if'] ?? null);
            $prior = array_map('intval', array_column(array_filter($stages,
                static fn(array $s): bool => (int) $s['ranking'] < (int) $candidate['ranking']), 'id'));
            if ($condition === null || Condition::validate($condition, $prior) !== []) {
                $next[] = $label . ' (условие повреждено — откройте этот этап)'; continue;
            }
            if ($condition === []) { $next[] = $label; $reachedAlways = true; break; }
            $next[] = $label . ' (' . ConditionText::short($condition, $stages) . ')';
        }
        if (!$reachedAlways) { $next[] = 'маршрут закончен'; }
        return 'Выполнено → ' . implode(', затем ', $next);
    }

    private const RULES = [
        'name'                             => ['place' => 'main'],
        'execution_mode'                   => ['place' => 'main'],
        'itilcategories_id'                => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED]],
        'completion_comment'               => ['place' => 'main', 'm' => [Stage::MODE_INLINE]],
        'content'                          => ['place' => 'main'],
        'approver_source'                  => ['place' => 'main', 'm' => [Stage::MODE_APPROVAL]],
        'form_validationsteps_id'          => ['place' => 'main', 'm' => [Stage::MODE_APPROVAL], 'c' => 'apform', 'o' => 'new', 'i' => 'Ticket'],
        'groups_id'                        => ['place' => 'main', 'c' => 'notapproval'],
        'users_id'                         => ['place' => 'main', 'c' => 'notapproval'],
        'approver_users'                   => ['place' => 'main', 'm' => [Stage::MODE_APPROVAL], 'c' => 'apfixed'],
        'approver_groups'                  => ['place' => 'main', 'm' => [Stage::MODE_APPROVAL], 'c' => 'apfixed'],
        'approver_group_mode'              => ['place' => 'main', 'm' => [Stage::MODE_APPROVAL], 'c' => 'apfixed'],
        'entity_strategy'                  => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED]],
        'entities_id_target'               => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED], 'c' => 'wherefixed'],
        'department_result_source'         => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED], 'o' => 'new'],
        'deadline'                         => ['place' => 'main'],
        'negative_policy'                  => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED, Stage::MODE_APPROVAL], 'o' => 'new'],
        'return_targets'                   => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED, Stage::MODE_APPROVAL], 'o' => 'new'],
        'on_reject'                        => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED, Stage::MODE_APPROVAL], 'o' => 'legacy'],
        'plugin_itilflow_stages_id_reject' => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED, Stage::MODE_APPROVAL], 'o' => 'legacy', 'c' => 'back'],
        'ranking'                          => ['place' => 'more'],
        'ticket_status'                    => ['place' => 'more'],
        'duration'                         => ['place' => 'more', 'm' => [Stage::MODE_INLINE]],
        'is_optional'                      => ['place' => 'more', 'm' => [Stage::MODE_INLINE]],
        'return_targets_retry'             => ['place' => 'more', 'm' => [Stage::MODE_INLINE], 'o' => 'new'],
        'slas_id'                          => ['place' => 'more', 'm' => [Stage::MODE_DELEGATED], 'i' => 'Ticket'],
        'approval_percent'                 => ['place' => 'more', 'c' => 'visa'],
    ];

    /** @return array<string,array> правила видимости полей */
    public static function rules(): array
    {
        return self::RULES;
    }

    /** Значения формы, от которых зависит видимость. */
    public static function state(array $fields): array
    {
        return [
            'mode'     => (string) ($fields['execution_mode'] ?? Stage::MODE_INLINE),
            'approver' => (string) ($fields['approver_source'] ?? Stage::APPROVER_FIXED),
            'where'    => (string) ($fields['entity_strategy'] ?? 'inherit'),
            'result'   => (string) ($fields['department_result_source'] ?? ''),
            'onrej'    => (string) ($fields['on_reject'] ?? Stage::REJECT_BLOCK),
        ];
    }

    /** Выводится ли поле вообще: порядок исходов и тип объекта маршрута не меняются в форме. */
    public static function rendered(string $key, bool $structured, string $itemtype): bool
    {
        $rule = self::RULES[$key] ?? [];
        if (isset($rule['o']) && $rule['o'] !== ($structured ? 'new' : 'legacy')) {
            return false;
        }
        return !isset($rule['i']) || $rule['i'] === $itemtype;
    }

    /** Видно ли выведенное поле при текущих значениях формы. */
    public static function visible(string $key, array $state, bool $structured): bool
    {
        $rule = self::RULES[$key] ?? [];
        if (isset($rule['m']) && !in_array($state['mode'], $rule['m'], true)) {
            return false;
        }
        return match ($rule['c'] ?? '') {
            'notapproval' => $state['mode'] !== Stage::MODE_APPROVAL,
            'apfixed'    => $state['mode'] !== Stage::MODE_APPROVAL || $state['approver'] === Stage::APPROVER_FIXED,
            'apform'     => $state['approver'] === Stage::APPROVER_FORM,
            'wherefixed' => $state['where'] === 'fixed',
            'back'       => $state['onrej'] === Stage::REJECT_BACK,
            'visa'       => ($state['mode'] === Stage::MODE_APPROVAL && $state['approver'] !== Stage::APPROVER_FORM)
                || ($structured && $state['mode'] === Stage::MODE_DELEGATED && $state['result'] === 'native_validation'),
            default      => true,
        };
    }

    /**
     * Видимые поля этапа: основная часть и «Дополнительно».
     *
     * @return array{main:list<string>,more:list<string>}
     */
    public static function visibleFields(array $fields, bool $structured, string $itemtype): array
    {
        $state = self::state($fields);
        $out = ['main' => [], 'more' => []];
        foreach (self::RULES as $key => $rule) {
            if (self::rendered($key, $structured, $itemtype) && self::visible($key, $state, $structured)) {
                $out[$rule['place']][] = $key;
            }
        }
        return $out;
    }

    /**
     * Видимость каждого выведенного поля и атрибуты для скрипта формы.
     *
     * @return array<string,array{show:bool,attrs:array<string,string>}>
     */
    public static function layout(array $fields, bool $structured, string $itemtype): array
    {
        $state = self::state($fields);
        $out = [];
        foreach (self::RULES as $key => $rule) {
            if (!self::rendered($key, $structured, $itemtype)) {
                continue;
            }
            $show = self::visible($key, $state, $structured);
            $attrs = ['data-itilflow-field' => $key];
            if (isset($rule['m'])) {
                $attrs['data-itilflow-m'] = implode(' ', $rule['m']);
            }
            if (isset($rule['c'])) {
                $attrs['data-itilflow-c'] = $rule['c'];
            }
            if (!$show) {
                $attrs['hidden'] = 'hidden';
            }
            $out[$key] = ['show' => $show, 'attrs' => $attrs];
        }
        return $out;
    }

    /**
     * Статус заявки по умолчанию для нового этапа — по режиму, если такой статус
     * есть у типа объекта: согласование — «Согласование», дочерняя — «Ожидание»,
     * задача — «В работе (назначена)».
     *
     * @param array<int,string> $choices
     * @return array<string,int>
     */
    public static function statusByMode(array $choices): array
    {
        $pick = static fn(int $s): int => array_key_exists($s, $choices) ? $s : 0;
        return [
            Stage::MODE_APPROVAL  => $pick(\CommonITILObject::APPROVAL),
            Stage::MODE_DELEGATED => $pick(\CommonITILObject::WAITING),
            Stage::MODE_INLINE    => $pick(\CommonITILObject::ASSIGNED),
        ];
    }
}
