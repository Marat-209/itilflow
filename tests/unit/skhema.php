<?php

/** Чистая модель схемы и настоящий Twig: снимок, длинные возвраты, экранирование. Без БД. */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Scheme;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// В этой проверке не вызываются интеграционные методы вкладки CommonGLPI.
$process = ['name' => 'Синтетический маршрут', 'version' => 3, 'outcome_contract' => 'structured_v1'];
$stages = [
    ['id' => 3, 'ranking' => 30, 'name' => 'Завершение', 'execution_mode' => 'inline', 'return_targets' => '[1,2]', 'negative_policy' => 'abort'],
    ['id' => 1, 'ranking' => 10, 'name' => 'Подготовка', 'execution_mode' => 'inline', 'return_targets' => '[]', 'negative_policy' => 'block'],
    ['id' => 2, 'ranking' => 20, 'name' => 'Проверка', 'execution_mode' => 'approval', 'return_targets' => '[1,2]', 'negative_policy' => 'abort'],
];
$options = ['subtitle' => 'Снимок редакции 3. Демонстрационные данные.', 'actor_labels' => [1 => 'Исполнитель', 2 => 'Группа проверки'],
    'states' => [1 => ['state' => 'done', 'label' => 'Выполнено', 'is_current' => false],
        2 => ['state' => 'running', 'label' => 'Попытка 2 · ожидается ответ', 'is_current' => true]],
    'urls' => [1 => '/plugins/itilflow/front/stage.form.php?id=1'], 'day_minutes' => 480,
    'history' => [['from_id' => 2, 'to_id' => 1, 'label' => 'Выполненный возврат, попытка 1']]];
$loader = new FilesystemLoader();
$loader->addPath(dirname(__DIR__, 2) . '/templates', 'itilflow');
$twig = new Environment($loader, ['autoescape' => 'html', 'strict_variables' => true, 'cache' => false]);
$render = static fn(array $model): string => $twig->render('@itilflow/scheme.html.twig', ['scheme' => $model]);
$model = Scheme::build($process, $stages, $options);

// Экспорт для отдельной визуальной проверки: только синтетика, stdout, без записи в рабочую копию.
if (in_array('--html', $argv, true)) {
    $long = [];
    for ($n = 1; $n <= 24; $n++) {
        $long[] = ['id' => $n, 'ranking' => $n * 10, 'name' => 'Этап ' . $n . ' — проверка длинного переносимого названия без сокращения смысла',
            'execution_mode' => $n % 3 === 0 ? 'delegated' : ($n % 2 === 0 ? 'approval' : 'inline'),
            'return_targets' => json_encode($n > 1 ? range(1, $n - 1) : []), 'negative_policy' => 'abort'];
    }
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка схемы</title><body style="margin:0;font-family:system-ui">';
    echo $render($model);
    echo '<div style="width:375px;max-width:100%;--tblr-bg-surface:#242424;--tblr-body-color:#eeeeee;--tblr-border-color:#777;--tblr-link-color:#9fc7ff;--tblr-primary:#a8caff;background:#242424">';
    echo $render($model), '</div>', $render(Scheme::build($process, $long));
    $statusRows = []; $statusOptions = ['states' => []];
    foreach (['done' => 'Выполнено', 'refused' => 'Отказ', 'notrun' => 'Не выполнялся', 'not_needed' => 'Не потребовался', 'pending' => 'Ожидает'] as $kind => $label) {
        $n = count($statusRows) + 1;
        $statusRows[] = ['id' => $n, 'ranking' => $n * 10, 'name' => 'Пример состояния', 'execution_mode' => 'inline'];
        $statusOptions['states'][$n] = ['state' => $kind, 'label' => $label];
    }
    echo $render(Scheme::build(['name' => 'Образцы состояний, не исполняемый процесс'], $statusRows, $statusOptions));
    $conditionalRows = $stages;
    $conditionalRows[2]['run_if'] = ['future_schema' => true];
    echo $render(Scheme::build($process, $conditionalRows, ['conditions' => [2 => ['supported' => true, 'text' => 'Проверенное условие']]])), '</body></html>';
    exit;
}

