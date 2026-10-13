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

// Этапы, хоть раз сохранённые формой 1.8.0: список точек возврата там пустой («[]»), а не NULL —
// форма всегда отправляла этот список. Пустой список — «не задано»: перевод ставит точку
// возврата так же, как при NULL.
$plan18 = Process::conversionPlan([
    $etap(1, 10, 'inline', ['return_targets' => '[]']),
    $etap(2, 20, 'approval', ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 1, 'return_targets' => '[]']),
    $etap(3, 30, 'delegated', ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 1, 'return_targets' => '[]']),
    $etap(4, 40, 'approval', ['on_reject' => 'abort', 'return_targets' => '[]']),
]);
$p18 = array_column($plan18, null, 'id');
ok($p18[2]['kind'] === 'back' && $p18[2]['set'] === ['negative_policy' => 'block', 'return_targets' => '[1]'],
    'форма 1.8.0, точек «[]»: «вернуться на 10» — точка возврата 10', json_encode($p18[2], JSON_UNESCAPED_UNICODE));
ok($p18[3]['set'] === ['negative_policy' => 'block', 'return_targets' => '[1]', 'department_result_source' => 'assignee_action'],
    'форма 1.8.0, точек «[]»: дочерняя с «вернуться на 10» — точка возврата 10', json_encode($p18[3], JSON_UNESCAPED_UNICODE));
ok($p18[1]['set'] === ['negative_policy' => 'block'], 'форма 1.8.0: задача с «[]» — точки возврата не трогаются',
    json_encode($p18[1], JSON_UNESCAPED_UNICODE));
ok($p18[4]['set'] === ['negative_policy' => 'abort'], 'форма 1.8.0: «прервать» с «[]» — точки возврата не трогаются',
    json_encode($p18[4], JSON_UNESCAPED_UNICODE));
$l18 = Process::conversionLines($plan18, 0);
ok(in_array('Этапы 20 («Вернуться на этап 10») и 30 («Вернуться на этап 10»): при отказе — «Остановить маршрут», точка возврата — 10.', $l18, true),
    'диалог формы 1.8.0: точка возврата 10 — та, что запишется', implode(' | ', $l18));

// Заранее выбранное «что делать при отказе» и отмеченные точки возврата не меняются —
// диалог говорит то, что останется в этапе.
$planV = Process::conversionPlan([
    $etap(1, 10, 'approval', ['on_reject' => 'abort', 'negative_policy' => 'block']),
    $etap(2, 20, 'approval', ['on_reject' => 'block', 'negative_policy' => 'abort']),
    $etap(3, 30, 'approval', ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 1, 'negative_policy' => 'abort']),
    $etap(4, 40, 'approval', ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 1, 'return_targets' => '[2]']),
]);
$pV = array_column($planV, null, 'id');
ok(($pV[1]['set'] ?? null) === ['return_targets' => '[]'] && ($pV[1]['policy'] ?? '') === 'block' && $pV[1]['kind'] === 'block',
    '«прервать», но заранее выбрано «остановить» — остаётся «остановить»', json_encode($pV[1], JSON_UNESCAPED_UNICODE));
ok(($pV[2]['policy'] ?? '') === 'abort' && $pV[2]['kind'] === 'abort',
    '«остановить», но заранее выбрано «ответить отказом» — остаётся «ответить отказом»', json_encode($pV[2], JSON_UNESCAPED_UNICODE));
ok($pV[4]['set'] === ['negative_policy' => 'block'] && ($pV[4]['to'] ?? null) === [20],
    'заранее отмеченная точка возврата не меняется', json_encode($pV[4], JSON_UNESCAPED_UNICODE));
$lV = Process::conversionLines($planV, 0);
ok(in_array('Этап 10: при отказе — «Остановить маршрут».', $lV, true),
    'диалог: «прервать» с заранее выбранным «остановить» — «Остановить маршрут»', implode(' | ', $lV));
ok(in_array('Этап 20: при отказе — «Ответить заявителю отказом и завершить маршрут».', $lV, true),
    'диалог: «остановить» с заранее выбранным «ответить отказом» — «Ответить заявителю отказом…»', implode(' | ', $lV));
ok(in_array('Этап 30 («Вернуться на этап 10»): при отказе — «Ответить заявителю отказом и завершить маршрут», точка возврата — 10.', $lV, true),
    'диалог: «вернуться» с заранее выбранным «ответить отказом» — отказ и точка возврата', implode(' | ', $lV));
ok(in_array('Этап 40 («Вернуться на этап 10»): при отказе — «Остановить маршрут», точка возврата — 20.', $lV, true),
    'диалог: заранее отмеченная точка возврата — та, что останется', implode(' | ', $lV));

itog();
