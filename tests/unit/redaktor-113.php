<?php

/**
 * Модель и редактор условий:
 * - сравнения по виду вопроса, в том числе текст «заполнено», — одни для «Выполнять, если»
 *   и «Запускать, если»;
 * - разбор редактора: «Всегда» — пустое правило без ошибок;
 * - текст условия — названиями, а не номерами («Категория заявки равно 42» — так было в 1.12).
 *
 * Формат унарных сравнений (1.13): filled, empty, unknown —
 * значение null; факт текстового вопроса — только «заполнено» (true/false), без текста ответа.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Condition;
use GlpiPlugin\Itilflow\ConditionEditor;
use GlpiPlugin\Itilflow\ConditionText;

if (!estKlass(ConditionEditor::class) || !estKlass(ConditionText::class)) {
    itog();
}

$vopros = ['id' => 11, 'uuid' => 'q-text', 'label' => 'Укажите имя устройства',
    'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeShortText', 'type' => 'text', 'multiple' => false,
    'options' => [], 'itemtype' => null, 'supported' => true, 'reason' => null, 'required' => false, 'conditional' => false];
$pravilo = ['version' => 1, 'join' => 'all', 'clauses' => [
    ['source' => 'form_answer', 'question_uuid' => 'q-text', 'question' => $vopros, 'operator' => 'filled', 'value' => null],
]];

$oshibki = Condition::validate($pravilo);
ok($oshibki === [], 'текст формы: «заполнено» — допустимое условие', implode('; ', $oshibki));

$kontekst = ['attempt_id' => 1, 'stages' => [], 'ticket' => [],
    'form_facts' => ['version' => 1, 'status' => 'final', 'source' => ['mode' => 'callback'], 'input_revision' => 'x',
        'facts' => ['q-text' => ['status' => 'known', 'type' => 'text', 'value' => true, 'reason' => null]]]];
$itog = Condition::evaluate($pravilo, $kontekst);
ok($itog['truth'] === 'yes', 'заполненный текст — условие «заполнено» верно', json_encode($itog, JSON_UNESCAPED_UNICODE));

$sravneniya = ConditionEditor::operators();
ok(in_array(['filled', 'заполнено'], $sravneniya['text'] ?? [], true)
    && in_array(['empty', 'не заполнено'], $sravneniya['text'] ?? [], true),
    'у текста сравнения «заполнено / не заполнено»');
ok(in_array(['in', 'любой из'], $sravneniya['choice'] ?? [], true)
    && in_array(['not_in', 'ни один из'], $sravneniya['choice'] ?? [], true),
    'у списка — «любой из / ни один из»');

$razobrano = ConditionEditor::parse(['mode' => ConditionEditor::MODE_ALWAYS], ConditionEditor::SCOPE_STAGE, ['forms_forms_id' => 0]);
ok($razobrano['errors'] === [] && $razobrano['rule'] === [] && $razobrano['mode'] === ConditionEditor::MODE_ALWAYS,
    '«Всегда» — пустое правило без ошибок', implode('; ', $razobrano['errors']));

$kategoriya = ['version' => 1, 'join' => 'all', 'clauses' => [
    ['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42]],
]];
$tekst = ConditionText::short($kategoriya, [], static fn(string $tip, int $id): string => $tip === 'ITILCategory' && $id === 42 ? 'Оборудование' : '?');
ok(str_contains($tekst, 'Оборудование') && !str_contains($tekst, '42'), 'категория — названием, без номера', $tekst);

// ---------------------------------------------------------------- модель по видам
use GlpiPlugin\Itilflow\FormFacts;
use GlpiPlugin\Itilflow\Step;

$q = static fn(string $uuid, string $type, array $extra = []): array => $extra + ['id' => crc32($uuid) % 100000 + 1, 'uuid' => $uuid,
    'label' => 'Вопрос ' . $uuid, 'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeShortText', 'type' => $type, 'multiple' => false,
    'options' => [], 'itemtype' => null, 'supported' => true, 'reason' => null, 'required' => true, 'conditional' => false];
$voprosy = [
    'q-text' => $q('q-text', 'text'),
    'q-file' => $q('q-file', 'file', ['native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeFile']),
    'q-date' => $q('q-date', 'date', ['native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeDateTime']),
    'q-user' => $q('q-user', 'ref', ['native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeItem', 'itemtype' => 'User']),
    'q-list' => $q('q-list', 'choice', ['native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeDropdown',
        'options' => [['key' => '1', 'label' => 'Вариант А'], ['key' => '2', 'label' => 'Вариант Б'], ['key' => '3', 'label' => 'Другое']]]),
    'q-flag' => $q('q-flag', 'bool', ['native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeCheckbox', 'multiple' => true,
        'options' => [['key' => '1', 'label' => 'Нужен выезд']]]),
];
$usl = static fn(string $uuid, string $op, mixed $value = null): array => ['source' => 'form_answer', 'question_uuid' => $uuid,
    'question' => $voprosy[$uuid], 'operator' => $op, 'value' => $value];
$pr = static fn(array ...$clauses): array => ['version' => 1, 'join' => 'all', 'clauses' => $clauses];
$izvestno = static fn(string $type, mixed $v): array => ['status' => 'known', 'type' => $type, 'value' => $v, 'reason' => null];
$neizv = static fn(string $type): array => ['status' => 'unknown', 'type' => $type, 'value' => null, 'reason' => 'visibility_unproven'];
$fakty = static fn(array $facts, ?string $at = '2026-10-21 18:00:00'): array => ['attempt_id' => 1, 'stages' => [], 'ticket' => [],
    'form_facts' => ['version' => 1, 'status' => 'final', 'source' => ['mode' => 'callback', 'submitted_at' => $at],
        'input_revision' => 'x', 'facts' => $facts]];
$istina = static fn(array $rule, array $ctx): string => Condition::evaluate($rule, $ctx)['truth'];

foreach ([['filled', true, 'yes'], ['filled', false, 'no'], ['empty', false, 'yes'], ['empty', true, 'no']] as [$op, $v, $zhdu]) {
    ok($istina($pr($usl('q-text', $op)), $fakty(['q-text' => $izvestno('text', $v)])) === $zhdu, "текст: «{$op}» при " . json_encode($v) . " — {$zhdu}");
    ok($istina($pr($usl('q-file', $op)), $fakty(['q-file' => $izvestno('file', $v)])) === $zhdu, "файл: «{$op}» при " . json_encode($v) . " — {$zhdu}");
}
ok($istina($pr($usl('q-text', 'filled')), $fakty(['q-text' => $neizv('text')])) === 'unknown', 'текст без ответа: «заполнено» неизвестно, а не «нет»');
ok($istina($pr($usl('q-text', 'unknown')), $fakty(['q-text' => $neizv('text')])) === 'yes', '«неизвестно» верно, когда ответа нет');
ok($istina($pr($usl('q-text', 'unknown')), $fakty(['q-text' => $izvestno('text', false)])) === 'no', '«неизвестно» неверно, когда ответ есть, даже пустой');
ok($istina($pr($usl('q-text', 'unknown')), ['attempt_id' => 1, 'stages' => [], 'ticket' => []]) === 'yes', 'заявка не из формы: «неизвестно» верно');

// Дата: «раньше / позже, чем через N дней после подачи» — по календарным дням.
$data = static fn(?string $d, ?string $at = '2026-10-21 18:00:00'): array => $fakty(['q-date' => $izvestno('date', $d)], $at);
ok($istina($pr($usl('q-date', 'before_days', 5)), $data('2026-10-25 00:00:00')) === 'yes', 'дата через 4 дня — раньше, чем через 5');
ok($istina($pr($usl('q-date', 'before_days', 5)), $data('2026-10-26 09:00:00')) === 'no', 'дата ровно через 5 дней — не раньше');
ok($istina($pr($usl('q-date', 'after_days', 5)), $data('2026-10-26 09:00:00')) === 'no', 'ровно через 5 дней — и не позже');
ok($istina($pr($usl('q-date', 'after_days', 5)), $data('2026-11-02 00:00:00')) === 'yes', 'через 12 дней — позже, чем через 5');
ok($istina($pr($usl('q-date', 'before_days', 5)), $data(null)) === 'no', 'пустая дата не «раньше»');
ok($istina($pr($usl('q-date', 'empty')), $data(null)) === 'yes' && $istina($pr($usl('q-date', 'filled')), $data('2026-10-25 00:00:00')) === 'yes', 'дата: «заполнено / не заполнено»');
ok($istina($pr($usl('q-date', 'before_days', 5)), $data('2026-10-25 00:00:00', null)) === 'unknown', 'без даты подачи «через N дней» неизвестно');
ok(Condition::validate($pr($usl('q-date', 'before_days', 0))) !== [] && Condition::validate($pr($usl('q-date', 'before_days', '5'))) !== [],
    'N дней — целое больше нуля');

// Пользователь: «любой из» списком, «заполнено», прежнее «равно» читается как раньше.
$ivanov = ['itemtype' => 'User', 'id' => 17]; $petrov = ['itemtype' => 'User', 'id' => 18];
ok($istina($pr($usl('q-user', 'in', [$petrov, $ivanov])), $fakty(['q-user' => $izvestno('ref', $ivanov)])) === 'yes', 'пользователь — любой из списка');
ok($istina($pr($usl('q-user', 'in', [$petrov])), $fakty(['q-user' => $izvestno('ref', null)])) === 'no', 'пустой ответ — не «любой из»');
ok($istina($pr($usl('q-user', 'empty')), $fakty(['q-user' => $izvestno('ref', null)])) === 'yes', 'пользователь: «не заполнено»');
ok($istina($pr($usl('q-user', 'filled')), $fakty(['q-user' => $izvestno('ref', $ivanov)])) === 'yes', 'пользователь: «заполнено»');
ok($istina($pr($usl('q-user', 'eq', $ivanov)), $fakty(['q-user' => $izvestno('ref', null)])) === 'unknown', 'прежнее «равно» при пустом ответе — неизвестно, как в 1.12');
ok(Condition::validate($pr($usl('q-user', 'in', [['itemtype' => 'Group', 'id' => 3]]))) !== [], 'группа в условии по пользователю не принимается');
ok(Condition::validate($pr($usl('q-user', 'filled', $ivanov))) !== [], 'у «заполнено» значения нет');

// Список и флажок.
ok($istina($pr($usl('q-list', 'in', ['1'])), $fakty(['q-list' => $izvestno('choice', '1')])) === 'yes', 'список: любой из');
ok($istina($pr($usl('q-list', 'not_in', ['1'])), $fakty(['q-list' => $izvestno('choice', '3')])) === 'yes', 'список: ни один из');
ok($istina($pr($usl('q-list', 'unknown')), $fakty(['q-list' => $neizv('choice')])) === 'yes', 'список: неизвестно');
$flag = static fn(string $op, ?bool $v = null): array => ['source' => 'form_answer', 'question_uuid' => 'q-flag', 'question' => $voprosy['q-flag'], 'operator' => $op, 'value' => $v];
ok($istina($pr($flag('eq', false)), $fakty(['q-flag' => $izvestno('bool', false)])) === 'yes', 'пустой флажок — «не отмечен» верно');
ok($istina($pr($flag('eq', false)), $fakty(['q-flag' => $neizv('bool')])) === 'unknown', 'флажок без ответа — «не отмечен» неизвестно, не «да»');
ok($istina($pr($flag('unknown')), $fakty(['q-flag' => $izvestno('bool', false)])) === 'no', 'пустой флажок — ответ, «неизвестно» неверно');
ok(Condition::validate($pr($usl('q-text', 'eq', true))) !== [], 'у текста нет «равно»: текст ответа не хранится');

// Вопрос этапа и категория заявки.
$etapy = [['id' => 1, 'ranking' => 20, 'name' => 'Проверка', 'decision_items' => [['key' => 'dop', 'label' => 'Нужно дополнительное согласование']]]];
$resh = static fn(string $op, ?bool $v = null): array => ['source' => 'decision', 'stage_id' => 1, 'key' => 'dop', 'operator' => $op, 'value' => $v];
$etap = static fn(string $state, ?bool $answer): array => ['attempt_id' => 1, 'stages' => [1 => ['state' => $state, 'attempt_id' => 1,
    'answers' => $answer === null ? [] : ['dop' => $answer]]]];
ok($istina($pr($resh('unknown')), $etap(Step::NOT_NEEDED, null)) === 'yes', 'вопрос этапа: «неизвестно», когда этап не нужен');
ok($istina($pr($resh('unknown')), $etap(Step::DONE, false)) === 'no', 'вопрос этапа: ответ «нет» — не «неизвестно»');
ok($istina($pr($resh('eq', true)), $etap(Step::DONE, true)) === 'yes', 'вопрос этапа: да');
$kat = static fn(string $op, array $ids): array => $pr(['source' => 'ticket', 'field' => 'category_id', 'operator' => $op, 'value' => $ids]);
ok(Condition::evaluate($kat('in', [5, 6]), ['ticket' => ['category_id' => 6]])['truth'] === 'yes'
    && Condition::evaluate($kat('not_in', [5, 6]), ['ticket' => ['category_id' => 6]])['truth'] === 'no', 'категория заявки: любая из / ни одна из');

// ---------------------------------------------------------------- факты формы без содержимого
$f = static fn(array $d, mixed $raw, ?bool $visible = true, bool $historical = true): array
    => FormFacts::normalize($d, $d['native_type'], $raw, $d, $historical, $visible);
ok($f($voprosy['q-text'], 'Имя-1') === ['status' => 'known', 'type' => 'text', 'value' => true, 'reason' => null], 'текст: только «заполнено», без текста ответа');
ok($f($voprosy['q-text'], '')['value'] === false && $f($voprosy['q-text'], '<p>&nbsp;</p>')['value'] === false, 'пустой и «пустой HTML» — не заполнено');
ok($f($voprosy['q-text'], '', null, false)['status'] === 'unknown', 'пустой ответ без доказанной видимости — неизвестно');
ok($f($voprosy['q-file'], [12])['value'] === true && $f($voprosy['q-file'], [])['value'] === false, 'файл: приложен / не приложен, без имени файла');
ok($f($voprosy['q-date'], '2026-10-25')['value'] === '2026-10-25 00:00:00' && $f($voprosy['q-date'], '2026-10-25T10:30')['value'] === '2026-10-25 10:30:00',
    'дата и дата со временем — строкой Y-m-d H:i:s');
ok($f($voprosy['q-date'], '2026-02-31')['status'] === 'unknown' && $f($voprosy['q-date'], '')['value'] === null, 'неверная дата неизвестна, пустая — «не заполнено»');
ok($f($voprosy['q-user'], [])['status'] === 'known' && $f($voprosy['q-user'], [])['value'] === null, 'пустой пользователь — «не заполнено»');
ok($f($voprosy['q-text'], 'текст', false)['status'] === 'unknown', 'скрытый вопрос — неизвестно');
$vid = static function (string $native, array $extra = []): array {
    $question = new \Glpi\Form\Question();
    $question->fields = ['id' => 9, 'uuid' => 'u-9', 'name' => 'Вопрос', 'type' => 'Glpi\\Form\\QuestionType\\' . $native] + $extra;
    return FormFacts::describeQuestion($question);
};
ok($vid('QuestionTypeShortText')['type'] === 'text' && $vid('QuestionTypeLongText')['type'] === 'text', 'краткий и долгий ответ — вид text');
ok($vid('QuestionTypeFile')['type'] === 'file' && $vid('QuestionTypeFile')['supported'], 'файл — вид file');
ok($vid('QuestionTypeDateTime', ['extra_data' => '{"is_date_enabled":true,"is_time_enabled":true}'])['type'] === 'date', 'дата и время — вид date');
ok(!$vid('QuestionTypeDateTime', ['extra_data' => '{"is_date_enabled":false,"is_time_enabled":true}'])['supported'], 'только время — в условиях не используется');

// ---------------------------------------------------------------- редактор: разбор и вид
$katalog = [];
foreach ($voprosy as $uuid => $vopros_) { $katalog[$uuid] = $vopros_ + ['section' => 'Основные данные', 'type_label' => 'вид']; }
$marshrut = ['id' => 5, 'itemtype' => 'Ticket', 'forms_forms_id' => 7, 'form_catalog' => $katalog];
$etapyM = [
    ['id' => 1, 'ranking' => 10, 'name' => 'Согласование', 'decision_items' => null, 'run_if' => null],
    ['id' => 2, 'ranking' => 20, 'name' => 'Проверка', 'decision_items' => [['key' => 'dop', 'label' => 'Нужна проверка']], 'run_if' => null],
    ['id' => 3, 'ranking' => 25, 'name' => 'Дополнительное согласование', 'decision_items' => null,
        'run_if' => '{"version":1,"join":"all","clauses":[{"source":"decision","stage_id":2,"key":"dop","operator":"eq","value":true}]}'],
    ['id' => 4, 'ranking' => 30, 'name' => 'Исполнение', 'decision_items' => null, 'run_if' => null],
];
$etap30 = $etapyM[3];
$gruppy = array_column(ConditionEditor::sources(ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM), 'label');
ok($gruppy === ['Вопросы этапов выше', 'Ответы формы · Основные данные', 'Этап не нужен по условию', 'Поле заявки'],
    '«Что проверяем» — группами', implode(' | ', $gruppy));
$vse = [];
foreach (ConditionEditor::sources(ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM) as $g) { foreach ($g['options'] as $o) { $vse[$o['value']] = $o; } }
ok(isset($vse['decision:2:dop'], $vse['skip:3'], $vse['ticket:category_id']) && !isset($vse['skip:2']) && !isset($vse['ticket:type']),
    'вопросы этапов выше; «не нужен» — только у этапов с условием; тип заявки убран');
$niz = ConditionEditor::sources(ConditionEditor::SCOPE_STAGE, $marshrut, $etapyM[1], $etapyM);
$nizVse = []; foreach ($niz as $g) { foreach ($g['options'] as $o) { $nizVse[] = $o['value']; } }
ok(!in_array('decision:2:dop', $nizVse, true) && !in_array('skip:3', $nizVse, true), 'этап не ссылается на себя и этапы ниже');
$zapGr = array_column(ConditionEditor::sources(ConditionEditor::SCOPE_START, $marshrut), 'label');
ok($zapGr === ['Ответы формы · Основные данные'], '«Запускать, если» — только ответы формы', implode(' | ', $zapGr));
$bezFormy = ConditionEditor::sources(ConditionEditor::SCOPE_STAGE, ['itemtype' => 'Ticket', 'forms_forms_id' => 0], $etap30, $etapyM);
ok(in_array('— сначала выберите форму маршрута —', array_column(array_merge(...array_column($bezFormy, 'options')), 'label'), true),
    'без формы — подсказка выбрать форму маршрута');

$vvod = static fn(array ...$rows): array => ['mode' => 'if', 'join' => 'all', 'clauses' => $rows];
$r = ConditionEditor::parse($vvod(['what' => 'form:q-text', 'op' => 'filled'], ['what' => 'form:q-list', 'op' => 'in', 'values' => ['1']],
    ['what' => 'form:q-date', 'op' => 'before_days', 'days' => '5']), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] === [] && count($r['rule']['clauses']) === 3 && $r['rule']['clauses'][2]['value'] === 5
    && $r['rule']['clauses'][1]['value'] === ['1'] && $r['rule']['clauses'][0]['value'] === null, 'разбор: текст, список, дата', json_encode($r, JSON_UNESCAPED_UNICODE));
ok(!isset($r['rule']['clauses'][0]['question']['section']) && !isset($r['rule']['clauses'][0]['question']['type_label']),
    'в снимок вопроса раздел и вид словами не попадают');
$r = ConditionEditor::parse($vvod(['what' => 'form:q-user', 'op' => 'in', 'items' => ['17', '18', '17']]), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] === [] && $r['rule']['clauses'][0]['value'] === [$ivanov, $petrov], 'пользователь — любой из, без повторов');
$r = ConditionEditor::parse($vvod(['what' => 'decision:2:dop', 'op' => 'no'], ['what' => 'skip:3', 'op' => 'skipped']), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] === [] && $r['rule']['clauses'][0]['operator'] === 'eq' && $r['rule']['clauses'][0]['value'] === false
    && $r['rule']['clauses'][1]['value'] === true, 'вопрос этапа «нет» и «не нужен по условию» — в модель eq');
$r = ConditionEditor::parse($vvod(['what' => 'form:q-flag', 'op' => 'unchecked']), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] === [] && $r['rule']['clauses'][0]['value'] === false, 'флажок «не отмечен» — eq false');
// Категория заявки — то же имя поля, что рисует шаблон, и тот же ключ, что читает сервер.
$r = ConditionEditor::parse($vvod(['what' => 'ticket:category_id', 'op' => 'in', 'items' => ['42', '']]), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] === [] && $r['rule']['clauses'][0] === ['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42]],
    'категория заявки сохраняется', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = ConditionEditor::parse($vvod(['what' => 'ticket:category_id', 'op' => 'in']), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] !== [] && $r['rule'] === [], 'категория без значения — причина словами');
$r = ConditionEditor::parse($vvod(), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] !== [], '«Только если» без условий — причина словами');
$r = ConditionEditor::parse($vvod(['what' => '']), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok(str_contains(implode(' ', $r['errors']), 'выберите, что проверяем'), 'пустое «Что проверяем» — причина словами');
$r = ConditionEditor::parse($vvod(['what' => 'decision:4:x', 'op' => 'yes']), ConditionEditor::SCOPE_STAGE, $marshrut, $etapyM[1], $etapyM);
ok($r['errors'] !== [], 'ссылка на этап ниже не сохраняется');
$r = ConditionEditor::parse($vvod(['what' => 'form:q-list', 'op' => 'in', 'values' => ['9']]), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] !== [], 'вариант, которого нет в вопросе, не сохраняется');
$r = ConditionEditor::parse($vvod(['what' => 'ticket:category_id', 'op' => 'in', 'items' => ['42']]), ConditionEditor::SCOPE_START, $marshrut);
ok($r['errors'] !== [], '«Запускать, если» — поле заявки не принимается');
$r = ConditionEditor::parse(['mode' => 'off'], ConditionEditor::SCOPE_START, $marshrut);
ok($r['mode'] === ConditionEditor::MODE_OFF && $r['rule'] === [] && $r['errors'] === [], '«Не запускать по форме» — без правила');
ok(ConditionEditor::parse(['mode' => 'off'], ConditionEditor::SCOPE_STAGE, $marshrut)['errors'] !== [], 'у этапа «Не запускать по форме» нет');
// Прежнее условие по типу заявки остаётся как было, новое не задаётся.
$tip = ['source' => 'ticket', 'field' => 'type', 'operator' => 'in', 'value' => [2]];
$sTipom = $etap30; $sTipom['run_if'] = json_encode(['version' => 1, 'join' => 'all', 'clauses' => [$tip]]);
$r = ConditionEditor::parse($vvod(['what' => 'ticket:type', 'keep' => '0']), ConditionEditor::SCOPE_STAGE, $marshrut, $sTipom, $etapyM);
ok($r['errors'] === [] && $r['rule']['clauses'][0] === $tip, 'прежнее условие по типу заявки сохраняется как было');
$r = ConditionEditor::parse($vvod(['what' => 'ticket:type', 'keep' => '0']), ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($r['errors'] !== [], 'новое условие по типу заявки не задаётся');

$vid_ = ConditionEditor::view(ConditionEditor::SCOPE_STAGE, 'run_if_editor', ConditionEditor::MODE_IF,
    $pr($usl('q-list', 'in', ['1']), ['source' => 'decision', 'stage_id' => 2, 'key' => 'dop', 'operator' => 'eq', 'value' => true]),
    $marshrut, $etap30, $etapyM);
ok(count($vid_['clauses']) === 2 && $vid_['clauses'][0]['op'] === 'in' && $vid_['clauses'][1]['op'] === 'yes' && $vid_['can_add'],
    'вид: сохранённые условия — строки редактора, третью можно добавить');
ok(str_starts_with($vid_['clauses'][0]['phrase'], 'Этап выполняется, если в форме «Вопрос q-list» — Вариант А')
    && str_starts_with($vid_['clauses'][1]['phrase'], '…и на этапе 20 ответили «да»'), 'фраза под каждым условием', json_encode(array_column($vid_['clauses'], 'phrase'), JSON_UNESCAPED_UNICODE));
ok(str_starts_with($vid_['summary'], 'если «Вопрос q-list» — Вариант А и 20: «Нужна проверка» — да'), 'кратко в заголовке', $vid_['summary']);
// Заглушка списков GLPI (User::dropdown, Group::dropdown, ITILCategory::dropdown): имя поля — как
// передал шаблон, выбранные значения — <option selected>, specific_tags — атрибутами. Без базы.
$zaglushkaSpiska = static function (string $f, array $a): string {
    $p = $a[0];
    $attr = '';
    foreach (is_array($p['specific_tags'] ?? null) ? $p['specific_tags'] : [] as $k => $v) { $attr .= ' ' . htmlspecialchars((string) $k) . '="' . htmlspecialchars((string) $v) . '"'; }
    $opts = '';
    foreach ((array) ($p['value'] ?? []) as $v) { $opts .= '<option value="' . htmlspecialchars((string) $v) . '" selected>№' . (int) $v . '</option>'; }
    return '<select multiple data-stub="' . htmlspecialchars($f) . '" name="' . htmlspecialchars((string) $p['name']) . '"' . $attr . '>' . $opts . '</select>';
};
// Шаблон собирается и рисует то, что читает parse. Списки GLPI (call) — заглушкой: без базы.
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['autoescape' => 'html']);
$twig->addFunction(new \Twig\TwigFunction('call', $zaglushkaSpiska, ['is_safe' => ['html']]));
$html = $twig->render('condition_editor.html.twig', $vid_ + ['draft_url' => '/plugins/itilflow/front/draft.php']);
preg_match_all('/\sname="([^"]+)"/', $html, $imena);
ok(in_array('run_if_editor[clauses][0][what]', $imena[1], true) && in_array('run_if_editor[clauses][2][items][]', $imena[1], true)
    && in_array('run_if_editor[mode]', $imena[1], true) && in_array('run_if_editor[join]', $imena[1], true),
    'шаблон: поля редактора под именем prefix');
ok(array_filter($imena[1], static fn(string $n): bool => str_contains($n, ':')) === [], 'в именах полей нет двоеточий');
ok(preg_match('/data-ce-clause="2"\s+hidden disabled/', $html) === 1 && preg_match('/data-ce-clause="0"\s*>/', $html) === 1,
    'третье условие скрыто и не отправляется, пустых строк на экране нет');
ok(preg_match('/value="1"\s+checked/', $html) === 1 && str_contains($html, 'data-draft-url="/plugins/itilflow/front/draft.php"')
    && str_contains($html, 'data-stub="User::dropdown"') && str_contains($html, 'Этап выполняется, если в форме'),
    'шаблон: выбранный вариант, пересчёт без сохранения, список пользователей GLPI, фраза');
ok(!preg_match('/<script>(?!\s*\(function)/', $html), 'скрипт редактора — внутри IIFE');
// Круг: браузер отправляет только поля без disabled — разбор даёт то же правило.
$otpravka = static function (string $html): array {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><form>' . $html . '</form>');
    $pary = [];
    foreach ((new DOMXPath($dom))->query('//input | //select | //textarea') as $el) {
        $imya = $el->getAttribute('name');
        if ($imya === '' || $el->hasAttribute('disabled')) { continue; }
        $tip = strtolower($el->getAttribute('type'));
        if (in_array($tip, ['radio', 'checkbox'], true) && !$el->hasAttribute('checked')) { continue; }
        if ($el->nodeName === 'select') {
            $vybrano = [];
            foreach ($el->getElementsByTagName('option') as $o) { if ($o->hasAttribute('selected')) { $vybrano[] = $o->getAttribute('value'); } }
            if (!$vybrano && !$el->hasAttribute('multiple') && $el->getElementsByTagName('option')->length) {
                $vybrano = [$el->getElementsByTagName('option')->item(0)->getAttribute('value')];
            }
            foreach ($vybrano as $v) { $pary[] = rawurlencode($imya) . '=' . rawurlencode($v); }
            continue;
        }
        $pary[] = rawurlencode($imya) . '=' . rawurlencode($el->getAttribute('value'));
    }
    parse_str(implode('&', $pary), $out);
    return $out;
};
$post = $otpravka($html);
$krug = ConditionEditor::parse($post['run_if_editor'] ?? [], ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($krug['errors'] === [] && $krug['rule'] === $pr($usl('q-list', 'in', ['1']), ['source' => 'decision', 'stage_id' => 2, 'key' => 'dop', 'operator' => 'eq', 'value' => true]),
    'круг: нарисованная форма сохраняет то же правило', json_encode($krug, JSON_UNESCAPED_UNICODE));
// По поведению: категория заявки и пользователь — значения из списков GLPI под тем именем,
// которое рисует шаблон; нарисованная форма с категориями 42 и 43 даёт то же правило.
$sKategoriej = $pr(['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42, 43]], $usl('q-user', 'in', [$ivanov]));
$html = $twig->render('condition_editor.html.twig', ConditionEditor::view(ConditionEditor::SCOPE_STAGE, 'run_if_editor',
    ConditionEditor::MODE_IF, $sKategoriej, $marshrut, $etap30, $etapyM));
$post = $otpravka($html);
$krug = ConditionEditor::parse($post['run_if_editor'] ?? [], ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok($krug['errors'] === [] && $krug['rule'] === $sKategoriej, 'круг: категория заявки и пользователь сохраняются тем именем, что рисует шаблон',
    json_encode([$post['run_if_editor'] ?? null, $krug], JSON_UNESCAPED_UNICODE));
// Порядок условий — как на странице: блок с бо́льшим номером выше — его условие первое.
$r = ConditionEditor::parse(['mode' => 'if', 'clauses' => [2 => ['what' => 'form:q-text', 'op' => 'filled'], 0 => ['what' => 'form:q-file', 'op' => 'empty']]],
    ConditionEditor::SCOPE_STAGE, $marshrut, $etap30, $etapyM);
ok(array_column($r['rule']['clauses'] ?? [], 'question_uuid') === ['q-text', 'q-file'], 'условия — в порядке страницы, а не номеров блоков');
$post = $otpravka($twig->render('condition_editor.html.twig', ConditionEditor::view(ConditionEditor::SCOPE_STAGE, 'run_if_editor',
    ConditionEditor::MODE_ALWAYS, [], $marshrut, $etap30, $etapyM)));
ok(($post['run_if_editor'] ?? null) === ['mode' => 'always'], '«Всегда»: форма отправляет только режим, без пустых условий', json_encode($post, JSON_UNESCAPED_UNICODE));

$vsegda = ConditionEditor::view(ConditionEditor::SCOPE_STAGE, 'run_if_editor', ConditionEditor::MODE_ALWAYS, [], $marshrut, $etap30, $etapyM);
ok($vsegda['clauses'] === [] && $vsegda['summary'] === 'всегда' && array_column($vsegda['modes'], 'value') === ['always', 'if'], '«Всегда»: пустых строк нет');
$zap = ConditionEditor::view(ConditionEditor::SCOPE_START, 'start_if', ConditionEditor::MODE_OFF, [], $marshrut);
ok(array_column($zap['modes'], 'value') === ['off', 'always', 'if'] && str_contains($zap['modes'][0]['hint'], 'бизнес-правило'),
    'у запуска — «Не запускать по форме» с подсказкой про бизнес-правило');

// ---------------------------------------------------------------- текст условий
ok(ConditionText::short($pr($resh('eq', true)), $etapy) === 'если 20: «Нужно дополнительное согласование» — да', 'кратко: «если 20: «…» — да»');
ok(str_starts_with(ConditionText::phrase($usl('q-text', 'filled'), ConditionEditor::SCOPE_START), 'Маршрут запускается, если в форме заполнено'), 'фраза запуска');
$prichina = ConditionText::reason($pr($resh('eq', true), $usl('q-text', 'filled')),
    $etap(Step::DONE, false) + ['form_facts' => $fakty(['q-text' => $izvestno('text', true)])['form_facts']], $etapy);
ok($prichina === '20: «Нужно дополнительное согласование» — нет', 'причина — только условие, давшее «нет»', $prichina);
$prichina = ConditionText::reason($pr($usl('q-user', 'in', [$petrov])), $fakty(['q-user' => $izvestno('ref', $ivanov)]), [],
    static fn(string $t, int $id): string => $id === 17 ? 'Пользователь А' : 'Пользователь Б');
ok($prichina === '«Вопрос q-user» — Пользователь А', 'причина по пользователю — именем', $prichina);
ok(ConditionText::reason($pr($usl('q-date', 'before_days', 3)), $data('2026-10-31 00:00:00'), []) === '«Вопрос q-date» — через 10 дней после подачи',
    'причина по дате — «через N дней после подачи»');
ok(ConditionText::short($pr($usl('q-file', 'empty'))) === 'если не приложен «Вопрос q-file»', 'файл: «не приложен»');

// ---------------------------------------------------------------- настройка этапа
use GlpiPlugin\Itilflow\BranchConfiguration;
use GlpiPlugin\Itilflow\Readiness;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\StageForm;

$forma = ['run_if_editor' => $vvod(['what' => 'decision:2:dop', 'op' => 'yes']), '_branch_present' => '1', '_branch_unknown' => 'ask',
    '_decision_rows' => [['key' => '', 'label' => ' Нужен выезд? '], ['key' => 'old', 'label' => 'Прежний вопрос']]];
$s = BranchConfiguration::fromEditor($forma, $marshrut, $etap30, $etapyM);
$items = json_decode((string) $s['decision_items'], true);
ok(json_decode((string) $s['run_if'], true) === $pr(['source' => 'decision', 'stage_id' => 2, 'key' => 'dop', 'operator' => 'eq', 'value' => true])
    && $s['unknown_policy'] === 'ask', 'форма этапа: условие и «Если ответа нет» сохраняются');
ok(count($items) === 2 && $items[0]['label'] === 'Нужен выезд?' && preg_match('/^q_[0-9a-f]{16}$/', $items[0]['key']) === 1 && $items[1]['key'] === 'old',
    'вопросы этапа: новый получает ключ, у прежнего ключ остаётся');
$s = BranchConfiguration::fromEditor(['run_if_editor' => ['mode' => 'always'], '_decision_rows' => []], $marshrut, $etap30, $etapyM);
ok($s['run_if'] === null && $s['decision_items'] === null && $s['unknown_policy'] === 'run', '«Всегда» и без вопросов — пусто, без пустых строк');
$nelzya = static function (callable $f): string { try { $f(); return ''; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
ok(str_contains($nelzya(static fn() => BranchConfiguration::fromEditor(['run_if_editor' => $vvod()], $marshrut, $etap30, $etapyM)), 'условий нет'),
    '«Только если» без условий не сохраняется — причина словами');
ok($nelzya(static fn() => BranchConfiguration::fromEditor(['_decision_rows' => array_fill(0, 5, ['key' => '', 'label' => 'В'])], $marshrut, $etap30, $etapyM)) !== '',
    'больше четырёх вопросов этапа не сохраняется');

// Причины — названиями этапов.
$vniz = $etapyM; $vniz[1]['ranking'] = 40;
$osh = implode(' | ', BranchConfiguration::validateRoute($marshrut, $vniz));
ok(str_contains($osh, 'Этап 25 «Дополнительное согласование»: Условие 1: ссылается на этап 40 «Проверка», а он идёт ниже'), 'ссылка на этап ниже — названием', $osh);
$bezVoprosa = $etapyM; $bezVoprosa[1]['decision_items'] = null;
ok(str_contains(implode(' | ', BranchConfiguration::validateRoute($marshrut, $bezVoprosa)), 'у этапа 20 «Проверка» больше нет вопроса'),
    'удалённый вопрос этапа — названием этапа');
$udalen = [$etapyM[0], $etapyM[2]];
ok(str_contains(implode(' | ', BranchConfiguration::validateRoute($marshrut, $udalen)), 'ссылается на удалённый этап'), 'удалённый этап — словами');
$izFormy = $etapyM[2] + ['execution_mode' => 'approval', 'approver_source' => 'form'];
ok(str_contains(implode(' | ', BranchConfiguration::validateSnapshotStage($izFormy, $etapyM, $marshrut)), 'Согласование из формы'),
    'у «Согласования из формы» условие не допускается — словами словаря');
$redFormy = BranchConfiguration::editor($marshrut, $izFormy, $etapyM);
ok($redFormy['form_approval'] && $redFormy['editor']['mode'] === 'always' && $redFormy['editor']['clauses'] === [],
    'у «Согласования из формы» редактор отправляет «Всегда»');
$red = BranchConfiguration::editor($marshrut, $etapyM[1], $etapyM);
ok(($red['refs']['dop'] ?? '') === '25', 'вопрос этапа: «На этот ответ опирается этап 25»');
ok(BranchConfiguration::editor($marshrut, $etap30, $etapyM)['unknown_possible'] === false
    && BranchConfiguration::editor($marshrut, $etapyM[2], $etapyM)['unknown_possible'] === true,
    '«Если ответа нет» — только когда в условиях есть ответы формы или вопросы этапов');
$s30 = $etap30;
foreach ([
    ['только категория заявки', [['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42]]], false],
    ['только «этап не нужен по условию»', [['source' => 'stage_not_needed', 'stage_id' => 3, 'operator' => 'eq', 'value' => true]], false],
    ['категория и «не нужен»', [['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42]],
        ['source' => 'stage_not_needed', 'stage_id' => 3, 'operator' => 'eq', 'value' => true]], false],
    ['ответ формы', [$usl('q-text', 'filled')], true],
    ['категория и ответ формы', [['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42]], $usl('q-file', 'empty')], true],
    ['вопрос этапа', [['source' => 'decision', 'stage_id' => 2, 'key' => 'dop', 'operator' => 'eq', 'value' => true]], true],
] as [$sluchaj, $c, $zhdu]) {
    $s30['run_if'] = json_encode($pr(...$c));
    ok(BranchConfiguration::editor($marshrut, $s30, $etapyM)['unknown_possible'] === $zhdu, '«Если ответа нет» — ' . $sluchaj . ': ' . ($zhdu ? 'есть' : 'нет'));
}
ok(BranchConfiguration::newTicketTypeClauses($pr($tip), []) && !BranchConfiguration::newTicketTypeClauses($pr($tip), $pr($tip)),
    'тип заявки: новое условие не сохраняется, прежнее остаётся');
// Вариант без этапа; вариант, при котором маршрут не запускается, не в счёт.
$po = static fn(string $op, array $keys): array => ['run_if' => json_encode($pr($usl('q-list', $op, $keys))), 'id' => 7];
$gaps = BranchConfiguration::uncoveredOptions([$po('in', ['1'])]);
ok(count($gaps) === 1 && $gaps[0]['options'] === ['Вариант Б', 'Другое'] && $gaps[0]['stage'] === 7, 'вариант без этапа назван и привязан к этапу');
ok(BranchConfiguration::uncoveredOptions([$po('in', ['1'])], $pr($usl('q-list', 'in', ['1']))) === [],
    'варианты, при которых маршрут не запускается, этап покрывать не должен');

// Стрелки: перестановка, которая ломает ссылку или возврат, не делается.
$strukt = $marshrut + ['outcome_contract' => \GlpiPlugin\Itilflow\DefinitionSnapshot::MODE];
$swap = static function (array $stages, int $a, int $b): array { $r = $stages[$a]['ranking']; $stages[$a]['ranking'] = $stages[$b]['ranking']; $stages[$b]['ranking'] = $r; return $stages; };
ok(Stage::orderProblem($strukt, $swap($etapyM, 0, 1)) === null, 'перестановка 10 и 20 допустима');
ok(str_contains((string) Stage::orderProblem($strukt, $swap($etapyM, 1, 2)), 'ниже'), 'перестановка 20 и 25 ломает условие 25 — причина словами');
$vozvrat = $etapyM; $vozvrat[3]['return_targets'] = '[3]';
ok(str_contains((string) Stage::orderProblem($strukt, $swap($vozvrat, 2, 3)), 'возвращает на уточнение'), 'возврат на этап ниже — причина словами');

// «Что дальше» — по черновику этапов.
ok(str_contains(StageForm::whatNext($etapyM[1], $etapyM), 'этап 25 «Дополнительное согласование» (если 20: «Нужна проверка» — да), затем этап 30 «Исполнение»'),
    '«Что дальше»: условный этап ниже с «если …», затем ближайший этап без условия');
$prezhde = StageForm::reachedFrom($etapyM[3], $etapyM);
ok(($prezhde['label'] ?? '') === 'После этапа 25 «Дополнительное согласование» — сюда попадают так'
    && ($prezhde['text'] ?? '') === 'Выполнено → этап 30 «Исполнение»', '«После этапа N — сюда попадают так»: предыдущий этап и его «Выполнено →»',
    json_encode($prezhde, JSON_UNESCAPED_UNICODE));
ok(StageForm::reachedFrom($etapyM[0], $etapyM) === null, 'у первого этапа строки «После этапа N» нет');
ok(str_starts_with((string) (StageForm::reachedFrom($etapyM[2], $etapyM)['text'] ?? ''), 'Выполнено → этап 25 «Дополнительное согласование» (если 20:'),
    'после этапа 20 сюда попадают по условию — с «если …»');

// SLA дочерней заявки — по календарю организации дочерней заявки, а не маршрута (как движок).
$kalendari = [1 => 11, 2 => 22, 3 => 33];
$kalendar = static fn(int $e): int => $kalendari[$e] ?? 0;
$gruppaV = static fn(int $g): ?int => $g === 5 ? 3 : null;
$marshrutK = ['entities_id' => 1, 'calendars_id' => 99];
ok(Stage::childCalendar(['entity_strategy' => 'fixed', 'entities_id_target' => 2], $marshrutK, $kalendar, $gruppaV) === 22,
    'SLA: «В указанной организации» — календарь этой организации');
ok(Stage::childCalendar(['entity_strategy' => 'by_group', 'groups_id' => 5], $marshrutK, $kalendar, $gruppaV) === 33,
    'SLA: «В организации группы» — календарь организации группы');
ok(Stage::childCalendar(['entity_strategy' => 'inherit'], $marshrutK, $kalendar, $gruppaV) === 11,
    'SLA: «В организации заявки» — календарь организации маршрута, не календарь маршрута');

// Ошибка под полем у нового этапа: при добавлении номера нет (-1), форма нового этапа читает 0.
$_SESSION['itilflow_stage_errors'] = [];
$novyj = new Stage();
$novyj->fields = [];
(new ReflectionMethod(Stage::class, 'fieldError'))->invoke($novyj, 'deadline', 'Срок слишком большой.');
ok(Stage::takeFieldErrors(0) === ['deadline' => 'Срок слишком большой.'] && ($_SESSION['itilflow_stage_errors'] ?? []) === [],
    'новый этап: ошибка срока под полем видна на форме нового этапа', json_encode($_SESSION['itilflow_stage_errors'] ?? null));

// Готовность по условиям: необязательный или скрытый вопрос, удалённый вопрос, «не нужен» у этапа без условия.
$katalogG = $katalog;
$katalogG['q-list']['required'] = false;
$katalogG['q-text']['required'] = false;
$katalogG['q-date']['conditional'] = true;
unset($katalogG['q-file']);
$etapyG = [
    ['id' => 1, 'ranking' => 10, 'name' => 'Первый', 'run_if' => null, 'decision_items' => null],
    ['id' => 2, 'ranking' => 20, 'name' => 'Второй', 'decision_items' => null,
        'run_if' => json_encode($pr($usl('q-list', 'in', ['1']), $usl('q-text', 'filled')))],
    ['id' => 3, 'ranking' => 30, 'name' => 'Третий', 'decision_items' => null, 'run_if' => json_encode($pr($usl('q-date', 'filled')))],
    ['id' => 4, 'ranking' => 40, 'name' => 'Четвёртый', 'decision_items' => null, 'run_if' => json_encode($pr($usl('q-file', 'filled')))],
    ['id' => 5, 'ranking' => 50, 'name' => 'Пятый', 'decision_items' => null,
        'run_if' => json_encode($pr(['source' => 'stage_not_needed', 'stage_id' => 1, 'operator' => 'eq', 'value' => true]))],
];
$g = Readiness::conditionLines($marshrut + ['outcome_contract' => 'structured_v1'], $etapyG, $katalogG);
$zametki = implode(' | ', array_column($g['notes'], 'text'));
$problemy = implode(' | ', array_column($g['problems'], 'text'));
ok(str_contains($zametki, 'Этап 20 «Второй»: вопрос «Вопрос q-list» необязательный — сработает «Если ответа нет».'),
    'готовность: необязательный список — предупреждение', $zametki);
ok(!str_contains($zametki, 'Вопрос q-text'), 'готовность: пустой необязательный текст известен («не заполнено») — без предупреждения', $zametki);
ok(str_contains($zametki, 'Этап 30 «Третий»: вопрос «Вопрос q-date» бывает скрыт'), 'готовность: скрываемый вопрос — предупреждение', $zametki);
ok(str_contains($problemy, 'Этап 40 «Четвёртый»: вопрос формы «Вопрос q-file» удалён или изменён'), 'готовность: удалённый вопрос формы — проблема с названием', $problemy);
ok(str_contains($zametki, 'Этап 50 «Пятый»: условие 1 не выполнится: этап 10 «Первый» нужен всегда.'), 'готовность: «не нужен по условию» у этапа без условия', $zametki);
ok(array_column($g['notes'], 'stage') !== [] && !in_array(null, array_column(array_filter($g['notes'],
    static fn(array $x): bool => str_starts_with($x['text'], 'Этап')), 'stage'), true), 'строки готовности привязаны к этапам');

// Строка этапа в списке: и проблемы, и предупреждения — без «Этап N «…»:» в начале.
$st20 = $etapyG[1];
ok(Stage::rowTexts($g['notes'], $st20) === ['вопрос «Вопрос q-list» необязательный — сработает «Если ответа нет».'],
    'строка этапа: предупреждение без «Этап 20 «Второй»:»', json_encode(Stage::rowTexts($g['notes'], $st20), JSON_UNESCAPED_UNICODE));

// Шаблоны: блок «Когда выполнять / Вопросы этапа» и список этапов.
$zagruzchik = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates');
$zagruzchik->addPath(dirname(__DIR__, 2) . '/templates', 'itilflow');
$twig = new \Twig\Environment($zagruzchik, ['autoescape' => 'html', 'strict_variables' => false]);
$twig->addFunction(new \Twig\TwigFunction('call', $zaglushkaSpiska, ['is_safe' => ['html']]));
$twig->addFunction(new \Twig\TwigFunction('csrf_token', static fn(): string => 'tok'));
$twig->addFunction(new \Twig\TwigFunction('path', static fn(string $p): string => $p));
$html = $twig->render('branch_configuration.html.twig', ['branch' => BranchConfiguration::editor($marshrut, $etapyM[1], $etapyM),
    'rand' => 5, 'draft_url' => '/draft', 'item' => ['fields' => ['execution_mode' => 'inline']]]);
$post = $otpravka($html);
ok(($post['_decision_rows'] ?? null) === [['key' => 'dop', 'label' => 'Нужна проверка']] && ($post['run_if_editor'] ?? null) === ['mode' => 'always']
    && !isset($post['_branch_unknown']), 'вопросы этапа: только заданные, пустых полей нет; «Если ответа нет» скрыт без условий',
    json_encode($post, JSON_UNESCAPED_UNICODE));
ok(str_contains($html, '+ Добавить вопрос') && str_contains($html, 'Нужен этапу 25 — сначала измените его условие.'), 'кнопка «+ Добавить вопрос» и ссылка на этап ниже');
// 1.13.1: на ответ опирается этап ниже — «Убрать» у этого вопроса недоступна (раньше прятала вопрос молча).
preg_match_all('/<button[^>]*data-itilflow-question-remove[^>]*>/u', $html, $ubrat);
ok(count($ubrat[0]) === 4 && str_contains($ubrat[0][0], 'disabled') && str_contains($ubrat[0][0], 'aria-describedby="itilflow-q-ref-5-0"')
    && !str_contains($ubrat[0][1], 'disabled') && str_contains($html, 'id="itilflow-q-ref-5-0"')
    && str_contains($html, "if (!btn || btn.disabled) {"),
    'вопрос, на который ссылается этап ниже, убрать нельзя: кнопка недоступна и связана с пояснением', implode(' | ', $ubrat[0]));
$html = $twig->render('branch_configuration.html.twig', ['branch' => $redFormy, 'rand' => 6, 'item' => ['fields' => ['execution_mode' => 'approval']]]);
ok(str_contains($html, 'Условия нет: запрос на согласование уходит при отправке формы') && preg_match('/data-itilflow-branch-editor\s+hidden/', $html) === 1,
    '«Согласование из формы»: вместо условий — строка');
$html = $twig->render('stages.html.twig', ['readiness' => ['ready' => true, 'title' => 'Готов', 'problems' => [], 'notes' => []],
    'process' => ['fields' => ['id' => 5]], 'can_edit' => true, 'rows' => [
        ['id' => 1, 'ranking' => 10, 'name' => 'Первый', 'mode' => 'Задача', 'when' => 'всегда', 'who' => 'Группа', 'who_sub' => '', 'deadline' => '—',
            'reject' => '—', 'reject_sub' => '', 'report_required' => false, 'is_optional' => false, 'problems' => []],
        ['id' => 2, 'ranking' => 20, 'name' => 'Второй', 'mode' => 'Задача', 'when' => 'если 10: «В» — да', 'who' => 'Группа', 'who_sub' => '', 'deadline' => '—',
            'reject' => '—', 'reject_sub' => '', 'report_required' => false, 'is_optional' => false, 'problems' => ['не выбрана группа.'],
            'notes' => ['вопрос «В» в форме необязательный.']]]]);
ok(str_contains($html, 'data-itilflow-row-problem>не выбрана группа.') && str_contains($html, 'data-itilflow-row-note>вопрос «В» в форме необязательный.'),
    'строка списка этапов: и проблемы, и предупреждения этапа');
ok(substr_count($html, 'name="move"') === 4 && preg_match('/value="up"[^>]*aria-label="Поднять этап 10[^"]*"\s+disabled/', $html) === 1
    && preg_match('/value="down"[^>]*aria-label="Опустить этап 20[^"]*"\s+disabled/', $html) === 1, 'стрелки ↑ ↓: у первого нет «вверх», у последнего — «вниз»');
ok(str_contains($html, '<th>Когда</th>') && str_contains($html, 'если 10: «В» — да') && substr_count($html, 'name="purge"') === 2, 'колонка «Когда» и «Убрать»');

itog();
