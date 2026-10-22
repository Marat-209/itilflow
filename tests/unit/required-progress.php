<?php
/** Требуемость, завершённость и история — независимые показатели без БД. */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Sheet;
use GlpiPlugin\Itilflow\Step;

$done = ['state' => Step::DONE];
$notNeeded = ['state' => Step::NOT_NEEDED];
$pending = ['state' => Step::PENDING];
$p = Sheet::requiredProgress([$done, $notNeeded]);
ok($p['total'] === 2 && $p['completed'] === 2 && $p['required'] === 1
    && $p['not_needed'] === 1 && $p['undetermined'] === 0,
    'выполнен + не потребовался: завершены оба, потребовался один');

$p = Sheet::requiredProgress([$done, $notNeeded, $pending + ['conditional' => true]]);
ok($p['completed'] === 2 && $p['total'] === 3 && $p['required'] === 1 && $p['undetermined'] === 1,
    'этап в очереди с условием не становится требуемостью или ложной полной завершённостью');
$p = Sheet::requiredProgress([$pending + ['conditional' => true, 'condition_note' => '{"result":"unknown"}']]);
ok($p['required'] === 0 && $p['completed'] === 0 && $p['undetermined'] === 1,
    'неизвестное условие ожидающего этапа не засчитывается как выполненное');

// 1.13.1: этап в очереди без условия «Когда выполнять» точно понадобится —
// он «нужен», а не «ещё не ясно»; завершённым он от этого не становится.
$p = Sheet::requiredProgress([$done, $notNeeded, $pending]);
ok($p['required'] === 2 && $p['undetermined'] === 0 && $p['completed'] === 2 && $p['total'] === 3,
    'безусловный этап в очереди — нужен, не «ещё не ясно»', json_encode($p));
$p = Sheet::requiredProgress(Sheet::markConditional([
    $done + ['plugin_itilflow_stages_id' => 51], ['state' => Step::RUNNING, 'plugin_itilflow_stages_id' => 52],
    $pending + ['plugin_itilflow_stages_id' => 53], $pending + ['plugin_itilflow_stages_id' => 54],
    $pending + ['plugin_itilflow_stages_id' => 55]], [54]));
ok($p['required'] === 4 && $p['undetermined'] === 1 && $p['total'] === 5,
    'пять этапов, у одного будущего условие: нужно пройти 4, зависит от условия 1', json_encode($p));
ok(Sheet::requiredProgress(Sheet::markConditional([$pending + ['plugin_itilflow_stages_id' => 53]], []))['required'] === 1,
    'запуск без условий этапов (прежний порядок, снимок до схемы 3): будущие этапы нужны');

// Какие этапы с условием — из снимка запуска, как Ui::stageRule.
$proc = ['id' => 5, 'name' => 'Учебный маршрут', 'version' => 2, 'itemtype' => 'Ticket',
    'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'enforcement' => 'audit',
    'block_resolution' => 1, 'public_log' => 1, 'helpdesk_tab' => 1, 'allow_withdraw' => 1,
    'calendars_id' => 0, 'outcome_contract' => DefinitionSnapshot::MODE, 'forms_forms_id' => 0, 'forms_destinations_id' => 0];
$etap = static fn(int $id, int $rank, $runIf, $items = null): array => ['id' => $id, 'plugin_itilflow_processes_id' => 5,
    'ranking' => $rank, 'name' => 'Этап ' . $rank, 'execution_mode' => 'inline', 'entity_strategy' => 'inherit', 'entities_id_target' => 0,
    'groups_id' => 7, 'users_id' => 0, 'itilcategories_id' => 0, 'slas_id' => 0, 'duration' => 0, 'is_optional' => 0,
    'completion_comment' => 'optional', 'deadline_minutes' => 0, 'approval_percent' => 100, 'approver_source' => 'fixed',
    'ticket_status' => 0, 'on_reject' => 'block', 'plugin_itilflow_stages_id_reject' => 0, 'content' => '',
    'department_result_source' => '', 'negative_policy' => 'block', 'return_targets' => '[]', 'form_validationsteps_id' => 0,
    'approver_users' => null, 'approver_groups' => null, 'approver_group_mode' => 'any', 'run_if' => $runIf,
    'unknown_policy' => 'run', 'decision_items' => $items];
$uslovie = ['version' => 1, 'join' => 'all', 'clauses' => [['source' => 'decision', 'stage_id' => 51, 'key' => 'dop', 'operator' => 'eq', 'value' => true]]];
$snimok = DefinitionSnapshot::draft($proc, [$etap(51, 10, null, [['key' => 'dop', 'label' => 'Нужна проверка']]),
    $etap(52, 20, $uslovie), $etap(53, 30, null)]);
ok(DefinitionSnapshot::valid($snimok), 'синтетический снимок с условием проходит настоящий валидатор');
$syroj = DefinitionSnapshot::encode($snimok);
$zapusk = new Instance();
$zapusk->fields = ['outcome_contract' => DefinitionSnapshot::MODE, 'definition_snapshot' => $syroj,
    'definition_sha256' => hash('sha256', $syroj), 'plugin_itilflow_processes_id' => 5, 'process_version' => 2, 'itemtype' => 'Ticket'];
