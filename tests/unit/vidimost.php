<?php

/**
 * Форма этапа показывает только поля выбранного режима и порядка маршрута.
 *
 * Ожидания:
 * З — задача в заявке, Д — дочерняя заявка, С — согласование; П — прежний
 * порядок исходов, Н — новый. Скрытое поле не отправляется формой, поэтому
 * его значение в базе не меняется.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\StageForm;

if (!estKlass(StageForm::class)) {
    itog();
}

$etap = static fn(array $f): array => $f + [
    'execution_mode' => 'inline', 'approver_source' => 'fixed', 'entity_strategy' => 'inherit',
    'department_result_source' => '', 'on_reject' => 'block',
];

$sluchai = [
    ['С, Н, из формы', ['execution_mode' => 'approval', 'approver_source' => 'form'], true, 'Ticket',
        ['name', 'execution_mode', 'content', 'approver_source', 'form_validationsteps_id', 'deadline', 'negative_policy', 'return_targets'],
        ['ranking', 'ticket_status']],
    // [название, поля этапа, новый порядок, тип маршрута, основные поля, «Дополнительно»]
    ['З, Н', ['execution_mode' => 'inline'], true, 'Ticket',
        ['name', 'execution_mode', 'completion_comment', 'content', 'groups_id', 'users_id', 'deadline'],
        ['ranking', 'ticket_status', 'duration', 'is_optional', 'return_targets_retry']],
    ['З, П', ['execution_mode' => 'inline'], false, 'Ticket',
        ['name', 'execution_mode', 'completion_comment', 'content', 'groups_id', 'users_id', 'deadline'],
        ['ranking', 'ticket_status', 'duration', 'is_optional']],
    ['Д, Н, исполнитель отмечает', ['execution_mode' => 'delegated', 'department_result_source' => 'assignee_action'], true, 'Ticket',
        ['name', 'execution_mode', 'itilcategories_id', 'content', 'groups_id', 'users_id', 'entity_strategy',
            'department_result_source', 'deadline', 'negative_policy', 'return_targets'],
        ['ranking', 'ticket_status', 'slas_id']],
    ['Д, Н, визой, в указанной организации', ['execution_mode' => 'delegated', 'department_result_source' => 'native_validation',
        'entity_strategy' => 'fixed'], true, 'Ticket',
        ['name', 'execution_mode', 'itilcategories_id', 'content', 'groups_id', 'users_id', 'entity_strategy',
            'entities_id_target', 'department_result_source', 'deadline', 'negative_policy', 'return_targets'],
        ['ranking', 'ticket_status', 'slas_id', 'approval_percent']],
    ['Д, П, вернуться на этап', ['execution_mode' => 'delegated', 'on_reject' => 'back'], false, 'Ticket',
        ['name', 'execution_mode', 'itilcategories_id', 'content', 'groups_id', 'users_id', 'entity_strategy',
            'deadline', 'on_reject', 'plugin_itilflow_stages_id_reject'],
        ['ranking', 'ticket_status', 'slas_id']],
    ['Д, Н, изменение — SLA нет', ['execution_mode' => 'delegated', 'department_result_source' => 'assignee_action'], true, 'Change',
        ['name', 'execution_mode', 'itilcategories_id', 'content', 'groups_id', 'users_id', 'entity_strategy',
            'department_result_source', 'deadline', 'negative_policy', 'return_targets'],
        ['ranking', 'ticket_status']],
    ['С, Н, при прохождении', ['execution_mode' => 'approval', 'approver_source' => 'runtime'], true, 'Ticket',
        ['name', 'execution_mode', 'content', 'approver_source', 'deadline', 'negative_policy', 'return_targets'],
        ['ranking', 'ticket_status', 'approval_percent']],
    ['С, Н, указан в этапе', ['execution_mode' => 'approval', 'approver_source' => 'fixed'], true, 'Ticket',
        ['name', 'execution_mode', 'content', 'approver_source', 'approver_users', 'approver_groups', 'approver_group_mode', 'deadline',
            'negative_policy', 'return_targets'],
        ['ranking', 'ticket_status', 'approval_percent']],
    ['С, П, остановить', ['execution_mode' => 'approval', 'approver_source' => 'fixed'], false, 'Ticket',
        ['name', 'execution_mode', 'content', 'approver_source', 'approver_users', 'approver_groups', 'approver_group_mode', 'deadline', 'on_reject'],
        ['ranking', 'ticket_status', 'approval_percent']],
];

foreach ($sluchai as [$imya, $polya, $novyj, $tip, $osnovnye, $dop]) {
    $vid = StageForm::visibleFields($etap($polya), $novyj, $tip);
    ok($vid['main'] === $osnovnye, $imya . ': основные поля',
        'получено ' . implode(',', $vid['main']) . '; ждали ' . implode(',', $osnovnye));
    ok($vid['more'] === $dop, $imya . ': «Дополнительно»',
        'получено ' . implode(',', $vid['more']) . '; ждали ' . implode(',', $dop));
}

// Сколько полей видно: 7–12 в основной части при 22 прежде.
$min = PHP_INT_MAX;
$max = 0;
foreach (['inline', 'delegated', 'approval'] as $m) {
    foreach (['fixed', 'runtime'] as $a) {
        foreach (['inherit', 'fixed'] as $w) {
            foreach (['assignee_action', 'native_validation'] as $r) {
                foreach ([true, false] as $novyj) {
                    $n = count(StageForm::visibleFields($etap(['execution_mode' => $m, 'approver_source' => $a,
                        'entity_strategy' => $w, 'department_result_source' => $r, 'on_reject' => 'back']), $novyj, 'Ticket')['main']);
                    $min = min($min, $n);
                    $max = max($max, $n);
                }
            }
        }
    }
}
ok($min >= 6 && $max <= 12, 'в основной части от 6 до 12 полей', $min . '–' . $max);

// Поля одного порядка не появляются у другого.
$prezhnie = ['on_reject', 'plugin_itilflow_stages_id_reject'];
$novye = ['department_result_source', 'negative_policy', 'return_targets', 'return_targets_retry'];
$smesh = [];
foreach (['inline', 'delegated', 'approval'] as $m) {
    $n = StageForm::visibleFields($etap(['execution_mode' => $m, 'on_reject' => 'back']), true, 'Ticket');
    $p = StageForm::visibleFields($etap(['execution_mode' => $m, 'on_reject' => 'back']), false, 'Ticket');
    $smesh = array_merge($smesh, array_intersect(array_merge($n['main'], $n['more']), $prezhnie),
        array_intersect(array_merge($p['main'], $p['more']), $novye));
}
ok($smesh === [], 'поля прежнего порядка только у старых маршрутов, нового — только у новых', implode(',', $smesh));

// Правила для скрипта формы — те же, что для сервера.
$pravila = StageForm::rules();
ok(isset($pravila['groups_id']['c']) && $pravila['groups_id']['c'] === 'notapproval', 'прежняя группа — только исполнитель, не согласующий');
ok(($pravila['entities_id_target']['m'] ?? null) === ['delegated'], 'организация — только у дочерней заявки');

itog();
