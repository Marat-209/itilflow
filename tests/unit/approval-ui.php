<?php
/** Контракт ввода и подписей: без БД, реальные классы GLPI. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\Ui;
use GlpiPlugin\Itilflow\ApprovalRoster;
$n = Stage::normalizeApprovalInput(['approver_users' => ['', '12', '12', 25], 'approver_groups' => ['']]);
ok($n['approver_users'] === '[12,25]' && $n['approver_groups'] === '[]', 'multiple: числа, дубли и явное очищение');
ok(Stage::normalizeApprovalInput(['approver_users' => null])['approver_users'] === null, 'NULL сохраняет legacy fallback');
foreach ([['x'], [true], [1.5], [-2], ['1e2'], ['01'], ['<b>'], range(1, 51), ['x' => 1]] as $bad) {
    ok(Stage::normalizeApprovalInput(['approver_users' => $bad]) === null, 'неверный или чрезмерный список отклонён');
}
ok(Stage::normalizeApprovalInput(['approver_users' => range(1, 50)]) !== null, '50 адресатов допустимы');
$p = ApprovalRoster::progress(['accepted' => 2, 'refused' => 0, 'waiting' => 0, 'dropped' => 2, 'total' => 4], 75);
$s = Ui::approvalSummary($p);
ok(str_contains($s, 'Одобрили 2 из 4, нужно 3.') && str_contains($s, 'Снято без ответа: 2'), 'cleanup не уменьшает показанный знаменатель', $s);
$p = ApprovalRoster::progress(['accepted' => 2, 'refused' => 1, 'waiting' => 0, 'dropped' => 0, 'total' => 3], 67);
$s = Ui::approvalSummary($p);
ok(str_contains($s, 'GLPI не подвёл итог') && str_contains($s, 'администратор маршрутов'), 'все ответили, native WAITING явно объяснён', $s);
ok(!preg_match('/порог|штатн|администратор маршрута(?![а-яё])/u', $s), 'в итоге согласования — слова словаря');
ok(Ui::approvalSummary(['errors' => ['Состав неизвестен']]) === 'Состав неизвестен', 'ошибка не выдаётся за нулевой прогресс');
// Проверяем экранирование реальным Twig, включая подпись диагностической ошибки.
$t = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['line' => '{{ line }}']), ['autoescape' => 'html', 'strict_variables' => true]);
ok($t->render('line', ['line' => Ui::approvalSummary(['errors' => ['<script>"&']])]) === '&lt;script&gt;&quot;&amp;', 'динамические подписи экранируются Twig');
$loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates');
$t = new \Twig\Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]);
$t->addFunction(new \Twig\TwigFunction('call', static fn() => ''));
$t->addFunction(new \Twig\TwigFunction('__', static fn($s) => $s));
foreach (['path', 'csrf_token'] as $function) { $t->addFunction(new \Twig\TwigFunction($function, static fn() => '')); }
$t->addFilter(new \Twig\TwigFilter('safe_html', static fn($s) => $s));
foreach (['stage.html.twig', 'flow_action.html.twig', 'timeline_stage.html.twig', 'flow_tab.html.twig', 'sheet.html.twig'] as $file) {
    try { $t->parse($t->tokenize(new \Twig\Source(file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file), $file))); ok(true, 'Twig разбирает ' . $file); }
    catch (\Throwable $e) { ok(false, 'Twig разбирает ' . $file, $e->getMessage()); }
}
$html = $t->render('flow_action.html.twig', ['command' => null, 'instance' => ['fields' => ['id' => 1]], 'r' => ['step_id' => 2], 'a' => ['action' => 'set_approver'], 'step_url' => '/safe?x="<']);
ok(str_contains($html, 'name="appr_group_mode"') && str_contains($html, 'value="each"') && str_contains($html, 'value="any"'), 'реальный runtime шаблон выводит оба режима');
ok(str_contains($html, '/safe?x=&quot;&lt;') && !str_contains($html, 'action="/safe?x="<'), 'runtime action URL экранируется');
$html = $t->createTemplate("{% import 'stage.html.twig' as stage %}{{ stage.choices('mode', {'any': ['Любой', 'Один запрос'], 'each': ['Каждый', 'Состав фиксирован']}, 'each', false) }}")->render([]);
ok(str_contains($html, 'Любой') && str_contains($html, 'Каждый') && str_contains($html, 'Состав фиксирован'), 'макрос формы выводит полные подписи режимов');
// Пропущенный согласующий — по имени (без базы имя даёт замыкание, на сервере — ConditionText::name).
$imena = static fn(string $itemtype, int $id): string => $itemtype === 'User' && $id === 8 ? 'Пользователь Б' : '?';
$notes = Ui::approvalNotes(json_encode(['errors' => ['Нет прав'], 'warnings' => ['Без заместителя'], 'excluded' => [['id' => 8, 'reason' => 'Неактивен']]]), $imena);
ok($notes === ['Нет прав', 'Без заместителя', 'Пользователь Б: Неактивен'], 'сохранённые ошибки, предупреждения и исключения видны в строке, имя вместо номера',
    json_encode($notes, JSON_UNESCAPED_UNICODE));
ok(Ui::approvalNotes('{broken') === [], 'повреждённая диагностика не создаёт ложных подписей');
ok(Ui::deputyText('Адресат', ['Заместитель', 'Заместитель']) === 'Ждём: Адресат или заместитель Заместитель.', 'текущие заместители показаны без дублей');
ok(Ui::deputyText('Адресат', []) === '', 'нет текущего заместителя — нет обещания замещения');
ok(str_contains($t->createTemplate('{{ text }}')->render(['text' => Ui::deputyText('<Адресат>', ['<Заместитель>'])]), '&lt;Заместитель&gt;'), 'имя заместителя экранируется');
ok(Stage::normalizeApprovalInput(['approver_users' => '', 'approver_groups' => '']) === ['approver_users' => '[]', 'approver_groups' => '[]'], 'native пустой multiple очищает список');
// Прогоняем реальный макрос плагина и конечный native Html::select без БД.
// User/Group адаптер здесь передаёт specific_tags, как штатный jsAjaxDropdown.
$drop = new \Twig\Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]);
foreach (['path', 'csrf_token', '__'] as $fn) { $drop->addFunction(new \Twig\TwigFunction($fn, static fn() => '')); }
$drop->addFunction(new \Twig\TwigFunction('call', static function ($method, $args) {
    $o = $args[0];
    return \Html::select($o['name'], [], ['multiple' => true] + $o['specific_tags']);
}));
foreach (['User' => 'approver_users', 'Group' => 'approver_groups'] as $type => $name) {
    foreach ([false, true] as $show) {
        $html = $drop->createTemplate("{% import 'stage.html.twig' as s %}{{ s.approvalDropdown(type, name, [], show) }}")->render(compact('type', 'name', 'show'));
        $dom = new \DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom); $controls = $xpath->query('//input|//select');
        $correct = $controls->length === 2;
        foreach ($controls as $control) { $correct = $correct && ($control->hasAttribute('disabled') === !$show); }
        ok($correct, $type . ': native select и hidden ' . ($show ? 'включены' : 'отключены') . ' до JavaScript');
    }
}
itog();