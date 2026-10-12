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
    private const RULES = [
        'name'                             => ['place' => 'main'],
        'execution_mode'                   => ['place' => 'main'],
        'itilcategories_id'                => ['place' => 'main', 'm' => [Stage::MODE_DELEGATED]],
        'completion_comment'               => ['place' => 'main', 'm' => [Stage::MODE_INLINE]],
        'content'                          => ['place' => 'main'],
        'approver_source'                  => ['place' => 'main', 'm' => [Stage::MODE_APPROVAL]],
        'groups_id'                        => ['place' => 'main', 'c' => 'apfixed'],
        'users_id'                         => ['place' => 'main', 'c' => 'apfixed'],
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
            'apfixed'    => $state['mode'] !== Stage::MODE_APPROVAL || $state['approver'] === Stage::APPROVER_FIXED,
            'wherefixed' => $state['where'] === 'fixed',
            'back'       => $state['onrej'] === Stage::REJECT_BACK,
            'visa'       => $state['mode'] === Stage::MODE_APPROVAL
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
