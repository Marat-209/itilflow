<?php

/** Чистые сценарии происхождения, без БД, хуков и подмены результата родной ступени. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\FormSource;

function sourceFacts(): array
{
    $targets = [['validatortype' => 'User', 'itemtype_target' => 'User', 'items_id_target' => ['21'], 'validationsteps_id' => '4'],
        ['validatortype' => 'Group', 'itemtype_target' => 'Group', 'items_id_target' => [30], 'validationsteps_id' => 4]];
    return ['evidence' => ['transaction_active' => true, 'input_complete' => true, 'current_read_complete' => true],
        'callback' => ['form_id' => 1, 'destination_id' => 10, 'answerset_id' => 100, 'ticket_id' => 200, 'entity_id' => 0,
            'answerset_form_id' => 1, 'destination_form_id' => 1],
        'source' => ['form_id' => 1, 'destination_id' => 10, 'validationsteps_id' => 4, 'approval_percent' => 50],
        'ticket' => ['id' => 200, 'entities_id' => 0],
        'created_items' => [['itemtype' => 'Ticket', 'id' => 200, 'entities_id' => 0]],
        'ticket_input' => [FormSource::INPUT_KEY => '100', '_add_validation' => 0, '_validation_targets' => $targets],
        'expected_targets' => $targets, 'initial_targets' => $targets,
        'step' => ['id' => 400, 'ticket_id' => 200, 'validationsteps_id' => 4, 'approval_percent' => 50],
        'validations' => [
            ['id' => 501, 'tickets_id' => 200, 'entities_id' => 0, 'itils_validationsteps_id' => 400,
                'itemtype_target' => 'User', 'items_id_target' => 21, 'status' => 2, 'itilvalidationtemplates_id' => 0],
            ['id' => 502, 'tickets_id' => 200, 'entities_id' => 0, 'itils_validationsteps_id' => 400,
                'itemtype_target' => 'Group', 'items_id_target' => 30, 'status' => 2, 'itilvalidationtemplates_id' => 0]],
        'bindings' => []];
}
function refused(array $facts, string $label): void
{
    $r = FormSource::assess($facts);
    ok(!$r['can_adopt'] && $r['receipt'] === null && $r['validation_ids'] === [], $label);
}
$f = sourceFacts();
$verified = FormSource::assess($f);
ok($verified['status'] === 'verified' && $verified['validation_ids'] === [501, 502], 'штатные native targets с validatortype подтверждены');
ok(!$verified['can_cancel'], 'даже доказанное происхождение не разрешает снятие без отдельной проверки');
ok(FormSource::replay($verified['receipt'], $f)['status'] === 'verified_replay', 'повтор тех же наблюдений даёт ту же квитанцию');
$changed = $f; $changed['validations'][0]['id'] = 503;
ok(!FormSource::replay($verified['receipt'], $changed)['can_adopt'], 'повтор не подменяет исходный набор виз новым с теми же адресатами');
$other = $f; $other['callback']['destination_id'] = 11; $other['source']['destination_id'] = 11;
$other['callback']['ticket_id'] = 201; $other['ticket']['id'] = 201; $other['created_items'][0]['id'] = 201;
$other['step']['ticket_id'] = 201; $other['step']['id'] = 401;
foreach ($other['validations'] as &$v) { $v['tickets_id'] = 201; $v['itils_validationsteps_id'] = 401; $v['id'] += 10; } unset($v);
$r = FormSource::assess($other);
ok($r['can_adopt'] && $r['receipt']['key'] !== $verified['receipt']['key'], 'две заявки одной отправки имеют отдельное точное происхождение');
$other['created_items'] = $f['created_items']; refused($other, 'заявка другого назначения не принимается по одному answerset');
foreach (['transaction_active', 'input_complete', 'current_read_complete'] as $flag) {
    $x = $f; unset($x['evidence'][$flag]); refused($x, 'неизвестное доказательство ' . $flag . ' закрывает принятие');
}
foreach ([null, '', false, 'requester_responsible', [0, '21'], ['group' => [30]], ['unknown' => 0]] as $input) {
    $x = $f; $x['ticket_input']['_add_validation'] = $input;
    refused($x, 'неизвестный или совпадающий вклад правил не равен отсутствию вклада: ' . json_encode($input));
}
$x = $f; $x['ticket_input']['_add_validation'] = [0, '0'];
ok(FormSource::assess($x)['can_adopt'], 'известные пустые placeholders ядра допустимы');
$x = $f; unset($x['ticket_input']['_add_validation']);
ok(FormSource::assess($x)['can_adopt'], 'отсутствие поля вклада правил отличается от явного неизвестного null');
$x = $f; $x['ticket_input']['_validationsteps_id'] = 4; ok(FormSource::assess($x)['can_adopt'], 'назначение шага без дополнительного адресата не создаёт неоднозначности');
$x = $f; $x['ticket_input']['_template_id'] = 7; refused($x, 'шаблон во входе закрывает автоматическое принятие');
$x = $f; $x['ticket_input']['_template_id'] = 0; ok(FormSource::assess($x)['can_adopt'], 'нулевой placeholder шаблона не считается дополнительным вкладом');
$x = $f; $x['expected_targets'][0] = ['_template_id' => 7]; refused($x, 'шаблон визы не выдаётся за адресата');
$x = $f; $x['expected_targets'][0]['validatortype'] = 'Group'; refused($x, 'противоречивый validatortype отклонён');
$x = $f; $x['expected_targets'][0]['items_id_target'] = [22]; refused($x, 'изменение назначения после создания обнаружено');
$x = $f; $x['validations'][] = $x['validations'][0]; $x['validations'][2]['id'] = 503;
refused($x, 'другая виза плагина на той же ступени обнаружена числом');
$x = $f; $x['validations'][1]['id'] = 501; refused($x, 'повтор ID строки недопустим');
$x = $f; $x['bindings'] = [['validation_id' => 501, 'binding_kind' => 'borrowed']]; refused($x, 'заимствованная привязка не перехватывается');
foreach ([true, 1.5, ' 1', '01', '1e2', '-1', '9999999999999999999999999', 0] as $id) {
    $x = $f; $x['callback']['ticket_id'] = $id; refused($x, 'строгий номер без приведения произвольного значения: ' . json_encode($id));
}
foreach (['tickets_id' => 201, 'entities_id' => 1, 'itils_validationsteps_id' => 401,
    'itilvalidationtemplates_id' => 3, 'status' => 3, 'items_id_target' => 99] as $field => $value) {
    $x = $f; $x['validations'][0][$field] = $value; refused($x, 'первичный callback проверяет ' . $field);
}
$x = $f; unset($x['validations'][0]['entities_id']); refused($x, 'отсутствующая сущность не приводится к корневой');
$x = $f; $x['expected_targets'][] = $x['expected_targets'][0]; refused($x, 'повтор ожидаемого адресата не скрывается дедупликацией');
$x = $f; $x['created_items'][] = $x['created_items'][0]; refused($x, 'повтор объекта callback недопустим');
$x = $f; $x['step']['approval_percent'] = 100; refused($x, 'порог ступени берётся из подтверждённого источника');

$receipt = $verified['receipt'];
$fresh = ['evidence' => ['transaction_active' => true, 'current_read_complete' => true, 'receipt_from_trusted_store' => true],
    'source' => $receipt['source'], 'step' => $f['step'], 'validations' => $f['validations'], 'bindings' => []];
foreach ([2, 3, 4] as $status) {
    $x = $fresh; $x['validations'][0]['status'] = $status;
    $r = FormSource::recheck($receipt, $x);
    ok($r['can_adopt'] && $r['receipt']['digest'] === $receipt['digest'] && $r['validation_statuses'][501] === $status,
        'поздний статус ' . $status . ' не меняет происхождение и передаётся native-интеграции');
}
$x = $fresh; $x['validations'][0]['status'] = 1;
ok(!FormSource::recheck($receipt, $x)['can_adopt'], 'неизвестный поздний статус не считается ответом');
$x = $fresh; $x['validations'][0]['id'] = 503;
ok(!FormSource::recheck($receipt, $x)['can_adopt'], 'поздняя замена визы с тем же адресатом обнаружена');
$x = $fresh; $x['validations'][0]['entities_id'] = 1;
ok(!FormSource::recheck($receipt, $x)['can_adopt'], 'поздняя смена сущности закрывает принятие');
$x = $fresh; $x['bindings'] = [['validation_id' => 501]];
ok(!FormSource::recheck($receipt, $x)['can_adopt'], 'поздняя чужая или уже существующая привязка не перехватывается');
$x = $fresh; unset($x['evidence']['receipt_from_trusted_store']);
ok(!FormSource::recheck($receipt, $x)['can_adopt'], 'клиентская квитанция с верным хешем не является источником');
$bad = $receipt; $bad['source']['destination_id'] = 999;
ok(!FormSource::recheck($bad, $fresh)['can_adopt'], 'квитанция другого назначения не подходит');
$bad = $receipt; $bad['digest'] = str_repeat('0', 64);
ok(!FormSource::recheck($bad, $fresh)['can_adopt'], 'повреждение квитанции обнаружено');
ok(!FormSource::recheck([], $fresh)['can_adopt'], 'пустая квитанция отклонена без исключения');
$reordered = array_reverse($receipt, true);
$reordered['source'] = array_reverse($reordered['source'], true);
foreach ($reordered['validations'] as &$record) { $record = array_reverse($record, true); } unset($record);
$x = $fresh; $x['source'] = array_reverse($x['source'], true);
ok(FormSource::recheck($reordered, $x)['can_adopt'], 'порядок ключей объектов после JSON хранения не меняет квитанцию');
ok(FormSource::replay($reordered, $f)['status'] === 'verified_replay', 'повтор допускает переставленные ключи JSON объектов');
$x['source']['ticket_id'] = '200';
ok(!FormSource::recheck($receipt, $x)['can_adopt'], 'канонизация не ослабляет типы доверенного контекста');
$multi = $f;
$extra = ['validatortype' => 'User', 'itemtype_target' => 'User', 'items_id_target' => [21], 'validationsteps_id' => 5];
$multi['initial_targets'][] = $extra;
$multi['expected_targets'][] = $extra;
$multi['ticket_input']['_validation_targets'][] = $extra;
ok(FormSource::assess($multi)['validation_ids'] === [501, 502], 'полное назначение с двумя ступенями допускает выбранную S');
$x = $multi; $x['expected_targets'][2]['items_id_target'] = [99];
refused($x, 'изменение даже другой ступени назначения обнаруживается до выделения S');
$x = $multi; $x['source']['validationsteps_id'] = 6; $x['step']['validationsteps_id'] = 6;
refused($x, 'выбранная ступень должна присутствовать в форме');
$x = $multi; array_shift($x['validations']);
refused($x, 'склейка одинакового адресата между ступенями не доказывает наличие визы на выбранной S');
$zero = sourceFacts(); $zero['source']['approval_percent'] = 0; $zero['step']['approval_percent'] = 0;
$zeroResult = FormSource::assess($zero);
ok($zeroResult['can_adopt'] && $zeroResult['receipt']['approval_percent'] === 0, 'native порог формы 0 не заменяется порогом этапа');
$zeroFresh = ['evidence' => ['transaction_active' => true, 'current_read_complete' => true, 'receipt_from_trusted_store' => true],
    'source' => $zeroResult['receipt']['source'], 'step' => $zero['step'], 'validations' => $zero['validations'], 'bindings' => []];
ok(FormSource::recheck($zeroResult['receipt'], $zeroFresh)['can_adopt'], 'поздняя проверка сохраняет native порог 0');
// Объяснимый вклад правила принимается целиком, но не становится разрешением удаления.
$rule = sourceFacts(); $rule['ticket_input']['_validationsteps_id'] = 4;
$rule['ticket_input']['_add_validation'] = [22];
$rule['validations'][] = array_replace($rule['validations'][0], ['id' => 503, 'items_id_target' => 22]);
$result = FormSource::assess($rule);
ok($result['can_adopt'] && $result['validation_ids'] === [501, 502, 503] && !$result['form_exclusive'], 'явный отдельный адресат правила сверяется вместе с формой; смешанный S не исключителен');
$rule['ticket_input']['_add_validation'] = [21]; array_pop($rule['validations']);
ok(FormSource::assess($rule)['reason'] === 'ambiguous_contribution', 'одинаковый адресат формы и правила не маскируется дедупликацией');
$rule['ticket_input']['_add_validation'] = ['group_any' => [31]];
$rule['validations'][] = array_replace($rule['validations'][1], ['id' => 503, 'items_id_target' => 31]);
ok(FormSource::assess($rule)['can_adopt'], 'штатный group_any даёт один объяснимый запрос группе');
$rule['ticket_input']['_add_validation'] = ['group' => [31]];
ok(FormSource::assess($rule)['reason'] === 'unknown_rule_contribution', 'динамический состав group не выводится из фактических виз задним числом');
$rule = sourceFacts(); $rule['ticket_input']['_validationsteps_id'] = 5; $rule['ticket_input']['_add_validation'] = [22];
ok(FormSource::assess($rule)['can_adopt'] && FormSource::assess($rule)['form_exclusive'], 'объяснимый вклад другого шага не делает выбранный S смешанным');
$template = sourceFacts();
$template['expected_targets'] = $template['initial_targets'] = $template['ticket_input']['_validation_targets'] = [['_template_id' => 7]];
$template['templates'] = [7 => ['id' => 7, 'validationsteps_id' => 4, 'targets' => [['itemtype' => 'User', 'items_id' => 21]]]];
$template['validations'] = [$template['validations'][0]];
ok(FormSource::assess($template)['can_adopt'], 'шаблон формы раскрывается по отдельным доверенным строкам, не по фактическим визам');
$x = $template; unset($x['templates']); refused($x, 'без прочитанного шаблона совпадающая виза не доказывает состав');
$x = $template; $x['templates'][7]['targets'][] = ['itemtype' => 'Group', 'items_id' => 30]; refused($x, 'смешанные типы повреждённого шаблона не нормализуются в ложный состав');
$x = $template; $x['templates'][7]['targets'][] = ['itemtype' => 'User', 'items_id' => 21]; refused($x, 'повтор адресата шаблона остаётся неоднозначностью');
$x = $template; $x['ticket_input']['_validation_targets'] = [['_template_id' => 8]]; refused($x, 'замена шаблона после capture отклонена');
$x = sourceFacts(); $x['validations'][1]['items_id_target'] = 31; refused($x, 'тот же размер набора с подменённым адресатом не проходит');
itog();
