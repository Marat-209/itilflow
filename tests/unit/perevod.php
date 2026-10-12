<?php

/**
 * «Перевести на новый порядок» заполняет поля этапов из прежних.
 *
 * Соответствие: «прервать» → «ответить отказом»; «остановить» →
 * «остановить»; «вернуться на N» → «остановить» + точка возврата N; дочерняя →
 * «исполнитель отмечает результат»; задача → «остановить», без точек.
 * Заполняется только пустое: то, что уже подготовлено в этапе, не меняется.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Process;

if (!method_exists(Process::class, 'conversionPlan')) {
    ok(false, 'есть Process::conversionPlan');
    itog();
}

$etap = static fn(int $id, int $rank, string $mode, array $f = []): array => $f + [
    'id' => $id, 'ranking' => $rank, 'name' => 'Этап ' . $rank, 'execution_mode' => $mode,
    'on_reject' => 'block', 'plugin_itilflow_stages_id_reject' => 0,
    'negative_policy' => '', 'department_result_source' => '', 'return_targets' => null,
];

$plan = Process::conversionPlan([
    $etap(1, 10, 'inline'),
    $etap(2, 20, 'approval', ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 1]),
    $etap(3, 25, 'approval', ['on_reject' => 'abort']),
    $etap(4, 30, 'approval', ['on_reject' => 'block']),
    $etap(5, 40, 'delegated', ['on_reject' => 'block']),
    $etap(6, 50, 'approval', ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 7]),
    $etap(7, 60, 'delegated', ['on_reject' => 'abort', 'negative_policy' => 'block', 'department_result_source' => 'native_validation',
        'return_targets' => '[7]']),
    $etap(8, 70, 'approval', ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 999]),
]);
$po = array_column($plan, null, 'id');

ok(count($plan) === 8, 'план на каждый этап');
ok($po[1]['kind'] === 'task' && $po[1]['set'] === ['negative_policy' => 'block', 'return_targets' => '[]'],
    'задача — «остановить», без точек возврата', json_encode($po[1], JSON_UNESCAPED_UNICODE));
ok($po[2]['kind'] === 'back' && $po[2]['from'] === 10 && $po[2]['set'] === ['negative_policy' => 'block', 'return_targets' => '[1]'],
    '«вернуться на 10» — «остановить» и точка возврата 10', json_encode($po[2], JSON_UNESCAPED_UNICODE));
ok($po[3]['kind'] === 'abort' && $po[3]['set'] === ['negative_policy' => 'abort', 'return_targets' => '[]'],
    '«прервать» — «ответить заявителю отказом»', json_encode($po[3], JSON_UNESCAPED_UNICODE));
ok($po[4]['kind'] === 'block' && $po[4]['set'] === ['negative_policy' => 'block', 'return_targets' => '[]'],
    '«остановить» — «остановить»', json_encode($po[4], JSON_UNESCAPED_UNICODE));
ok($po[5]['set'] === ['negative_policy' => 'block', 'return_targets' => '[]', 'department_result_source' => 'assignee_action'],
    'дочерняя — «исполнитель отмечает результат»', json_encode($po[5], JSON_UNESCAPED_UNICODE));
ok($po[6]['kind'] === 'back_lost' && $po[6]['set'] === ['negative_policy' => 'block', 'return_targets' => '[]'],
    '«вернуться» на более поздний этап — без точки возврата, с пометкой', json_encode($po[6], JSON_UNESCAPED_UNICODE));
ok($po[7]['kind'] === 'kept' && $po[7]['set'] === [],
    'подготовленные заранее поля не меняются', json_encode($po[7], JSON_UNESCAPED_UNICODE));
ok($po[8]['kind'] === 'back_lost', '«вернуться» на удалённый этап — без точки возврата', json_encode($po[8], JSON_UNESCAPED_UNICODE));

itog();
