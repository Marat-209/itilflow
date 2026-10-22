<?php
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\Sheet;
use GlpiPlugin\Itilflow\Step;
$loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates');
$loader->addPath(dirname(__DIR__, 2) . '/templates', 'itilflow');
$twig = new \Twig\Environment($loader, ['autoescape'=>'html','strict_variables'=>true]);
foreach (['csrf_token','call'] as $fn) { $twig->addFunction(new \Twig\TwigFunction($fn, static fn()=>'')); }
$base = ['command'=>['key'=>'test','attempt_id'=>1], 'instance'=>['fields'=>['id'=>1]], 'r'=>['step_id'=>2],
    'decision_url'=>'/decision', 'step_url'=>'/step', 'outcome_url'=>'/outcome'];
$a = ['action'=>'decision','decision_items'=>[['key'=>'ready','label'=>'<script>Ready?</script>']],
    'decision_answers'=>[], 'decision_revision'=>7, 'can_approve'=>false, 'validation_id'=>0];
$html = $twig->render('flow_action.html.twig', $base + ['a'=>$a]);
ok(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'подпись решения экранирована настоящим Twig');
ok(str_contains($html, 'value="" selected') && !str_contains($html, 'value="0" selected'), 'неотвеченный вопрос не предвыбран как нет');
ok(str_contains($html, 'name="expected_decision_revision" value="7"'), 'форма передаёт исходную ревизию решения');
ok(!str_contains($html, 'value="approve"'), 'без native права нет кнопки совместного согласования');
$a['decision_answers'] = ['ready'=>false]; $a['can_approve']=true; $a['validation_id']=19;
$html = $twig->render('flow_action.html.twig', $base + ['a'=>$a]);
ok(str_contains($html, 'value="0" selected') && str_contains($html, 'name="validation_id" value="19"'), 'false сохраняет смысл и native запрос передаётся явно');
$a = ['action'=>'condition','input_revision'=>'<revision>','can_skip'=>false];
$html = $twig->render('flow_action.html.twig', $base + ['a'=>$a]);
ok(str_contains($html, 'name="choice" value="run"') && !str_contains($html, 'name="choice" value="skip"'), 'согласование без skip-права даёт только выполнить');
ok(str_contains($html, '&lt;revision&gt;') && str_contains($html, 'name="condition_reason" rows="2" required'), 'выбор UNKNOWN передаёт ревизию и обязательное основание');
$a['can_skip']=true;
ok(str_contains($twig->render('flow_action.html.twig', $base + ['a'=>$a]), 'name="choice" value="skip"'), 'разрешённый пропуск явно называется не требуется');
ok(!Sheet::wasOverdue(['state'=>Step::NOT_NEEDED,'date_due'=>'2000-01-01','date_end'=>'2020-01-01']), 'не требуется не считается просроченным даже при повреждённых датах');
$a = ['action'=>'outcome','can_return'=>false,'decision_items'=>[['key'=>'ready','label'=>'Готово']],
    'decision_answers'=>[], 'decision_revision'=>3];
$html = $twig->render('flow_action.html.twig', $base + ['a'=>$a]);
// 1.13: ответы этапа дочерней заявки — прямо в «Отметить результат», без отдельного «Сохранить ответы».
ok(str_contains($html, 'name="expected_decision_revision" value="3"') && str_contains($html, 'name="decision_answers[ready]"')
    && str_contains($html, 'value="" selected') && !str_contains($html, 'value="0" selected'),
    'ответы этапа — в «Отметить результат», без ответа не предвыбрано «нет», номер изменения передан');
$dom = new \DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html); $xp = new \DOMXPath($dom);
$refuse = $xp->query('//button[@name="itilflow_do"][@value="refuse"]')->item(0);
ok($refuse !== null && $refuse->hasAttribute('formnovalidate') && $xp->query('//select[@name="decision_answers[ready]"][@required]')->length === 1,
    '«Выполнено» требует ответов, «Отказ» отправляется без них');
ok(!str_contains($html, 'Перед выполнением сохраните') && !str_contains($html, 'Сохранить ответы'),
    'нет подсказки про отдельное сохранение ответов перед результатом');
// Настоящий шаблон: общая сводка не раскрывает скрытые этапы через другой интерфейс.
$flow = ['process_name'=>'Маршрут', 'only'=>false, 'parent_link'=>'', 'state'=>['В работе','bg-blue-lt',''],
    'enforcement'=>'', 'sheet_url'=>'', 'header_menu'=>[], 'can_resume'=>false, 'rows'=>[],
    'withdraw'=>false, 'violations'=>[], 'is_staff'=>true,
    'required_progress'=>Sheet::requiredProgress([['state'=>Step::DONE], ['state'=>Step::NOT_NEEDED],
        ['state'=>Step::SUPERSEDED, 'date_start'=>'2026-01-01 10:00:00']])];
$html = $twig->render('flow_tab.html.twig', $flow);
$svodka = static fn(string $h): string => preg_match('/<div class="small text-muted itilflow-progress">(.*?)<\/div>/su', $h, $m) ? $m[1] : '';
// 1.13.1: сводка вкладки — одна строка; «Не закончены» и прочее — в листе согласования.
ok($svodka($html) === 'Нужно пройти: 2 из 3 этапов (1 не нужен по условию).' && !str_contains($html, 'Не закончены'),
    'сводка вкладки — одна строка: требовавшийся прерванный этап нужен, не нужный по условию назван', $svodka($html));
ok(!str_contains($html, 'прохожд') && !str_contains($html, 'проходов'), 'в сводке нет «прохождений» и «проходов»');
$s5 = $svodka($twig->render('flow_tab.html.twig', array_replace($flow, ['required_progress' => Sheet::requiredProgress([
    ['state'=>Step::DONE], ['state'=>Step::RUNNING], ['state'=>Step::PENDING], ['state'=>Step::PENDING, 'conditional'=>true],
    ['state'=>Step::NOT_NEEDED]])])));
ok($s5 === 'Нужно пройти: 3 из 5 этапов (1 не нужен по условию). Зависят от условий: 1.',
    'безусловный будущий этап — в «нужно пройти», условный — «зависят от условий», без «ещё не ясно»', $s5);
$s1 = $svodka($twig->render('flow_tab.html.twig', array_replace($flow, ['required_progress' => Sheet::requiredProgress([
    ['state'=>Step::RUNNING], ['state'=>Step::PENDING]])])));
ok($s1 === 'Нужно пройти: 2 из 2 этапов.', 'без «не нужных» и условий — без скобок и хвоста', $s1);
ok(!str_contains($twig->render('flow_tab.html.twig', array_replace($flow, ['is_staff'=>false])), 'Нужно пройти'),
    'инициатор не получает сводку скрытых этапов даже при переданном счётчике');
ok(!str_contains($twig->render('flow_tab.html.twig', array_replace($flow, ['only'=>true])), 'Нужно пройти'),
    'вкладка дочерней заявки не раскрывает сводку всего маршрута');
itog();
