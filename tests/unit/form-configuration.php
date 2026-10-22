<?php
/** Границы настройки: неверный ввод не требует обращения к БД. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\FormConfiguration;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\StageForm;
use GlpiPlugin\Itilflow\DefinitionSnapshot;
$base = ['forms_forms_id' => 0, 'forms_destinations_id' => 0, 'itemtype' => 'Ticket', 'outcome_contract' => DefinitionSnapshot::MODE];
ok(FormConfiguration::inputError($base) === null, 'маршрут без формы совместим');
// 1.13.1: у этапа «Согласование из формы» в готовности нет постоянного текста о правиле работы —
// только находки этого маршрута (назначения без этапа согласования при «любом назначении»).
$etapFormy = ['approver_source' => 'form', 'form_validationsteps_id' => 985];
ok(FormConfiguration::warnings(array_replace($base, ['forms_forms_id' => 390, 'forms_destinations_id' => 458, 'start_if' => null]), $etapFormy) === []
    && FormConfiguration::warnings(array_replace($base, ['forms_forms_id' => 390, 'forms_destinations_id' => 458,
        'start_if' => '{"version":1,"mode":"always"}']), $etapFormy) === [],
    'этап «Согласование из формы» с выбранным назначением — без постоянной строки «засчитает согласование…»');
ok(FormConfiguration::warnings($base, ['approver_source' => 'form', 'form_validationsteps_id' => 0]) === [],
    'этап согласования в форме не выбран — предупреждений нет (проблема — отдельно)');
foreach ([-1, 'abc', '1e3', [], null] as $bad) {
    // NULL в сохранённом legacy поле трактуется как отсутствие; массивы и строки не приводятся к номеру.
    if ($bad === null) { continue; }
    ok(FormConfiguration::inputError(array_replace($base, ['forms_forms_id' => $bad])) !== null, 'неверный номер формы отвергнут');
}
ok(FormConfiguration::inputError(array_replace($base, ['forms_forms_id' => 1, 'itemtype' => 'Change'])) !== null, 'форма только для Ticket');
ok(FormConfiguration::inputError(array_replace($base, ['forms_forms_id' => 1, 'outcome_contract' => 'legacy'])) !== null, 'форма только для нового порядка');
ok(!Stage::approverConflict(['execution_mode' => 'approval', 'approver_source' => 'form', 'groups_id' => 5, 'users_id' => 7]), 'старые поля не меняют источник формы');
$shown = StageForm::visibleFields(['execution_mode' => 'approval', 'approver_source' => 'form'], true, 'Ticket');
ok(in_array('form_validationsteps_id', $shown['main'], true) && !in_array('approval_percent', $shown['more'], true), 'порог формы не переопределяется настройкой этапа');
ok(!StageForm::rendered('form_validationsteps_id', false, 'Ticket') && !StageForm::rendered('form_validationsteps_id', true, 'Change'), 'поле не показывается у другого контракта или типа');
$rule = ['id' => 1, 'name' => 'Проверка заявки', 'sub_type' => 'RuleTicket', 'is_active' => 1,
    'entities_id' => 2, 'is_recursive' => 0, 'field' => 'validationsteps_id', 'value' => '7'];
$scope = [2 => [0, 1]];
$conflict = FormConfiguration::ruleConflicts([$rule], 7, $scope);
ok(count($conflict) === 1 && str_contains($conflict[0], 'Правило «Проверка заявки» тоже использует этот этап согласования')
    && str_contains($conflict[0], 'Отключите правило или выберите другой этап согласования.'),
    'активное правило общего этапа согласования даёт блокирующую причину с названием и действием (словарь 1.13)', $conflict[0] ?? '');
foreach (['is_active' => 0, 'sub_type' => 'RuleChange', 'field' => '_plugin_itilflow_processes_id',
    'value' => '8', 'entities_id' => 3] as $key => $value) {
    ok(FormConfiguration::ruleConflicts([array_replace($rule, [$key => $value])], 7, $scope) === [],
        'нерелевантное правило не блокирует: ' . $key);
}
$parent = array_replace($rule, ['entities_id' => 1, 'is_recursive' => 1]);
ok(count(FormConfiguration::ruleConflicts([$parent], 7, $scope)) === 1, 'рекурсивное правило предка затрагивает организацию маршрута');
ok(FormConfiguration::ruleConflicts([array_replace($parent, ['is_recursive' => 0])], 7, $scope) === [], 'нерекурсивное правило предка не затрагивает дочернюю организацию');
ok(FormConfiguration::ruleConflicts([array_replace($parent, ['entities_id' => 3])], 7, $scope) === [], 'рекурсивное правило другой ветви не блокирует');
ok(count(FormConfiguration::ruleConflicts([array_replace($rule, ['entities_id' => 3])], 7, $scope + [3 => [0, 1, 2]])) === 1,
    'правило дочерней организации блокирует рекурсивный маршрут');
ok(count(FormConfiguration::ruleConflicts([$rule, $rule], 7, $scope)) === 1, 'несколько действий одного правила не дублируют причину');
$response = $rule; unset($response['field'], $response['value']);
$response['match'] = 'AND';
$response['condition'] = RuleTicket::ONUPDATE;
$response['step_criteria'] = [['criteria' => '_validationsteps_id', 'condition' => Rule::PATTERN_IS, 'pattern' => '7']];
ok(count(FormConfiguration::ruleConflicts([$response], 7, $scope)) === 1, 'правило реакции на ответ шага тоже блокирует');
ok(FormConfiguration::ruleConflicts([array_replace($response, ['condition' => RuleTicket::ONADD])], 7, $scope) === [],
    'критерий правила только создания не реагирует на ответ');
ok(count(FormConfiguration::ruleConflicts([array_replace($response, ['condition' => RuleTicket::ONADD | RuleTicket::ONUPDATE])], 7, $scope)) === 1,
    'правило создания и изменения реагирует на ответ');
ok(count(FormConfiguration::ruleConflicts([array_replace($rule, ['condition' => RuleTicket::ONADD])], 7, $scope)) === 1,
    'действие общего шага при создании остаётся конфликтом состава');
$other = $response; $other['step_criteria'][0]['pattern'] = '8';
ok(FormConfiguration::ruleConflicts([$other], 7, $scope) === [], 'равенство другому шагу не блокирует');
$other['step_criteria'][0]['condition'] = Rule::PATTERN_IS_NOT;
ok(count(FormConfiguration::ruleConflicts([$other], 7, $scope)) === 1, 'неравенство другому шагу допускает ответ выбранного шага');
$other['step_criteria'][0]['pattern'] = '7';
ok(FormConfiguration::ruleConflicts([$other], 7, $scope) === [], 'явное исключение выбранного шага не блокирует');
$other['step_criteria'][] = ['criteria' => 'global_validation', 'condition' => Rule::PATTERN_IS, 'pattern' => '3'];
ok(FormConfiguration::ruleConflicts([$other], 7, $scope) === [], 'AND с исключением шага остаётся неприменимым');
$other['match'] = 'OR';
ok(count(FormConfiguration::ruleConflicts([$other], 7, $scope)) === 1, 'OR с другим условием может сработать на выбранном шаге');
$two = array_replace($rule, ['id' => 2, 'name' => 'Другая проверка']);
ok(count(FormConfiguration::ruleConflicts([$rule, $two], 7, $scope)) === 2, 'перечислены оба конфликтующих правила');
$approval = ['id' => 7, 'name' => 'Проверка', 'percent' => 50, 'who' => 'Дежурный'];
$destinations = [
    ['id' => 12, 'name' => 'Обращение', 'known' => true, 'approvals' => [$approval]],
    ['id' => 14, 'name' => 'Работы', 'known' => true, 'approvals' => [array_replace($approval, ['id' => 8, 'name' => 'Подтверждение'])]],
    ['id' => 18, 'name' => 'Уведомление', 'known' => true, 'approvals' => []],
];
ok(FormConfiguration::approvalOptions($destinations) === [0 => 'Не выбран', 7 => 'Проверка', 8 => 'Подтверждение'], 'общий выбор объединяет этапы согласования нескольких назначений');
ok(FormConfiguration::approvalOptions([$destinations[0], $destinations[0]]) === [0 => 'Не выбран', 7 => 'Проверка'], 'общий этап согласования не дублируется');
$notes = FormConfiguration::missingStepNotes($destinations, 7);
ok(count($notes) === 2 && str_contains($notes[0], 'Работы') && str_contains($notes[1], 'Уведомление'), 'примечания перечисляют только назначения без выбранного этапа');
ok(str_contains($notes[1], 'укажите согласующего или засчитайте запрос из заявки'), 'примечание объясняет ожидание, а не объявляет запуск запрещённым');
$unread = $destinations[2]; $unread['known'] = false;
ok(str_contains(FormConfiguration::missingStepNotes([$unread], 7)[0], 'не удалось прочитать'), 'ошибка чтения не выдаётся за доказанное отсутствие согласования');
$description = FormConfiguration::approvalDescriptions([$destinations[0]], [
    ['name' => 'Проверить обращение', 'approver_source' => 'form', 'form_validationsteps_id' => 7],
    ['name' => 'Не связан', 'approver_source' => 'fixed', 'form_validationsteps_id' => 7],
]);
ok(count($description) === 1 && str_contains($description[0], 'Дежурный → этап согласования «Проверка», нужно одобрений 50 %.'), 'описание согласования показывает людей, имя и процент ядра');
ok(str_contains($description[0], '«Проверить обращение»') && !str_contains($description[0], 'Не связан'), 'описание называет принимающий этап вместо внутреннего номера');
ok(str_contains(FormConfiguration::approvalDescriptions([$destinations[0]], [])[0], 'Выберите этап маршрута'), 'нет принимающего этапа — есть действие по настройке');

// Реальный редактор ядра: только сохранённые условия и подписи, без формы в БД и без вычисления ответов.
$editor = new \Glpi\Form\Condition\EditorManager();
$editor->setFormData(new \Glpi\Form\Condition\FormData(['questions' => [[
    'uuid' => 'test-amount', 'name' => 'Количество', 'type' => \Glpi\Form\QuestionType\QuestionTypeNumber::class,
]]]));
$condition = new \Glpi\Form\Condition\ConditionData('test-amount', 'question', \Glpi\Form\Condition\ValueOperator::GREATER_THAN->value, 3);
$if = \Glpi\Form\Condition\CreationStrategy::CREATED_IF;
$unless = \Glpi\Form\Condition\CreationStrategy::CREATED_UNLESS;
ok(FormConfiguration::creationText(\Glpi\Form\Condition\CreationStrategy::ALWAYS_CREATED, [], $editor) === 'создаётся всегда', 'безусловное назначение описано прямо');
$text = FormConfiguration::creationText($if, [$condition], $editor);
ok(str_contains($text, '«Количество»') && str_contains($text, '«3»')
    && str_contains($text, \Glpi\Form\Condition\ValueOperator::GREATER_THAN->getLabel()), 'описание берёт имя и оператор из редактора ядра');
ok(FormConfiguration::creationText($unless, [$condition], $editor) !== $text, 'создаётся если и создаётся если не различаются');
$missing = new \Glpi\Form\Condition\ConditionData('removed-question', 'question', \Glpi\Form\Condition\ValueOperator::EQUALS->value, 1);
ok(str_contains(FormConfiguration::creationText($if, [$missing], $editor), 'проверьте условия'), 'удалённый вопрос не получает придуманное условие');
ok(str_contains(FormConfiguration::creationText($if, [], $editor), 'проверьте условия'), 'пустая условная настройка не объявляется безусловной');

// Покрытие: сочетания нескольких вопросов, многозначный выбор, неизвестность и границы перебора.
$choiceQ = ['id' => 10, 'uuid' => 'choice', 'label' => 'Вид работы', 'type' => 'choice',
    'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeDropdown', 'multiple' => false,
    'itemtype' => null, 'supported' => true, 'required' => true, 'conditional' => false,
    'options' => [['key' => 'a', 'label' => 'Первый'], ['key' => 'b', 'label' => 'Второй']]];
$boolQ = ['id' => 11, 'uuid' => 'flag', 'label' => 'Срочно', 'type' => 'bool',
    'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeCheckbox', 'multiple' => true,
    'itemtype' => null, 'supported' => true, 'required' => true, 'conditional' => false,
    'options' => [['key' => 'yes', 'label' => 'Да']]];
$clause = static fn(array $q, string $op, mixed $value): array => [
    'source' => 'form_answer', 'question_uuid' => $q['uuid'], 'question' => $q, 'operator' => $op, 'value' => $value];
$route = static fn(int $id, array $clauses): array => ['id' => $id, 'name' => 'Маршрут ' . $id,
    'start' => ['version' => 1, 'mode' => 'conditions', 'rule' => ['version' => 1, 'join' => 'all', 'clauses' => $clauses]]];
$always = ['id' => 90, 'name' => 'Всегда', 'start' => ['version' => 1, 'mode' => 'always', 'rule' => []]];
$routes = [$route(20, [$clause($choiceQ, 'in', ['a']), $clause($boolQ, 'eq', true)]), $always];
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage($routes, ['choice' => $choiceQ, 'flag' => $boolQ]);
ok(count($coverage) === 4, 'покрытие перебирает декартово произведение двух вопросов, а не по одному');
ok(count(array_filter($coverage, static fn($v): bool => count($v['yes']) === 2)) === 1, 'пересечение обнаружено только для нужного сочетания двух ответов');
foreach ($coverage as $v) { ok(array_diff($v['yes'], $v['maybe']) === [], 'точно подходящие маршруты входят в множество возможных'); }
$multiQ = array_replace($choiceQ, ['multiple' => true, 'required' => false]);
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([
    $route(21, [$clause($multiQ, 'in', ['a'])]), $route(22, [$clause($multiQ, 'in', ['b'])]),
], ['choice' => $multiQ]);
ok(count($coverage) === 5, 'множественный выбор включает пустой, оба одиночных, совместный и неизвестный ответ');
ok(count(array_filter($coverage, static fn($v): bool => $v['yes'] === [21, 22])) === 1, 'совместный выбор обнаруживает пересечение, пропускаемое одиночным перебором');
ok(count(array_filter($coverage, static fn($v): bool => $v['complete'] && $v['maybe'] === [])) === 1, 'пустой множественный выбор показывает пробел покрытия');
$numberQ = array_replace($choiceQ, ['uuid' => 'amount', 'id' => 12, 'type' => 'number', 'options' => [],
    'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeNumber']);
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([$route(23, [$clause($numberQ, 'gt', '3')]), $always], ['amount' => $numberQ]);
// Число перебирается не произвольным примером, а по промежуткам между числами условий: 2 | 3 | 4 для «больше 3».
ok(array_column($coverage, 'option') === ['2', '3', '4'] && !array_filter($coverage, static fn($v): bool => !$v['complete'])
    && array_column($coverage, 'yes') === [[90], [90], [23, 90]], 'число: граница условия и промежутки по обе стороны — пересечение найдено только при «больше 3»',
    json_encode($coverage, JSON_UNESCAPED_UNICODE));
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([$route(25, [$clause($numberQ, 'lt', '1.5')]), $route(26, [$clause($numberQ, 'gte', '1.2')])],
    ['amount' => array_replace($numberQ, ['required' => false])]);
ok(array_column($coverage, 'option') === ['0.2', '1.2', '1.35', '1.5', '2.5', 'нет ответа']
    && array_column($coverage, 'yes') === [[25], [25, 26], [25, 26], [26], [26], []],
    'дробные числа: пересечение между 1,2 и 1,5 найдено; пустой необязательный ответ — «неизвестно»', json_encode($coverage, JSON_UNESCAPED_UNICODE));
$dateQ = array_replace($choiceQ, ['uuid' => 'when', 'id' => 13, 'label' => 'Когда нужно', 'type' => 'date', 'options' => [], 'required' => false,
    'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeDateTime']);
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([$route(27, [$clause($dateQ, 'before_days', 5)]), $route(28, [$clause($dateQ, 'after_days', 3)])],
    ['when' => $dateQ]);
$dvaDnya = array_values(array_filter($coverage, static fn($v): bool => $v['yes'] === [27, 28]));
ok(!array_filter($coverage, static fn($v): bool => !$v['complete']) && array_column($dvaDnya, 'option') === ['через 4 дн. после подачи']
    && in_array('не заполнено', array_column($coverage, 'option'), true),
    'дата: «раньше, чем через 5» и «позже, чем через 3» пересекаются только на 4-м дне; пустая дата — известное «не заполнено»',
    json_encode(array_map(static fn($v): array => [$v['option'], $v['yes']], $coverage), JSON_UNESCAPED_UNICODE));
$huge = $multiQ; $huge['options'] = [];
foreach (range(1, 9) as $n) { $huge['options'][] = ['key' => 'v' . $n, 'label' => 'Вариант ' . $n]; }
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([$route(24, [$clause($huge, 'in', ['v1'])]), $always], ['choice' => $huge]);
ok(count($coverage) === 1 && !$coverage[0]['complete'] && $coverage[0]['reason'] === 'variant_limit'
    && $coverage[0]['yes'] === [] && $coverage[0]['maybe'] === [24,90], 'ограниченный перебор не выдаёт усечённую выборку за полное доказательство');
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([$always], []);
ok(count($coverage) === 1 && $coverage[0]['yes'] === [90] && $coverage[0]['complete'], 'безусловный маршрут проверяется без вопросов');
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([], []);
ok(count($coverage) === 1 && $coverage[0]['maybe'] === [], 'отсутствие кандидатов даёт пробел, а не пустой результат проверки');
$changedQ = $choiceQ; $changedQ['options'][0]['label'] = 'Другой смысл';
$coverage = \GlpiPlugin\Itilflow\StartReadiness::coverage([$routes[0]], ['choice' => $changedQ, 'flag' => $boolQ]);
ok(!array_filter($coverage, static fn($v): bool => $v['yes'] !== []), 'изменённое определение вопроса не даёт ложного точного совпадения');
$rows = [];
foreach (range(1, 20) as $id) { $rows[] = ['id' => $id, 'name' => 'Маршрут ' . $id, 'entities_id' => 2, 'forms_destinations_id' => $id, 'start_if' => '{"version":1,"mode":"always"}']; }
$rows[] = ['id' => 30, 'name' => 'Общий', 'entities_id' => 1, 'is_recursive' => 1, 'forms_destinations_id' => 0, 'start_if' => '{"version":1,"mode":"always"}'];
$candidates = \GlpiPlugin\Itilflow\StartReadiness::forDestination($rows, 5, 2, [0,1]);
ok(array_column($candidates, 'id') === [5,30], '20 назначений не дают ложное пересечение всей формы: только своё и общее');
ok(\GlpiPlugin\Itilflow\StartReadiness::forDestination($rows, 5, 7, [0,6]) === [], 'другая ветвь организаций не смешивается в покрытии');
ok(!\GlpiPlugin\Itilflow\StartReadiness::fitsEntity(['entities_id' => 1, 'is_recursive' => 0], 2, [0,1]), 'нерекурсивный родитель не подходит дочерней организации');
itog();
