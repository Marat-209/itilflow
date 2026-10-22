<?php
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\FormFacts;
$bool = ['id' => 4, 'uuid' => 'question-a', 'label' => 'Synthetic checkbox', 'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeCheckbox',
    'type' => 'bool', 'multiple' => true, 'options' => [['key' => '1', 'label' => 'Yes']], 'itemtype' => null, 'supported' => true, 'reason' => null];
$normalize = static fn($d, $raw, $historical = true, $visible = true, $observed = null) => FormFacts::normalize($d, $d['native_type'], $raw, $observed ?? $d, $historical, $visible);
ok($normalize($bool, [])['value'] === false, 'проверенный видимый checkbox [] означает false');
ok($normalize($bool, ['1'])['value'] === true, 'проверенный единственный вариант означает true');
foreach ([[false,null],[true,false],[true,null]] as [$historical,$visible]) {
    ok($normalize($bool, [], $historical, $visible)['status'] === 'unknown', 'отсутствие исторической видимости не становится false');
}
$renamed = $bool; $renamed['options'][0]['label'] = 'Different meaning';
ok($normalize($bool, ['1'], true, true, $renamed)['status'] === 'unknown', 'изменённый смысл варианта закрывает даже прежний key');
$deleted = $bool; $deleted['uuid'] = 'recreated';
ok($normalize($bool, ['1'], true, true, $deleted)['status'] === 'unknown', 'пересозданный вопрос не сопоставляется по названию');
$choice = $bool; $choice['type'] = 'choice'; $choice['options'][] = ['key' => 'b', 'label' => 'Other'];
ok($normalize($choice, ['b','1'])['value'] === ['1','b'], 'multiple choice сохраняет полный отсортированный набор ключей');
foreach ([['gone'], ['1','gone'], ['1','1'], [false], null] as $raw) {
    ok($normalize($choice, $raw)['status'] === 'unknown', 'испорченный выбор не превращается в пустое пересечение');
}
ok($normalize($choice, ['1'], false, null)['value'] === ['1'], 'поздний выбор прочитан при совпадении ключей и подписей со снимком');
ok($normalize($bool, ['1'], false, null)['value'] === true, 'поздний выбранный checkbox прочитан при совпадении вариантов');
ok($normalize($choice, [], false, null)['status'] === 'unknown', 'пустой поздний multiple без видимости остаётся неизвестным');
ok($normalize($choice, ['1'], false, false)['status'] === 'unknown', 'скрытый выбор не используется');
foreach (['key', 'label'] as $field) {
    $changed = $choice; $changed['options'][0][$field] = 'changed';
    ok($normalize($choice, ['1'], false, null, $changed)['status'] === 'unknown', 'поздний выбор закрыт при изменении ' . $field);
}
$single = $choice; $single['multiple'] = false;
ok($normalize($single, 'b', false, null)['value'] === 'b', 'поздний одиночный выбор сохраняет ключ');
ok($normalize($single, ['b', '1'], false, null)['status'] === 'unknown', 'поздний одиночный выбор не принимает несколько ответов');
$n = $bool; $n['type'] = 'number'; $n['native_type'] = 'Glpi\\Form\\QuestionType\\QuestionTypeNumber'; $n['options'] = []; $n['multiple'] = false;
foreach (['001.200' => '1.2', '-0.000' => '0', '99999999999999999999.00100' => '99999999999999999999.001'] as $raw => $expected) {
    ok($normalize($n, $raw, false)['value'] === $expected, 'точная десятичная строка без float ' . $raw);
}
foreach ([1.2, '', '1e3', 'NaN', true, ' 1 ', '1,2'] as $raw) { ok($normalize($n, $raw)['status'] === 'unknown', 'неподтверждённое число закрыто'); }
$r = $n; $r['type'] = 'ref'; $r['native_type'] = 'Glpi\\Form\\QuestionType\\QuestionTypeItem'; $r['itemtype'] = 'User';
ok($normalize($r, ['itemtype'=>'User','items_id'=>5], false)['value'] === ['itemtype'=>'User','id'=>5], 'typed User не сравнивается с номером группы');
ok($normalize($r, ['itemtype'=>'Group','items_id'=>5])['status'] === 'unknown', 'другой itemtype при одинаковом ID неизвестен');
ok($normalize($r, [['itemtype'=>'User','items_id'=>5],['itemtype'=>'User','items_id'=>6]])['status'] === 'unknown', 'multiple actors не сворачиваются в первого');
ok(FormFacts::validateMapping($bool,$renamed) !== [], 'импорт запрещает изменённые labels');
$mapped = $bool; $mapped['id']=9; $mapped['uuid']='new-uuid';
ok(FormFacts::validateMapping($bool,$mapped) !== [], 'import UUID требует явного сопоставления');
ok(FormFacts::validateMapping($bool,$mapped,['question-a'=>'new-uuid']) === [], 'явное сопоставление совместимого вопроса разрешено');
$q = new \Glpi\Form\Question(); $q->fields = ['id'=>4,'uuid'=>'question-a','name'=>'Synthetic','type'=>'Glpi\\Form\\QuestionType\\QuestionTypeNumber'];
ok(FormFacts::describeQuestion($q)['type'] === 'number', 'метаданные используют настоящий native тип Number');
$q->fields['type']='UntrustedQuestion';
ok(!FormFacts::describeQuestion($q)['supported'], 'произвольный класс вопроса не создаётся');
$q->fields['type'] = 'Glpi\\Form\\QuestionType\\QuestionTypeRequester';
foreach ([['is_multiple_actors'=>false,'enabled_types'=>['User']], ['is_multiple_actors'=>true,'enabled_types'=>['User']],
    ['is_multiple_actors'=>false,'enabled_types'=>['User','Group']], ['is_multiple_actors'=>false,'enabled_types'=>null]] as $index=>$extra) {
    $q->fields['extra_data']=json_encode($extra);
    $d=FormFacts::describeQuestion($q);
    ok($index===0 ? $d['supported'] && $d['itemtype']==='User' : !$d['supported'], 'native actor descriptor различает scalar и mixed/multiple '.$index);
}
$q->fields['extra_data']='broken';
ok(!FormFacts::describeQuestion($q)['supported'],'повреждённая настройка actors не даёт ложный User');
$digest = hash('sha256', 'synthetic-native-answers');
$capture = ['form_id' => 9, 'metadata' => ['question-a' => $bool], 'visible' => ['question-a' => true], 'answers_digest' => $digest];
ok(FormFacts::submissionMatches($capture, 9, $digest), 'наблюдённая отправка с неизменными сырыми ответами подтверждается без списка плагинов');
ok(!FormFacts::submissionMatches(null, 9, $digest), 'отсутствие native capture не заменяется совпадением ответов');
ok(!FormFacts::submissionMatches($capture, 10, $digest), 'capture другой формы не подтверждает отправку');
ok(!FormFacts::submissionMatches($capture, 9, hash('sha256', 'changed')), 'изменение сырых ответов после capture запрещает исторические факты');
foreach (['answers_digest' => null, 'metadata' => null, 'visible' => null, 'form_id' => '9'] as $key => $value) {
    ok(!FormFacts::submissionMatches(array_replace($capture, [$key => $value]), 9, $digest), 'неполное доказательство не принимается: ' . $key);
}
// Тот же набор в другом порядке не меняет отпечаток; значения и типы сохраняют смысл.
$answerSet = new \Glpi\Form\AnswersSet();
$answer = static function (int $id, string $type, mixed $value): \Glpi\Form\Answer {
    $question = new \Glpi\Form\Question();
    $question->fields = ['id' => $id, 'name' => 'Synthetic question', 'type' => $type];
    return new \Glpi\Form\Answer($question, $value);
};
$fingerprint = (new ReflectionClass(FormFacts::class))->getMethod('answersDigest');
$syntheticAnswers = [$answer(1, 'Number', '1'), $answer(2, 'Checkbox', [])];
$answerSet->fields['answers'] = json_encode($syntheticAnswers, JSON_THROW_ON_ERROR);
$first = $fingerprint->invoke(null, $answerSet);
$syntheticAnswers = array_reverse($syntheticAnswers);
$answerSet->fields['answers'] = json_encode($syntheticAnswers, JSON_THROW_ON_ERROR);
ok($fingerprint->invoke(null, $answerSet) === $first, 'порядок native Answer строк не меняет свидетельство отправки');
$syntheticAnswers[1] = $answer(1, 'Number', 1);
$answerSet->fields['answers'] = json_encode($syntheticAnswers, JSON_THROW_ON_ERROR);
ok($fingerprint->invoke(null, $answerSet) !== $first, 'тип сырого ответа участвует в свидетельстве отправки');
try { \GlpiPlugin\Itilflow\TransactionScope::markLost('synthetic'); } catch (\GlpiPlugin\Itilflow\TransactionLost) {}
$fatal=false;
try { FormFacts::describeQuestion($q); } catch (\GlpiPlugin\Itilflow\TransactionLost) { $fatal=true; }
ok($fatal,'catch метаданных не превращает sticky transaction loss в UNKNOWN');
$fatal=false;
try { FormFacts::submissionMatches($capture, 9, $digest); } catch (\GlpiPlugin\Itilflow\TransactionLost) { $fatal=true; }
ok($fatal, 'даже совпавшее доказательство не разрешает продолжение после потери транзакции');
itog();
