<?php
/** Чистые границы принятия внешней ступени, без БД и полномочий сессии. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\OutcomeValidation;
$row = ['id' => 10, 'tickets_id' => 7, 'entities_id' => 0, 'itemtype_target' => 'User', 'items_id_target' => 8, 'status' => 2, 'itils_validationsteps_id' => 5];
$ctx = ['ticket_id' => 7, 'entity_id' => 0];
$plan = static fn(array $rows) => OutcomeValidation::adoptionPlan($rows, $ctx);
ok($plan([$row]) === ['ids' => [10], 'step_id' => 5, 'answered' => false], 'ожидающая внешняя ступень сохраняет native идентификатор');
ok($plan([array_replace($row, ['status' => 3])])['answered'], 'позднее согласие требует полномочия для отвеченного состава');
ok($plan([array_replace($row, ['status' => 4])])['answered'], 'поздний отказ не превращается в ожидающую визу');
foreach (['tickets_id' => 8, 'entities_id' => 1, 'itemtype_target' => 'Ticket', 'status' => 1, 'items_id_target' => 0, 'itils_validationsteps_id' => 0] as $field => $bad) {
    ok($plan([array_replace($row, [$field => $bad])]) === null, 'отказ при неверном поле ' . $field);
}
ok($plan([$row, $row]) === null, 'повтор ID не удваивает знаменатель');
ok($plan([$row, array_replace($row, ['id' => 11, 'itils_validationsteps_id' => 6])]) === null, 'разные native ступени не объединяются');
foreach ([true, 10.0, ' 10', '01', '999999999999999999999999'] as $bad) {
    ok($plan([array_replace($row, ['id' => $bad])]) === null, 'нецелочисленный или переполненный ID закрывает принятие');
}
ok($plan([array_map(static fn($v) => is_int($v) ? (string) $v : $v, $row)]) !== null, 'канонические строки БД допускаются');
$many = []; for ($i = 1; $i <= 51; $i++) { $many[] = array_replace($row, ['id' => $i]); }
ok($plan($many) === null && $plan(array_slice($many, 0, 50)) !== null, 'лимит полного состава проверяется до записи');
ok(OutcomeValidation::kindOf(['binding_kind' => 'alien']) === 'unknown', 'неизвестный вид привязки не становится созданным маршрутом');
ok(OutcomeValidation::kindOf(['binding_kind' => OutcomeValidation::KIND_MANUAL]) === OutcomeValidation::KIND_MANUAL, 'ручное принятие остаётся borrowed');
ok(OutcomeValidation::kindOf(['binding_kind' => null]) === 'unknown', 'явный NULL не становится legacy created');
itog();