ok(array_column($model['rows'], 'ranking') === [10, 20, 30], 'этапы упорядочены по номеру, а не входному массиву');
ok(count(array_filter($model['rows'], static fn($r) => $r['is_current'])) === 1, 'текущий этап один');
ok($model['rows'][1]['state_label'] === 'Попытка 2 · ожидается ответ', 'повторная попытка видна текстом');
$saved = $stages;
$stages[1]['name'] = 'Изменённая текущая настройка';
$snapshot = Scheme::build($process, $saved, $options);
ok($snapshot['rows'][0]['name'] === 'Подготовка', 'построение по переданному снимку не подменяет его текущей настройкой');
ok($snapshot === $model, 'повторное построение того же снимка детерминировано');
$withoutLinks = Scheme::build($process, $saved);
ok(count(array_filter($withoutLinks['rows'], static fn($r) => !empty($r['url']))) === 0, 'без выданных интеграцией ссылок этапы не дают переход в редактор');
$html = $render($model);
ok(str_contains($html, 'aria-current="step"') && str_contains($html, 'Попытка 2'), 'Twig выводит текущий шаг и попытку');
ok(str_contains($html, 'role="list"') && str_contains($html, 'aria-hidden="true"'), 'список этапов семантический, декоративные линии скрыты от чтения');
ok(str_contains($html, 'scope="row"') && str_contains($html, 'Текстовое описание схемы'), 'доступна текстовая таблица переходов');

$evil = $saved;
$evil[1]['name'] = '<img src=x onerror=alert(1)>';
$bad = Scheme::build(['name' => '<script>alert(1)</script>'] + $process, $evil,
    ['actor_labels' => [1 => '<svg onload=alert(1)>'], 'problems' => [1 => ['<iframe src=x>']],
     'urls' => [1 => 'javascript:alert(1)', 2 => '//foreign.example/']]);
$escaped = $render($bad);
ok(!str_contains($escaped, '<script>') && !str_contains($escaped, '<img ') && !str_contains($escaped, '<svg ') && !str_contains($escaped, '<iframe '), 'имя, заголовок, исполнитель и проблема экранируются настоящим Twig');
ok(str_contains($escaped, '&lt;img') && str_contains($escaped, '&lt;script'), 'опасный текст не пропадает и остаётся читаемым');
ok(!str_contains($escaped, 'href="javascript:') && !str_contains($escaped, 'href="//'), 'небезопасные и внешние ссылки не попадают в схему');

$dense = [];
for ($n = 1; $n <= 20; $n++) {
    $dense[] = ['id' => $n, 'ranking' => $n * 10, 'name' => 'Этап ' . $n, 'execution_mode' => 'approval',
        'return_targets' => json_encode($n > 1 ? range(1, $n - 1) : []), 'negative_policy' => 'abort'];
}
$crowded = Scheme::build($process, $dense);
ok($crowded['left_lanes'] <= 4 && $crowded['right_lanes'] <= 4, 'число дорожек ограничено четырьмя с каждой стороны');
ok($crowded['hidden_edges'] > 0, 'плотная схема считает неуместившиеся линии');
$overflowHtml = $render($crowded);
ok(str_contains($overflowHtml, 'Не все линии нарисованы'), 'ограничение линий показано видимым предупреждением');
ok(count($crowded['rows']) === 20 && !empty($crowded['rows'][19]['transition_text']), 'ограничение линий не удаляет этапы и текст переходов');
$empty = $render(Scheme::build($process, []));
ok(str_contains($empty, 'В маршруте пока нет этапов'), 'пустой маршрут даёт понятное сообщение');