ok(Sheet::conditionalStages($zapusk) === [52], 'с условием — только этап 20; этапы 10 и 30 — без условия',
    json_encode(Sheet::conditionalStages($zapusk)));
$zapusk->fields['definition_sha256'] = str_repeat('0', 64);
ok(Sheet::conditionalStages($zapusk) === [], 'снимок не читается — условий нет, этапы не выдаются за условные');

$p = Sheet::requiredProgress([
    ['state' => Step::DONE, 'superseded_by_attempt_id' => 2],
    ['state' => Step::SUPERSEDED, 'date_start' => '2026-01-01 10:00:00', 'superseded_by_attempt_id' => 2],
    $done, $notNeeded,
]);
ok($p['history'] === 1 && $p['total'] === 2 && $p['required'] === 1 && $p['completed'] === 2,
    'заменённый положительный итог и заменённый прекращённый проход — одна прежняя попытка, отдельно от итога');
// «Прежних попыток» — число попыток, а не строк этапов (три строки одной попытки — не «3»).
$odnaPopytka = [['state' => Step::DONE, 'superseded_by_attempt_id' => 7], ['state' => Step::SUPERSEDED, 'superseded_by_attempt_id' => 7],
    ['state' => Step::SUPERSEDED, 'superseded_by_attempt_id' => 7], $done, ['state' => Step::RUNNING]];
ok(Sheet::requiredProgress($odnaPopytka)['history'] === 1, 'один возврат на уточнение — «Прежних попыток: 1», а не по числу строк');
ok(Sheet::requiredProgress(array_merge($odnaPopytka, [['state' => Step::REJECTED, 'superseded_by_attempt_id' => 9]]))['history'] === 2,
    'два возврата — две прежние попытки');
$p = Sheet::requiredProgress([$done,
    ['state' => Step::SUPERSEDED, 'date_start' => '2026-01-01 10:00:00']]);
ok($p['total'] === 2 && $p['completed'] === 1 && $p['required'] === 2
    && $p['interrupted'] === 1 && $p['history'] === 0,
    'прерывание начатого этапа после выполненного не создаёт ложные 1 из 1');
$p = Sheet::requiredProgress([$done, ['state' => Step::SUPERSEDED]]);
ok($p['total'] === 2 && $p['completed'] === 1 && $p['required'] === 1 && $p['not_run'] === 1 && $p['history'] === 0,
    'прекращённая очередь остаётся в знаменателе, но не становится потребовавшимся этапом');
// Брошенный до начала этап — только «не выполнялся», не «ещё не ясно» и не «не закончен».
ok($p['undetermined'] === 0 && $p['interrupted'] === 0,
    'этап, брошенный до начала, не считается дважды', json_encode($p));
ok($p['required'] + $p['not_needed'] + $p['undetermined'] + $p['skipped_before_start'] + $p['not_run'] === $p['total'],
    'прерывание — дополнительная отметка, категории требуемости не пересекаются');
// Пример: отказ на втором этапе, три этапа после него так и не начались.
$p = Sheet::requiredProgress([$done, ['state' => Step::REJECTED, 'date_start' => '2026-01-01 10:00:00'],
    ['state' => Step::SUPERSEDED], ['state' => Step::SUPERSEDED], ['state' => Step::SUPERSEDED]]);
$sum = $p['required'] + $p['not_needed'] + $p['undetermined'] + $p['skipped_before_start'] + $p['not_run'];
ok($p['not_run'] === 3 && $p['undetermined'] === 0 && $p['interrupted'] === 0 && $sum === $p['total'] && $p['total'] === 5,
    'после отказа «не выполнялись: 3», без «ещё не ясно: 3» и «не закончены: 3»', json_encode($p));
$p = Sheet::requiredProgress([$done, ['state' => Step::SUPERSEDED, 'date_start' => '2026-01-01 10:00:00'], ['state' => Step::SUPERSEDED]]);
ok($p['interrupted'] === 1 && $p['not_run'] === 1 && $p['required'] === 2,
    'прерванный идущий этап — «не закончен», следующий за ним — «не выполнялся»', json_encode($p));
$p = Sheet::requiredProgress([$done, ['state' => Step::RUNNING]]);
ok($p['total'] === 2 && $p['required'] === 2 && $p['completed'] === 1,
    'сохранённый завершённый префикс учитывается вместе с текущим проходом');

$p = Sheet::requiredProgress([
    ['state' => Step::SKIPPED],
    ['state' => Step::SKIPPED, 'date_start' => '2026-01-01 10:00:00'],
]);
ok($p['completed'] === 2 && $p['required'] === 1 && $p['skipped_before_start'] === 1,
    'пропуск до начала завершает проход, но не доказывает требуемость');
$p = Sheet::requiredProgress([['state' => Step::REJECTED]]);
ok($p['required'] === 1 && $p['completed'] === 0,
    'отказ потребовал этапа, но не выдаётся за положительное завершение маршрута');
$p = Sheet::requiredProgress([['state' => 'unrecognized']]);
ok($p['undetermined'] === 1 && $p['completed'] === 0 && $p['required'] === 0,
    'неизвестное состояние не выводится как полное прохождение');
$p = Sheet::requiredProgress([]);
ok($p['total'] === 0 && $p['completed'] === 0 && $p['required'] === 0,
    'пустая выборка не создаёт фиктивных проходов');
itog();
