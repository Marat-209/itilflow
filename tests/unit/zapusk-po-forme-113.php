<?php

/**
 * Запуск маршрута по ответу формы:
 * - формат «Запускать, если»;
 * - «Запускать, если» — только ответы формы, без полей заявки;
 * - выбор на одну созданную заявку: ровно один «да» — запуск; два «да» — никого;
 *   «неизвестно» — никого, даже рядом с «да»; ни одного — никого;
 * - назначение запуска по форме берётся из start_source;
 * - покрытие считается по назначению: вариант, при котором подходят два маршрута, виден.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\FormStart;
use GlpiPlugin\Itilflow\StartReadiness;
use GlpiPlugin\Itilflow\StartRule;

if (!estKlass(StartRule::class) || !estKlass(FormStart::class) || !estKlass(StartReadiness::class)) {
    itog();
}

// --- формат (основа)
ok(StartRule::decode(null) === null && StartRule::decode('') === null, 'NULL — по форме не запускать');
ok((StartRule::decode('{"version":1,"mode":"always"}')['mode'] ?? '') === StartRule::MODE_ALWAYS, '«Всегда» читается');
ok((StartRule::decode('{"version":2,"mode":"always"}')['mode'] ?? '') === StartRule::MODE_INVALID, 'чужая версия — повреждённая запись, не угадывается');
ok(StartRule::encode(['mode' => StartRule::MODE_ALWAYS]) === '{"mode":"always","version":1}', 'запись «Всегда» — канонический JSON');

// --- назначение запуска (основа)
ok(FormStart::destinationMatches(['forms_destinations_id' => 0], null, 7), 'как в 1.12: назначение 0 — любое');
ok(!FormStart::destinationMatches(['forms_destinations_id' => 5], null, 7), 'как в 1.12: другое назначение не подходит');
ok(FormStart::destinationMatches(['forms_destinations_id' => 0],
    '{"version":1,"kind":"form","destination_id":7}', 7)
    && !FormStart::destinationMatches(['forms_destinations_id' => 0], '{"version":1,"kind":"form","destination_id":7}', 8),
    'запуск по форме — фактическое назначение запуска');

// --- «Запускать, если» и выбор маршрута
$vopros = ['id' => 5, 'uuid' => 'q-cat', 'label' => 'Категория', 'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeDropdown',
    'type' => 'choice', 'multiple' => false, 'itemtype' => null, 'supported' => true, 'reason' => null,
    'required' => true, 'conditional' => false,
    'options' => [['key' => '1', 'label' => 'Вариант А'], ['key' => '2', 'label' => 'Вариант Б']]];
$esli = static fn(string $key): array => ['version' => 1, 'mode' => StartRule::MODE_CONDITIONS, 'rule' => [
    'version' => 1, 'join' => 'all', 'clauses' => [
        ['source' => 'form_answer', 'question_uuid' => 'q-cat', 'question' => $vopros, 'operator' => 'in', 'value' => [$key]],
    ]]];
$vsegda = ['version' => 1, 'mode' => StartRule::MODE_ALWAYS, 'rule' => []];
$fakty = static fn(?string $key): array => ['version' => 1, 'status' => 'final', 'source' => ['mode' => 'callback'],
    'input_revision' => 'x', 'facts' => ['q-cat' => $key === null
        ? ['status' => 'unknown', 'type' => 'choice', 'value' => null, 'reason' => 'visibility_unproven']
        : ['status' => 'known', 'type' => 'choice', 'value' => $key, 'reason' => null]]];
$A = ['id' => 101, 'name' => 'Маршрут А', 'start' => $esli('1')];
$B = ['id' => 102, 'name' => 'Маршрут Б', 'start' => $esli('2')];

$bilet = ['version' => 1, 'mode' => StartRule::MODE_CONDITIONS, 'rule' => ['version' => 1, 'join' => 'all', 'clauses' => [
    ['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42]]]]];
ok(StartRule::validate($bilet, ['q-cat' => $vopros]) !== [], '«Запускать, если» — только ответы формы, поле заявки не допускается');

$v = FormStart::select([$A, $B], $fakty('1'));
ok($v['kind'] === FormStart::ONE && $v['process_id'] === 101, 'один «да» — запускается он', json_encode($v));
$v = FormStart::select([$A, ['id' => 103, 'name' => 'Всегда', 'start' => $vsegda]], $fakty('1'));
ok($v['kind'] === FormStart::MANY && $v['process_id'] === null, 'два «да» — не запускается ни один', json_encode($v));
$v = FormStart::select([$A, $B], $fakty(null));
ok($v['kind'] === FormStart::UNKNOWN && $v['process_id'] === null, '«неизвестно» — не запускается ни один', json_encode($v));
$v = FormStart::select([$A], $fakty('2'));
ok($v['kind'] === FormStart::NONE && $v['process_id'] === null, 'ни одного «да» — не запускается ни один', json_encode($v));

$pokrytie = StartReadiness::coverage([$A, ['id' => 103, 'name' => 'Всегда', 'start' => $vsegda]], ['q-cat' => $vopros]);
$dva = array_values(array_filter($pokrytie, static fn(array $r): bool => $r['option'] === 'Вариант А' && count($r['yes']) === 2));
ok(count($dva) === 1, 'покрытие называет вариант, при котором подходят два маршрута', json_encode($pokrytie, JSON_UNESCAPED_UNICODE));

ok(StartRule::validate($esli('1'), ['q-cat' => $vopros]) === [], 'подтверждённый вопрос выбранной формы допустим');
ok(StartRule::validate($esli('1'), []) !== [], 'удалённый вопрос не допускает сохранение');
$changed = $vopros; $changed['options'][0]['label'] = 'Изменённый вариант';
ok(StartRule::validate($esli('1'), ['q-cat' => $changed]) !== [], 'изменённый каталог требует повторного выбора');
ok(StartRule::validate(StartRule::decode('{"version":1,"mode":"conditions","rule":[]}'), []) !== [],
    'только если без условий не превращается во всегда');
ok(StartRule::evaluate(['version' => 1, 'mode' => StartRule::MODE_INVALID], $fakty('1'))['truth'] === 'unknown',
    'повреждённый запуск не становится отрицательным или безусловным');
$flag = ['id' => 6, 'uuid' => 'q-flag', 'label' => 'Нужна проверка', 'type' => 'bool', 'supported' => true];
$flagClause = ['source' => 'form_answer', 'question_uuid' => 'q-flag', 'question' => $flag, 'operator' => 'eq', 'value' => false];
$flagStart = ['version' => 1, 'mode' => StartRule::MODE_CONDITIONS, 'rule' => [
    'version' => 1, 'join' => 'all', 'clauses' => [$flagClause]]];
$factsFlag = ['status' => 'final', 'facts' => ['q-flag' => ['status' => 'known', 'type' => 'bool', 'value' => false]]];
ok(StartRule::evaluate($flagStart, $factsFlag)['truth'] === 'yes', 'снятый флажок — известное нет');
$factsFlag['facts']['q-flag']['status'] = 'unknown';
ok(StartRule::evaluate($flagStart, $factsFlag)['truth'] === 'unknown', 'скрытый флажок не подменяется снятым');
$combined = $esli('1'); $combined['rule']['clauses'][] = $flagClause;
$combinedFacts = $fakty('2'); $combinedFacts['facts']['q-flag'] = ['status' => 'known', 'type' => 'bool', 'value' => false];
ok(StartRule::evaluate($combined, $combinedFacts)['truth'] === 'no', 'И требует оба известных условия');
$combined['rule']['join'] = 'any';
ok(StartRule::evaluate($combined, $combinedFacts)['truth'] === 'yes', 'ИЛИ допускает одно известное условие');
$combinedFacts['facts']['q-flag']['status'] = 'unknown';
ok(StartRule::evaluate($combined, $combinedFacts)['truth'] === 'unknown', 'неизвестность не скрывается за ИЛИ');
ok(FormStart::select([['id' => 103, 'start' => $vsegda], $B], $fakty(null))['kind'] === FormStart::UNKNOWN,
    'да рядом с неизвестным не разрешает запуск');
ok(FormStart::select([['id' => 103, 'start' => $vsegda], ['id' => 104, 'start' => $vsegda], $B], $fakty(null))['kind'] === FormStart::MANY,
    'два известных да остаются пересечением даже рядом с неизвестным');
ok(FormStart::select([$A, $A], $fakty('1'))['process_id'] === null, 'повтор кандидата не даёт однозначного разрешения');
$renamed = $esli('1');
$renamed['rule']['clauses'][] = $renamed['rule']['clauses'][0];
$renamed['rule']['clauses'][1]['question']['label'] = 'Новое название вопроса';
ok(StartRule::evaluate($renamed, $fakty('1'))['truth'] === 'yes', 'одна подпись вопроса не меняет семантику факта');
$renamed['rule']['clauses'][1]['question']['multiple'] = true;
ok(StartRule::evaluate($renamed, $fakty('1'))['truth'] === 'unknown', 'разные семантические определения одного вопроса закрывают запуск');

// ======================================================================== запуск по ответу формы
$PR = \GlpiPlugin\Itilflow\Process::class;
$FC = \GlpiPlugin\Itilflow\FormConfiguration::class;
$CE = \GlpiPlugin\Itilflow\ConditionEditor::class;

// --- готовность по назначению: строки по перебранным вариантам (StartReadiness::findings)
$kandidaty = [['id' => 1, 'name' => 'Маршрут А', 'start' => $vsegda], ['id' => 2, 'name' => 'Маршрут Б', 'start' => $vsegda],
    ['id' => 3, 'name' => 'Третий', 'start' => $vsegda]];
$var = static fn(array $yes, array $unknown, string $created, string $case, bool $complete = true): array =>
    ['yes' => $yes, 'maybe' => array_merge($yes, $unknown), 'unknown' => $unknown, 'created' => $created, 'case' => $case, 'complete' => $complete];
$f = StartReadiness::findings(1, 'Назначение «Заявка»', $kandidaty, [
    $var([1, 2], [], 'yes', '«Категория» — «Вариант А»'), $var([3], [], 'yes', '«Категория» — «Вариант Б»')]);
ok($f['problems'] === ['Назначение «Заявка»: если «Категория» — «Вариант А», подходят 2 маршрута — «Маршрут А», «Маршрут Б». Должен запускаться один: измените «Запускать, если».']
    && $f['notes'] === [], 'пересечение с этим маршрутом — проблема: вариант ответа и названия маршрутов', json_encode($f, JSON_UNESCAPED_UNICODE));
$f = StartReadiness::findings(3, 'Назначение «Заявка»', $kandidaty, [
    $var([1, 2], [], 'yes', '«Категория» — «Вариант А»'), $var([3], [], 'yes', '«Категория» — «Вариант Б»')]);
ok($f['problems'] === [], 'чужое пересечение не делает этот маршрут «не готов»: его покажет готовность тех маршрутов', json_encode($f, JSON_UNESCAPED_UNICODE));
$f = StartReadiness::findings(1, 'Назначение «Заявка»', $kandidaty, [$var([1, 2], [], 'maybe', '«Категория» — «Вариант А»')]);
ok(count($f['problems']) === 1 && str_contains($f['problems'][0], 'могут подойти 2 маршрута'),
    'ядро не дало однозначного ответа о создании назначения — «могут подойти», без утверждения', json_encode($f, JSON_UNESCAPED_UNICODE));
$f = StartReadiness::findings(1, 'Назначение «Заявка»', $kandidaty, [$var([1, 2], [], 'no', '«Категория» — «Вариант А»'), $var([1], [], 'yes', 'а')]);
ok($f['problems'] === [] && $f['notes'] === [], 'вариант, при котором форма заявку этого назначения не создаёт, не проверяется', json_encode($f, JSON_UNESCAPED_UNICODE));
$f = StartReadiness::findings(1, 'Назначение «Заявка»', $kandidaty, [$var([1], [], 'yes', 'а'),
    $var([], [], 'yes', '«Категория» — «Другое»'), $var([1], [2], 'yes', '«Категория» — нет ответа')]);
ok($f['problems'] === [] && $f['notes'] === ['Назначение «Заявка»: если «Категория» — «Другое» или «Категория» — нет ответа, не запускается ни один маршрут — заявка останется без маршрута.'],
    'вариант без маршрута и «неизвестно» рядом с «да» — одно примечание с вариантами ответов', json_encode($f, JSON_UNESCAPED_UNICODE));
$f = StartReadiness::findings(1, 'Назначение «Заявка»', $kandidaty, [$var([2], [], 'yes', 'а')]);
ok($f['notes'] === ['Назначение «Заявка»: этот маршрут не подходит ни при одном варианте ответов.'], 'маршрут, который не подходит ни при одном ответе, назван',
    json_encode($f, JSON_UNESCAPED_UNICODE));
$f = StartReadiness::findings(1, 'Назначение «Заявка»', $kandidaty, [$var([1], [], 'yes', 'а'), $var([], [1], 'maybe', '', false)]);
ok($f['problems'] === [] && count($f['notes']) === 1 && str_contains($f['notes'][0], 'не проверены — проверьте вручную'),
    'неполный перебор (пользователь, группа, категория) — примечание, а не выдуманный пробел', json_encode($f, JSON_UNESCAPED_UNICODE));

// --- покрытие: текст «заполнено / не заполнено»; взаимоисключающие условия на одном назначении
$tekst = ['id' => 7, 'uuid' => 'q-name', 'label' => 'Имя объекта', 'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeShortText',
    'type' => 'text', 'multiple' => false, 'itemtype' => null, 'supported' => true, 'reason' => null,
    'required' => false, 'conditional' => false, 'options' => []];
$zapolneno = ['source' => 'form_answer', 'question_uuid' => 'q-name', 'question' => $tekst, 'operator' => 'filled', 'value' => null];
$marshrutA = ['id' => 201, 'name' => 'Маршрут А', 'start' => $esli('1')];
$marshrutB = ['id' => 202, 'name' => 'Маршрут Б', 'start' => ['version' => 1, 'mode' => StartRule::MODE_CONDITIONS, 'rule' => [
    'version' => 1, 'join' => 'all', 'clauses' => [$esli('2')['rule']['clauses'][0], $zapolneno]]]];
$cov = StartReadiness::coverage([$marshrutA, $marshrutB], ['q-cat' => $vopros, 'q-name' => $tekst]);
ok(count($cov) === 4 && !array_filter($cov, static fn(array $v): bool => !$v['complete']),
    'текст перебирается «заполнено / не заполнено»: 2 × 2 полных варианта', json_encode(array_column($cov, 'case'), JSON_UNESCAPED_UNICODE));
$probel = array_values(array_filter($cov, static fn(array $v): bool => $v['yes'] === []));
ok(count($probel) === 1 && $probel[0]['case'] === '«Категория» — «Вариант Б», «Имя объекта» — не заполнено',
    'пробел назван вариантом ответов: вариант Б без имени объекта', json_encode($probel, JSON_UNESCAPED_UNICODE));
$f = StartReadiness::findings(202, 'Назначение «Заявка»', [$marshrutA, $marshrutB],
    array_map(static fn(array $v): array => $v + ['created' => 'yes'], $cov));
ok($f['problems'] === [] && count($f['notes']) === 1 && str_contains($f['notes'][0], '«Имя объекта» — не заполнено, не запускается ни один маршрут'),
    'взаимоисключающие условия — без пересечения; пробел — примечанием', json_encode($f, JSON_UNESCAPED_UNICODE));
ok(count(StartReadiness::coverage([$marshrutA, $marshrutB], ['q-cat' => $vopros, 'q-name' => array_replace($tekst, ['required' => true])])) === 2,
    'обязательный текст всегда заполнен — «не заполнено» не перебирается');

// --- два назначения одной формы и «любое назначение»
$stroki = [
    ['id' => 301, 'name' => 'Первое назначение', 'entities_id' => 0, 'is_recursive' => 1, 'forms_destinations_id' => 11, 'start_if' => StartRule::encode($esli('1'))],
    ['id' => 302, 'name' => 'Второе назначение', 'entities_id' => 0, 'is_recursive' => 1, 'forms_destinations_id' => 12, 'start_if' => '{"mode":"always","version":1}'],
    ['id' => 303, 'name' => 'Любое', 'entities_id' => 0, 'is_recursive' => 1, 'forms_destinations_id' => 0, 'start_if' => StartRule::encode($esli('2'))],
];
$d11 = StartReadiness::forDestination($stroki, 11, 0, []);
$d12 = StartReadiness::forDestination($stroki, 12, 0, []);
ok(array_column($d11, 'id') === [301, 303] && array_column($d12, 'id') === [302, 303],
    'два назначения одной формы: у каждого свои маршруты и общий с «любым назначением»');
$sozdano = static fn(array $c): array => array_map(static fn(array $v): array => $v + ['created' => 'yes'], StartReadiness::coverage($c, ['q-cat' => $vopros]));
$f11 = StartReadiness::findings(303, 'Назначение «Первое»', $d11, $sozdano($d11));
$f12 = StartReadiness::findings(303, 'Назначение «Второе»', $d12, $sozdano($d12));
ok($f11['problems'] === [] && count($f12['problems']) === 1 && str_contains($f12['problems'][0], 'Назначение «Второе»: если «Категория» — «Вариант Б», подходят 2 маршрута — «Второе назначение», «Любое»'),
    '«любое назначение» пересекается только на том назначении, где есть свой «Всегда»', json_encode([$f11, $f12], JSON_UNESCAPED_UNICODE));

// --- выбор: флажок «не отмечен» против «неизвестно»; «все» и «хотя бы одно»
$flagQ = ['id' => 6, 'uuid' => 'q-flag', 'label' => 'Нужна проверка', 'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeCheckbox',
    'type' => 'bool', 'multiple' => true, 'itemtype' => null, 'supported' => true, 'options' => [['key' => 'da', 'label' => 'Да']]];
$neOtmechen = ['id' => 401, 'name' => 'Без проверки', 'start' => ['version' => 1, 'mode' => StartRule::MODE_CONDITIONS, 'rule' => ['version' => 1,
    'join' => 'all', 'clauses' => [['source' => 'form_answer', 'question_uuid' => 'q-flag', 'question' => $flagQ, 'operator' => 'eq', 'value' => false]]]]];
$otmechen = $neOtmechen; $otmechen['id'] = 402; $otmechen['name'] = 'С проверкой'; $otmechen['start']['rule']['clauses'][0]['value'] = true;
$flagFacts = static fn(?bool $v): array => ['version' => 1, 'status' => 'final', 'source' => ['mode' => 'callback'], 'facts' => ['q-flag' => $v === null
    ? ['status' => 'unknown', 'type' => 'bool', 'value' => null, 'reason' => 'missing_answer']
    : ['status' => 'known', 'type' => 'bool', 'value' => $v, 'reason' => null]]];
$s = FormStart::select([$neOtmechen, $otmechen], $flagFacts(false));
ok($s['kind'] === FormStart::ONE && $s['process_id'] === 401, 'флажок «не отмечен» — известное «нет»: запускается маршрут «не отмечен»', json_encode($s));
$s = FormStart::select([$neOtmechen, $otmechen], $flagFacts(null));
ok($s['kind'] === FormStart::UNKNOWN && $s['process_id'] === null && $s['unknown'] === [401, 402],
    'флажок без ответа — «неизвестно», а не «не отмечен»: не запускается ни один', json_encode($s));
$ili = ['version' => 1, 'mode' => StartRule::MODE_CONDITIONS, 'rule' => ['version' => 1, 'join' => 'any',
    'clauses' => [$esli('1')['rule']['clauses'][0], $otmechen['start']['rule']['clauses'][0]]]];
$vse = $ili; $vse['rule']['join'] = 'all';
$oba = static fn(string $cat, ?bool $flag): array => ['version' => 1, 'status' => 'final', 'source' => ['mode' => 'callback'],
    'facts' => $fakty($cat)['facts'] + $flagFacts($flag)['facts']];
$para = [['id' => 501, 'name' => 'Хотя бы одно', 'start' => $ili], ['id' => 502, 'name' => 'Все', 'start' => $vse]];
$s = FormStart::select($para, $oba('1', false));
ok($s['kind'] === FormStart::ONE && $s['process_id'] === 501, '«хотя бы одно» — хватает одного «да»; «все» — нужны оба', json_encode($s));
$s = FormStart::select($para, $oba('1', true));
ok($s['kind'] === FormStart::MANY && $s['yes'] === [501, 502], 'выполнены оба условия — подходят оба маршрута, не запускается ни один', json_encode($s));

// --- один путь запуска на форму — в организации заявки; кандидаты — по организации
$zayavkaOrg = static function (int $entity): \Ticket {
    $t = new \Ticket(); $t->fields = ['id' => 90 + $entity, 'entities_id' => $entity, 'status' => 1, 'is_deleted' => 0]; return $t;
};
$marshrutOrg = static fn(int $id, int $entity, int $dest, ?string $start): array => ['id' => $id, 'name' => 'Маршрут ' . $id,
    'is_active' => 1, 'itemtype' => 'Ticket', 'outcome_contract' => 'structured_v1', 'entities_id' => $entity, 'is_recursive' => 0,
    'forms_forms_id' => 4, 'forms_destinations_id' => $dest, 'start_if' => $start];
$vsegdaJson = '{"mode":"always","version":1}';
$formaOrg1 = [$marshrutOrg(601, 1, 0, $vsegdaJson)];
ok(FormStart::enabledFor($formaOrg1, $zayavkaOrg(1)) && !FormStart::enabledFor($formaOrg1, $zayavkaOrg(2)),
    'две организации: «Запускать, если» в организации 1 подавляет правило только там, в организации 2 правило запускает как раньше');
ok(!FormStart::enabledFor([$marshrutOrg(602, 1, 0, null)], $zayavkaOrg(1)), 'маршрут формы без «Запускать, если» правило не подавляет');
$smes = [$marshrutOrg(611, 1, 0, $vsegdaJson), $marshrutOrg(612, 2, 5, $vsegdaJson), $marshrutOrg(613, 1, 6, $vsegdaJson),
    $marshrutOrg(614, 1, 5, null), $marshrutOrg(615, 1, 5, $vsegdaJson)];
ok(array_column(FormStart::candidatesFrom($smes, 5, $zayavkaOrg(1)), 'id') === [611, 615]
    && array_column(FormStart::candidatesFrom($smes, 5, $zayavkaOrg(2)), 'id') === [612],
    'кандидаты: назначение заявки или «любое», организация маршрута подходит заявке; без «Запускать, если» — не кандидат');
ok(FormStart::candidatesFrom($smes, 0, $zayavkaOrg(1)) === [], 'без номера назначения кандидатов нет');

// --- отправку формы не блокировать: сбой записи журнала в failed() не бросает
$zayavka = new \Ticket(); $zayavka->fields = ['id' => 77, 'entities_id' => 0];
$brosilo = null;
try {
    FormStart::failed(new \Glpi\Form\Destination\FormDestination(), new \Glpi\Form\AnswersSet(), [$zayavka], new \RuntimeException('проба'));
} catch (\Throwable $e) { $brosilo = $e; }
ok($brosilo === null, 'сбой записи журнала в FormStart::failed не бросает: иначе FormTransactionGuard откатил бы всю отправку формы',
    $brosilo ? get_class($brosilo) . ': ' . $brosilo->getMessage() : '');

// --- заявки без маршрута за 30 дней — построителем запросов GLPI (прямой SQL в GLPI 11 запрещён)
$fakeDb = new class {
    public array $sql = [];
    public function request(array $criteria): \ArrayIterator
    {
        $it = new \DBmysqlIterator(null);
        $it->buildQuery($criteria);
        $this->sql[] = $sql = $it->getSql();
        return new \ArrayIterator([str_contains($sql, 'COUNT(DISTINCT') ? ['total' => 2]
            : ['items_id' => 41, 'message' => 'Маршрут не запущен: подошли несколько маршрутов: «А», «Б».']]);
    }
};
$prezhnyaya = $GLOBALS['DB'] ?? null;
$GLOBALS['DB'] = $fakeDb;
$_SESSION['glpi_currenttime'] = '2026-10-21 12:00:00';
try {
    $r = (new \ReflectionMethod(StartReadiness::class, 'recentUnrouted'))->invoke(null, 7, [3, 4]);
} catch (\Throwable $e) {
    $r = get_class($e) . ': ' . $e->getMessage();
} finally {
    $GLOBALS['DB'] = $prezhnyaya;
}
$sqlVse = implode(' | ', $fakeDb->sql);
ok(is_array($r) && $r['total'] === 2 && $r['last'] === ['ticket' => 41, 'message' => 'Маршрут не запущен: подошли несколько маршрутов: «А», «Б».'],
    'заявки формы без маршрута: число и последняя причина — без прямого SQL', is_array($r) ? json_encode($r, JSON_UNESCAPED_UNICODE) : (string) $r);
ok(str_contains($sqlVse, "`v`.`date` >= '2026-09-21 12:00:00'") && str_contains($sqlVse, "`a`.`forms_forms_id` = '7'")
    && str_contains($sqlVse, "IN ('3', '4')") && str_contains($sqlVse, '`i`.`id` IS NULL') && str_contains($sqlVse, "`v`.`action` = 'action_form_start'"),
    'запрос журнала: 30 дней, эта форма, организации маршрута, только заявки без маршрута', $sqlVse);
ok(str_contains($sqlVse, 'INNER JOIN `glpi_tickets` AS `t` ON (`t`.`id` = `v`.`items_id`)') && str_contains($sqlVse, "`t`.`is_deleted` = '0'"),
    'заявки в корзине в «За последние 30 дней … без маршрута» не считаются', $sqlVse);

// --- запись «Запускать, если» из редактора формы маршрута (Process::startFromEditor)
$katalogR = ['q-cat' => $vopros + ['section' => 'Основное', 'type_label' => 'выпадающий список']];
$polya = ['id' => 9, 'itemtype' => 'Ticket', 'outcome_contract' => 'structured_v1', 'forms_forms_id' => 4, 'form_catalog' => $katalogR];
$E = $PR::START_EDITOR;
ok($PR::startFromEditor([$E => ['mode' => 'off']], $polya) === ['start_if' => null, 'error' => null, 'notice' => null],
    '«Не запускать по форме» — start_if NULL: маршрут запускает бизнес-правило');
ok($PR::startFromEditor([$E => ['mode' => 'always']], $polya)['start_if'] === '{"mode":"always","version":1}', '«Всегда» — канонический JSON');
$r = $PR::startFromEditor([$E => ['mode' => 'if', 'join' => 'all', 'clauses' => [['what' => 'form:q-cat', 'op' => 'in', 'values' => ['2']]]]], $polya);
$z = StartRule::decode($r['start_if']);
ok($r['error'] === null && ($z['mode'] ?? '') === StartRule::MODE_CONDITIONS && ($z['rule']['clauses'][0]['value'] ?? null) === ['2']
    && !isset($z['rule']['clauses'][0]['question']['section']) && StartRule::validate($z, ['q-cat' => $vopros]) === [],
    '«Только если»: правило из общего редактора проходит StartRule::validate, вопрос без полей редактора', (string) $r['start_if']);
$r = $PR::startFromEditor([$E => ['mode' => 'if']], $polya);
ok($r['start_if'] === null && str_starts_with((string) $r['error'], '«Запускать, если» — Выбрано «Только если», но условий нет'),
    '«Только если» без условий не сохраняется и не становится «Всегда»', (string) $r['error']);
$r = $PR::startFromEditor([$E => ['mode' => 'if', 'clauses' => [['what' => 'form:q-cat', 'op' => 'in', 'values' => ['2']]]], 'forms_forms_id' => 5], $polya);
ok($r['start_if'] === null && $r['error'] === null && str_contains((string) $r['notice'], '«Запускать, если» сброшено'),
    'сменили форму — условия по вопросам прежней сброшены, запуск по форме выключен', json_encode($r, JSON_UNESCAPED_UNICODE));
ok($PR::startMode(null) === ['off', []] && $PR::startMode('{"version":1,"mode":"always"}') === ['always', []]
    && $PR::startMode('{"version":7}') === ['if', []] && $PR::startMode(StartRule::encode($esli('1')))[1] == $esli('1')['rule'],
    'режим редактора по start_if: NULL, «Всегда», повреждённое — «Только если» без условий, правило');

// --- строки блока «Запуск маршрута» (FormConfiguration)
$info = $FC::informational(['forms_forms_id' => 0]);
ok(($info['no_form'] ?? '') === 'Форма не выбрана. В условиях этапов не будет ответов формы, а «Согласование из формы» не сработает.' && $info['approvals'] === [],
    'без формы — своя строка, без согласований');
$stroka = $FC::questionsLine(['a' => $vopros, 'b' => $tekst, 'c' => ['supported' => false, 'label' => 'Время']]);
ok($stroka === 'Ответы формы в условиях этапов: 2 вопроса — «Категория», «Имя объекта».',
    'вопросы формы в условиях этапов: число по-русски, только те, что видят условия', $stroka);
$d = $FC::approvalDescriptions([['id' => 1, 'name' => 'Заявка', 'known' => true, 'approvals' => [
    ['id' => 7, 'name' => 'Согласование руководителя', 'percent' => 100, 'who' => 'ответ на вопрос «Руководитель»']]]],
    [['name' => 'Согласование', 'ranking' => 10, 'approver_source' => 'form', 'form_validationsteps_id' => 7]]);
ok($d === ['Согласование в этом назначении: ответ на вопрос «Руководитель» → этап согласования «Согласование руководителя», нужно одобрений 100 %. Его принимает этап 10 «Согласование».'],
    'согласование назначения — одной строкой: кто, этап согласования, процент, принимающий этап', json_encode($d, JSON_UNESCAPED_UNICODE));

// --- шаблон: блок «Запуск маршрута» в форме маршрута (макросы полей GLPI — заглушкой, без базы)
$polePr = '<div class="col-12"><label class="col-form-label">{{ label }}</label><input name="{{ name }}" value="{{ value }}">{{ options.add_field_html ?? \'\' }}</div>';
$makrosy = '{% macro smallTitle(label, icon = \'\') %}<h4 class="card-subtitle">{{ label }}</h4>{% endmacro %}'
    . '{% macro textField(name, value, label = \'\', options = {}) %}' . $polePr . '{% endmacro %}'
    . '{% macro dropdownField(itemtype, name, value, label = \'\', options = {}) %}' . $polePr . '{% endmacro %}'
    . '{% macro dropdownYesNo(name, value, label = \'\', options = {}) %}' . $polePr . '{% endmacro %}'
    . '{% macro textareaField(name, value, label = \'\', options = {}) %}' . $polePr . '{% endmacro %}'
    . '{% macro readOnlyField(name, value, label = \'\', options = {}) %}' . $polePr . '{% endmacro %}'
    . '{% macro dropdownArrayField(name, value, elements, label = \'\', options = {}) %}<label class="col-form-label">{{ label }}</label>'
    . '<select name="{{ name }}">{% for k, v in elements %}<option value="{{ k }}"{% if k == value %} selected{% endif %}>{{ v }}</option>{% endfor %}</select>'
    . '{{ options.add_field_html ?? \'\' }}{% endmacro %}';
$papka = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates');
$papka->addPath(dirname(__DIR__, 2) . '/templates', 'itilflow');
$twigP = new \Twig\Environment(new \Twig\Loader\ChainLoader([
    new \Twig\Loader\ArrayLoader(['components/form/fields_macros.html.twig' => $makrosy]), $papka]), ['autoescape' => 'html']);
$twigP->addFunction(new \Twig\TwigFunction('csrf_token', static fn(): string => 'tok'));
$twigP->addFunction(new \Twig\TwigFunction('__', static fn(string $s): string => $s));
$twigP->addFunction(new \Twig\TwigFunction('call', static fn(string $f, array $a): string =>
    '<select multiple name="' . htmlspecialchars((string) $a[0]['name']) . '"></select>', ['is_safe' => ['html']]));
$marshrutP = new class {
    public array $fields = ['id' => 9, 'name' => 'Маршрут', 'entities_id' => 0, 'is_recursive' => 0, 'calendars_id' => 0, 'is_active' => 1,
        'comment' => '', 'itemtype' => 'Ticket', 'forms_forms_id' => 4, 'forms_destinations_id' => 0, 'enforcement' => 'audit',
        'block_resolution' => 1, 'public_log' => 1, 'helpdesk_tab' => 1, 'allow_withdraw' => 1, 'version' => 3];
    public function getFormURL(): string { return '/plugins/itilflow/front/process.form.php'; }
    public function isNewItem(): bool { return false; }
};
$zapuskP = ['summary' => 'Форма заявок · если «Категория» — Вариант Б',
    'info' => ['approvals' => $d, 'questions' => $stroka, 'no_form' => null],
    'editor' => $CE::view($CE::SCOPE_START, $E, $CE::MODE_IF, $z['rule'] ?? [], $polya) + ['draft_url' => '/plugins/itilflow/front/draft.php'],
    'siblings' => [['id' => 12, 'name' => 'Вариант А', 'summary' => 'если «Категория» — Вариант А',
        'destination' => 'любое назначение', 'url' => '/plugins/itilflow/front/process.form.php?id=12']]];
$kontekstP = ['item' => $marshrutP, 'params' => [], 'enforcements' => $PR::getEnforcementLabels(), 'itemtypes' => $PR::getItemtypeFormLabels(),
    'structured' => true, 'form_sources' => [0 => 'Не выбрана', 4 => 'Форма заявок'],
    'form_destinations' => [0 => 'Любое назначение формы, создающее заявку'], 'readiness' => null, 'new_contract' => '', 'legacy' => null];
$htmlP = '';
try { $htmlP = $twigP->render('process.html.twig', $kontekstP + ['start' => $zapuskP]); }
catch (\Throwable $e) { ok(false, 'форма маршрута собирается', get_class($e) . ': ' . $e->getMessage()); }
preg_match_all('/\sname="([^"]+)"/', $htmlP, $imenaP);
ok(in_array($E . '[mode]', $imenaP[1], true) && in_array($E . '[clauses][0][what]', $imenaP[1], true)
    && str_contains($htmlP, 'data-scope="start"') && str_contains($htmlP, 'data-prefix="' . $E . '"')
    && str_contains($htmlP, 'data-draft-url="/plugins/itilflow/front/draft.php"'),
    'блок «Запуск маршрута»: общий редактор с SCOPE_START, поля под именем, которое разбирает Process, пересчёт без сохранения');
ok(str_contains($htmlP, 'Не запускать по форме') && str_contains($htmlP, 'Маршрут запускается, если') && str_contains($htmlP, '>Запуск маршрута<'),
    'три режима запуска и фраза под условием');
ok(str_contains($htmlP, 'Другие маршруты этой формы') && str_contains($htmlP, 'href="/plugins/itilflow/front/process.form.php?id=12"')
    && str_contains($htmlP, '— любое назначение, если «Категория» — Вариант А'),
    '«Другие маршруты этой формы» — ссылками, с назначением и условием');
ok(str_contains($htmlP, 'Согласование в этом назначении:') && str_contains($htmlP, 'Ответы формы в условиях этапов: 2 вопроса'),
    'строки о согласовании назначения и вопросах формы — в блоке');
ok(!str_contains($htmlP, 'не подтверждает происхождение') && !str_contains($htmlP, 'Большие формы') && !preg_match('/заявител/iu', $htmlP),
    'нет служебной подсказки о происхождении, подсказки о больших формах и слова «заявитель»');
preg_match_all('/<script>(.*?)<\/script>/s', $htmlP, $skripty);
ok($skripty[1] !== [] && !array_filter($skripty[1], static fn(string $s): bool => !str_starts_with(trim($s), '(function () {')),
    'скрипты формы маршрута и редактора — внутри IIFE', (string) count($skripty[1]));
$htmlBez = $twigP->render('process.html.twig', $kontekstP + ['start' => ['summary' => 'форма не выбрана',
    'info' => $FC::informational(['forms_forms_id' => 0]), 'editor' => null, 'siblings' => []]]);
ok(str_contains($htmlBez, 'Форма не выбрана. В условиях этапов') && !str_contains($htmlBez, 'data-scope="start"'),
    'без формы — строка «Форма не выбрана», редактора запуска нет');

// 1.13.1: строка готовности — только про этот маршрут и коротко.
$R = \GlpiPlugin\Itilflow\Readiness::class;
ok(StartReadiness::rulesLine([]) === null, 'правил этого маршрута нет — строки о правилах нет');
ok(StartReadiness::rulesLine(['А']) === 'Правило «А» не запускает маршрут для заявок этой формы — его запускает форма.',
    'одно правило — одной строкой', (string) StartReadiness::rulesLine(['А']));
ok(StartReadiness::rulesLine(['А', 'Б', 'В', 'Г']) === 'Правила «А», «Б» и ещё 2 не запускают маршрут для заявок этой формы — его запускает форма.',
    'несколько правил — одна строка: два названия и «и ещё N»', (string) StartReadiness::rulesLine(['А', 'Б', 'В', 'Г']));
$istochnik = (string) file_get_contents(dirname(__DIR__, 2) . '/src/StartReadiness.php');
ok(preg_match('/function ruleNotes\(int \$process.*?\'a\.value\' => \(string\) \$process/su', $istochnik) === 1,
    'правила отбираются по маршруту действия (a.value = этот маршрут), как Readiness::rules — чужие правила в готовность не попадают');
ok($R::title(0, 4, null, ['А']) === 'Готов к запуску · 4 этапа · запуск: правило «А»', 'заголовок: одно правило — названием', $R::title(0, 4, null, ['А']));
ok($R::title(0, 5, null, ['А', 'Б', 'В']) === 'Готов к запуску · 5 этапов · запуск: правила (3)', 'заголовок: несколько правил — числом');
ok($R::title(0, 2, null, []) === 'Готов к запуску · запуск вручную', 'заголовок: без правил — «запуск вручную»');
ok($R::title(0, 1, 'запускается по ответу формы «Ф»', ['А']) === 'Готов к запуску · 1 этап · запускается по ответу формы «Ф»'
    && $R::title(2, 3, null, []) === 'Не готов к запуску: 2 проблемы', 'заголовок по форме и «не готов» — как раньше');

// 1.13.1: с одним «Чтением» на форме маршрута нет «Сохранить» и «Удалить навсегда»; готовность — с пересчётом без сохранения.
$knopki = static fn(array $b): string => $twigP->render('process.html.twig', $kontekstP + ['start' => $zapuskP, 'buttons' => $b,
    'readiness' => ['ready' => true, 'title' => 'Готов к запуску', 'problems' => [], 'notes' => []], 'draft_url' => '/plugins/itilflow/front/draft.php']);
$tolkoChtenie = $knopki(['add' => false, 'update' => false, 'purge' => false]);
ok(!str_contains($tolkoChtenie, 'name="update"') && !str_contains($tolkoChtenie, 'name="purge"') && !str_contains($tolkoChtenie, 'name="add"'),
    'только «Чтение»: на форме маршрута нет «Сохранить» и «Удалить навсегда»');
$admin = $knopki(['add' => false, 'update' => true, 'purge' => true]);
ok(str_contains($admin, 'name="update"') && str_contains($admin, 'name="purge"'), 'администратору маршрутов — обе кнопки');
ok(str_contains($admin, 'data-itilflow-readiness-box') && str_contains($admin, 'data-itilflow-draft-url="/plugins/itilflow/front/draft.php"'),
    'строка готовности в блоке, который пересчёт без сохранения заменяет; адрес пересчёта на форме');
itog();