$legacy = Scheme::build(['name' => 'Прежний порядок'], [
    ['id' => 1, 'ranking' => 10, 'name' => 'Начало', 'execution_mode' => 'inline'],
    ['id' => 2, 'ranking' => 20, 'name' => 'Проверка', 'execution_mode' => 'approval', 'on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 1],
]);
ok(count($legacy['edges']) === 1 && $legacy['edges'][0]['kind'] === 'reject', 'прежний возврат при отказе отличим от уточнения');
ok(str_contains($legacy['rows'][1]['transition_text'], 'этап 10'), 'возврат подписан номером этапа, а не внутренним id');
$broken = Scheme::build($process, [['id' => 1, 'ranking' => 10, 'name' => 'Проверка', 'execution_mode' => 'approval', 'negative_policy' => 'abort', 'return_targets' => '[999]']]);
ok($broken['edges'] === [] && $broken['rows'][0]['problems'] !== [], 'несуществующая точка возврата показана проблемой, линия не выдумана');
$plainHtml = $render(Scheme::build($process, [['id' => 1, 'ranking' => 10, 'name' => 'Один этап', 'execution_mode' => 'inline']]));
ok(!str_contains($plainHtml, 'Пунктирная линия слева') && !str_contains($plainHtml, 'Линия справа'), 'легенда не обещает отсутствующие линии');

$deadlineStage = [['id' => 1, 'ranking' => 10, 'name' => 'Со сроком', 'execution_mode' => 'inline', 'deadline_minutes' => 480]];
$noCalendar = Scheme::build($process, $deadlineStage);
$calendar = Scheme::build($process, $deadlineStage, ['day_minutes' => 480]);
ok($noCalendar['rows'][0]['deadline_label'] === '8 ч', 'без рабочего календаря 480 минут — 8 часов, не 480 рабочих дней');
ok($calendar['rows'][0]['deadline_label'] === '1 р. д.', 'с календарём 480 минут в рабочем дне срок равен одному дню');
$anchor = Scheme::build($process, $saved, ['urls' => [1 => '#itilflow-row-42']]);
ok($anchor['rows'][0]['url'] === '#itilflow-row-42', 'ссылка экземпляра может вести к существующей строке таблицы');
ok(str_contains($html, 'ifsc-history') && str_contains($html, 'выполненный возврат из истории'), 'выполненный возврат имеет отдельные стиль и легенду');
ok(str_contains($model['rows'][1]['transition_text'], 'Выполненный возврат, попытка 1'), 'исторический возврат доступен текстом, не только линией');

$conditionStages = $saved;
$conditionStages[2]['run_if'] = ['future_schema' => true]; // Второй по порядку этап; движок условий здесь не подменяется.
$unknownCondition = Scheme::build($process, $conditionStages);
$knownCondition = Scheme::build($process, $conditionStages, ['conditions' => [2 => ['supported' => true, 'text' => 'Проверенное условие']]]);
ok(count(array_filter($unknownCondition['edges'], static fn($e) => $e['side'] === 'right')) === 0, 'неизвестный формат условия не рисует выдуманный обход');
ok(count(array_filter($knownCondition['edges'], static fn($e) => $e['side'] === 'right')) === 1, 'проверенный пересказ среднего условного этапа даёт правый обход');
ok(str_contains($render($knownCondition), 'Проверенное условие'), 'проверенное условие читается текстом');
$singleConditional = Scheme::build($process, [['id' => 1, 'ranking' => 10, 'name' => 'Условный', 'execution_mode' => 'inline', 'run_if' => ['future_schema' => true]]], ['conditions' => [1 => ['supported' => true, 'text' => 'Проверенное условие']]]);
ok($singleConditional['edges'] === [], 'единственный условный этап не получает фиктивную цель обхода');

$sharedTarget = Scheme::build($process, [
    ['id' => 1, 'ranking' => 10, 'name' => 'Начало', 'execution_mode' => 'inline'],
    ['id' => 2, 'ranking' => 20, 'name' => 'Проверка 1', 'execution_mode' => 'approval', 'negative_policy' => 'abort', 'return_targets' => '[1]'],
    ['id' => 3, 'ranking' => 30, 'name' => 'Проверка 2', 'execution_mode' => 'approval', 'negative_policy' => 'abort', 'return_targets' => '[1]'],
]);
ok($sharedTarget['left_lanes'] === 1 && count($sharedTarget['edges']) === 2, 'возвраты к общей цели используют одну дорожку без потери переходов');
$runtime = Scheme::build($process, $saved, ['runtime' => true,
    'states' => [1 => ['state' => 'done', 'label' => 'Выполнено'], 2 => ['state' => 'refused', 'label' => 'Отказ'], 3 => ['state' => 'notrun', 'label' => 'Не выполнялся']],
    'history' => [['from_id' => 2, 'to_id' => 1, 'label' => 'Выполненный возврат']]]);
ok(count(array_filter($runtime['edges'], static fn($e) => $e['kind'] === 'return')) === 0, 'у завершённых строк в runtime не нарисованы неиспользованные возможные возвраты');
ok(count(array_filter($runtime['edges'], static fn($e) => $e['kind'] === 'history')) === 1, 'фактический возврат сохраняется после завершения строки');
$runtimeHtml = $render($runtime);
ok(str_contains($runtimeHtml, 'ifsc-refused"') && str_contains($runtimeHtml, 'ifsc-skipped"'), 'канонические refused/notrun получают отличимые рамки');

$consecutive = [
    ['id' => 1, 'ranking' => 10, 'name' => 'Начало', 'execution_mode' => 'inline'],
    ['id' => 2, 'ranking' => 20, 'name' => 'Условный 1', 'execution_mode' => 'inline', 'run_if' => ['future_schema' => true]],
    ['id' => 3, 'ranking' => 30, 'name' => 'Условный 2', 'execution_mode' => 'inline', 'run_if' => ['future_schema' => true]],
    ['id' => 4, 'ranking' => 40, 'name' => 'Конец', 'execution_mode' => 'inline'],
];
$consecutiveModel = Scheme::build($process, $consecutive, ['conditions' => [
    2 => ['supported' => true, 'text' => 'Первое условие'], 3 => ['supported' => true, 'text' => 'Второе условие']]]);
$right = array_values(array_filter($consecutiveModel['edges'], static fn($e) => $e['side'] === 'right'));
ok(count($right) === 1 && $right[0]['from_index'] === 0 && $right[0]['to_index'] === 3, 'последовательные условные этапы объединены одним обходом к следующему обычному этапу');
$unsupported = Scheme::build($process, $conditionStages, ['conditions' => [2 => ['supported' => false, 'text' => 'Не подтверждено']]]);
ok(count(array_filter($unsupported['edges'], static fn($e) => $e['side'] === 'right')) === 0, 'неподдержанное условие не превращается в вычисленный FALSE и обход');

// Реальный формат снимка 3: адаптер options.conditions не нужен ни подписи, ни обходу.
$schema3Stages = $consecutive;
$schema3Condition = ['version' => 1, 'join' => 'all', 'clauses' => [
    ['source' => 'decision', 'stage_id' => 1, 'key' => 'ready', 'operator' => 'eq', 'value' => true],
]];
$schema3Stages[0]['decision_items'] = [['key' => 'ready', 'label' => 'Нужна проверка']];
$schema3Stages[1]['run_if'] = json_encode($schema3Condition);
unset($schema3Stages[2]['run_if']);
$schema3Model = Scheme::build($process, $schema3Stages);
$schema3Right = array_values(array_filter($schema3Model['edges'], static fn($e) => $e['kind'] === 'bypass'));
ok(count($schema3Right) === 1 && $schema3Right[0]['side'] === 'right'
    && $schema3Right[0]['from_index'] === 0 && $schema3Right[0]['to_index'] === 2,
    'run_if снимка 3 без адаптера строит правый обход среднего этапа');
ok($schema3Model['rows'][1]['condition_text'] === GlpiPlugin\Itilflow\ConditionText::short($schema3Condition, $schema3Stages)
    && str_contains($render($schema3Model), 'Нужна проверка'), 'схема 3 сохраняет общий читаемый пересказ условия');
$schema3Stages[2]['run_if'] = $schema3Condition;
$schema3Model = Scheme::build($process, $schema3Stages);
$schema3Right = array_values(array_filter($schema3Model['edges'], static fn($e) => $e['kind'] === 'bypass'));
ok(count($schema3Right) === 1 && $schema3Right[0]['from_index'] === 0 && $schema3Right[0]['to_index'] === 3,
    'два последовательных условия схемы 3 имеют один общий обход');
$schema3Stages[1]['run_if'] = ['version' => 999];
unset($schema3Stages[2]['run_if']);
$schema3Model = Scheme::build($process, $schema3Stages);
ok(count(array_filter($schema3Model['edges'], static fn($e) => $e['kind'] === 'bypass')) === 0,
    'неподдержанное run_if не выдаётся за проверенный обход');
$actorStages = [
    ['id' => 1, 'ranking' => 10, 'execution_mode' => 'approval', 'groups_id' => 99,
        'approver_users' => '[7,8]', 'approver_groups' => '[]', 'approver_group_mode' => 'any'],
    ['id' => 2, 'ranking' => 20, 'execution_mode' => 'approval', 'users_id' => 99,
        'approver_users' => '[]', 'approver_groups' => '[4,5]', 'approver_group_mode' => 'each'],
    ['id' => 3, 'ranking' => 30, 'execution_mode' => 'inline', 'users_id' => 7],
    ['id' => 4, 'ranking' => 40, 'execution_mode' => 'delegated',
        'department_result_source' => 'native_validation', 'users_id' => 7, 'groups_id' => 4],
];
$actorNames = static fn(string $type, int $id): string => $type === 'Group'
    ? [4 => 'Проверка', 5 => 'Поддержка'][$id] : [7 => 'Первый согласующий', 8 => '<Второй>'][$id];
$labels = Scheme::actors($actorStages, $actorNames);
ok(str_contains($labels[1], 'Первый согласующий') && str_contains($labels[1], '<Второй>')
    && !str_contains($labels[1], '99'), 'схема берёт список пользователей 1.11 вместо старого поля группы');
ok(str_contains($labels[2], 'Проверка') && str_contains($labels[2], 'Поддержка')
    && str_contains($labels[2], 'каждый в группе'), 'схема показывает все выбранные группы и правило каждого');
ok(str_contains($labels[3], 'Первый согласующий'), 'задача сохраняет назначенного исполнителя');
$namedHtml = $render(Scheme::build($process, $actorStages, ['actor_labels' => $labels]));
ok(!str_contains($namedHtml, '<Второй>') && str_contains($namedHtml, '&lt;Второй&gt;'),
    'имена состава безопасно экранируются в схеме');
ok($labels[4] === 'Группа «Проверка»; Пользователь «Первый согласующий»',
    'дочерняя заявка с согласованием сохраняет группу и пользователя без правила любого');
$transition = Scheme::build($process, array_slice($schema3Stages, 0, 1));
$schema3Stages[1]['run_if'] = $schema3Condition;
$transition = Scheme::build($process, $schema3Stages)['rows'][1]['transition_text'];
ok(!str_contains($transition, 'Нужна проверка') && str_contains($transition, 'Если условие не выполнено: «Не нужен по условию», далее')
    && str_ends_with($transition, '.') && !str_contains($transition, 'Нет ответа'),
    'переход не повторяет условие и завершён читаемым предложением', $transition);
itog();
