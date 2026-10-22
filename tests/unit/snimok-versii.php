<?php

/**
 * 1.11.0, шаг 1: снимок определения по версиям схемы.
 *
 * - Схема 1 (запуски до 1.11) читается с умолчаниями: «формы нет», согласующие — прежние
 *   «группа / сотрудник» как список из одного, группа отвечает «любой участник», запуск первый.
 *   Номер схемы остаётся 1 — по нему видно, что запуск начат до 1.11.
 * - Схема 2 требует свои поля: недостающее — снимок недействителен, лишнее допускается.
 * - Списки согласующих записаны неверно — снимок недействителен (только у согласования).
 * - Будущая схема этой версией не читается.
 * Без базы: проверка снимка её не трогает.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Stage;

if (!estKlass(DefinitionSnapshot::class)) {
    itog();
}

function marshrut1(): array
{
    return ['id' => 5, 'name' => 'Маршрут', 'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1,
        'itemtype' => 'Ticket', 'enforcement' => 'audit', 'version' => 3, 'block_resolution' => 1, 'public_log' => 1,
        'helpdesk_tab' => 1, 'allow_withdraw' => 1, 'calendars_id' => 0, 'outcome_contract' => 'structured_v1'];
}

function etap1(int $id, int $rank, array $izm = []): array
{
    return array_replace(['id' => $id, 'plugin_itilflow_processes_id' => 5, 'name' => 'Этап ' . $rank, 'ranking' => $rank,
        'execution_mode' => Stage::MODE_INLINE, 'entity_strategy' => 'inherit', 'entities_id_target' => 0,
        'groups_id' => 7, 'users_id' => 0, 'itilcategories_id' => 0, 'slas_id' => 0, 'duration' => 0,
        'is_optional' => 0, 'completion_comment' => 'optional', 'deadline_minutes' => 0, 'approval_percent' => 100,
        'approver_source' => 'fixed', 'ticket_status' => 0, 'on_reject' => 'block', 'plugin_itilflow_stages_id_reject' => 0,
        'content' => '', 'department_result_source' => '', 'negative_policy' => 'block', 'return_targets' => '[]'], $izm);
}

function snimok1(): array
{
    return ['schema' => 1, 'process' => marshrut1(), 'stages' => [
        etap1(51, 10, ['execution_mode' => Stage::MODE_APPROVAL, 'groups_id' => 0, 'users_id' => 9]),
        etap1(52, 20, ['execution_mode' => Stage::MODE_APPROVAL, 'groups_id' => 7]),
        etap1(53, 30),
    ]];
}

function snimok2(): array
{
    $s = snimok1();
    $s['schema'] = 2;
    $s['run'] = 1;
    $s['process'] += ['forms_forms_id' => 0, 'forms_destinations_id' => 0];
    foreach ($s['stages'] as $i => $st) {
        $s['stages'][$i] = $st + ['form_validationsteps_id' => 0, 'approver_users' => null, 'approver_groups' => null,
            'approver_group_mode' => 'any'];
    }
    return $s;
}

// 1. Списки полей по версиям.
$p1 = DefinitionSnapshot::processFields(1);
$p2 = DefinitionSnapshot::processFields(2);
$e1 = DefinitionSnapshot::stageFields(1);
$e2 = DefinitionSnapshot::stageFields(2);
ok(DefinitionSnapshot::SCHEMA === 3, 'этот выпуск пишет схему 3');
ok(array_values(array_diff($p2, $p1)) === ['forms_forms_id', 'forms_destinations_id'] && array_diff($p1, $p2) === [],
    'схема 2 маршрута = схема 1 + раздел «Форма»');
ok(array_values(array_diff($e2, $e1)) === ['form_validationsteps_id', 'approver_users', 'approver_groups', 'approver_group_mode']
    && array_diff($e1, $e2) === [], 'схема 2 этапа = схема 1 + шаг формы, списки согласующих, ответ группы');
ok(DefinitionSnapshot::processFields(4) === [] && DefinitionSnapshot::stageFields(0) === [], 'неизвестной схемы полей нет');

// 2. Схема 1 — с умолчаниями, номер схемы прежний.
ok(DefinitionSnapshot::valid(snimok1()), 'снимок схемы 1 (запуск до 1.11) действителен');
$n = DefinitionSnapshot::normalize(snimok1());
ok($n['schema'] === 1 && $n['run'] === 1, 'схема 1 после чтения: номер схемы прежний, запуск первый');
ok($n['process']['forms_forms_id'] === 0 && $n['process']['forms_destinations_id'] === 0, 'схема 1: формы нет');
ok($n['stages'][0]['approver_users'] === null && $n['stages'][0]['approver_groups'] === null
    && $n['stages'][0]['approver_group_mode'] === 'any' && $n['stages'][0]['form_validationsteps_id'] === 0,
    'схема 1: списки не заполнены, «любой участник», шага формы нет');
$a = DefinitionSnapshot::approvers($n['stages'][0]);
ok($a === ['users' => [9], 'groups' => [], 'mode' => 'any', 'legacy' => true], 'прежний сотрудник — список из одного',
    json_encode($a));
$a = DefinitionSnapshot::approvers($n['stages'][1]);
ok($a === ['users' => [], 'groups' => [7], 'mode' => 'any', 'legacy' => true], 'прежняя группа — список из одного',
    json_encode($a));
ok(DefinitionSnapshot::valid(DefinitionSnapshot::normalize(snimok1())), 'прочитанный снимок схемы 1 действителен');
$n2 = DefinitionSnapshot::normalize(snimok2());
ok($n2['schema'] === 2 && $n2['stages'][0]['run_if'] === null && $n2['stages'][0]['decision_items'] === null,
    'снимок схемы 2 сохраняет версию и линейность');

// 3. Схема 2 требует свои поля; лишнее допускается.
ok(DefinitionSnapshot::valid(snimok2()), 'снимок схемы 2 действителен');
$s = snimok2();
unset($s['process']['forms_destinations_id']);
ok(!DefinitionSnapshot::valid($s), 'схема 2 без поля маршрута «forms_destinations_id» — недействительна');
$s = snimok2();
unset($s['stages'][2]['approver_group_mode']);
ok(!DefinitionSnapshot::valid($s), 'схема 2 без поля этапа «approver_group_mode» — недействительна');
$s = snimok2();
unset($s['run']);
ok(!DefinitionSnapshot::valid($s), 'схема 2 без номера запуска — недействительна');
$s = snimok2();
$s['run'] = 0;
ok(!DefinitionSnapshot::valid($s), 'номер запуска 0 — недействителен');
$s = snimok2();
$s['process']['lishnee'] = 1;
$s['stages'][0]['lishnee'] = 'x';
ok(DefinitionSnapshot::valid($s), 'лишнее поле маршрута и этапа допускается');
$s = snimok1();
$s['stages'][0]['approver_users'] = 'мусор';
ok(DefinitionSnapshot::valid($s), 'у схемы 1 поля схемы 2 не читаются: лишнее');
$s = snimok1();
unset($s['stages'][0]['return_targets']);
ok(!DefinitionSnapshot::valid($s), 'схема 1 без своего поля — недействительна, как раньше');
$s = snimok2();
$s['schema'] = 4;
ok(!DefinitionSnapshot::valid($s), 'схема новее этого выпуска не читается');
$s = snimok2();
$s['schema'] = '2';
ok(!DefinitionSnapshot::valid($s), 'номер схемы строкой — недействителен');

// 4. Значения новых полей.
$plohie = [
    'список сотрудников — не JSON' => [0, 'approver_users', 'мусор'],
    'список групп с нулём' => [0, 'approver_groups', '[0]'],
    'список с повтором' => [0, 'approver_users', '[9,9]'],
    'список — объект' => [0, 'approver_users', '{"a":9}'],
    'список номеров строками' => [1, 'approver_groups', '["7"]'],
    'неизвестный ответ группы' => [1, 'approver_group_mode', 'all'],
    'шаг формы отрицательный' => [2, 'form_validationsteps_id', -1],
];
foreach ($plohie as $chto => [$i, $pole, $znach]) {
    $s = snimok2();
    $s['stages'][$i][$pole] = $znach;
    ok(!DefinitionSnapshot::valid($s), 'недействителен: ' . $chto);
}
$s = snimok2();
$s['process']['forms_forms_id'] = -3;
ok(!DefinitionSnapshot::valid($s), 'недействителен: форма маршрута отрицательная');
$s = snimok2();
$s['stages'][2]['approver_users'] = 'мусор';
ok(DefinitionSnapshot::valid($s), 'у задачи списки согласующих не читаются — снимок действителен');

$s = snimok2();
$s['stages'][0]['approver_users'] = '[9,11]';
$s['stages'][0]['approver_groups'] = '[]';
$s['stages'][0]['approver_group_mode'] = 'each';
ok(DefinitionSnapshot::valid($s), 'заполненные списки и «каждый участник» — действительны');
$a = DefinitionSnapshot::approvers($s['stages'][0]);
ok($a === ['users' => [9, 11], 'groups' => [], 'mode' => 'each', 'legacy' => false],
    'заполненные списки читаются как есть, прежние поля не подмешиваются', json_encode($a));
$a = DefinitionSnapshot::approvers(['groups_id' => 7, 'users_id' => 9, 'approver_users' => null, 'approver_groups' => '[3]']);
ok($a === ['users' => [], 'groups' => [3], 'mode' => 'any', 'legacy' => false], 'заполнен один список — второй пуст',
    json_encode($a));
ok(DefinitionSnapshot::approvers(['groups_id' => '0', 'users_id' => '0']) === ['users' => [], 'groups' => [], 'mode' => 'any', 'legacy' => true],
    'строка без согласующих — пустые списки');
ok(DefinitionSnapshot::idList(null) === null && DefinitionSnapshot::idList('[]') === []
    && DefinitionSnapshot::idList('[1,2]') === [1, 2] && DefinitionSnapshot::idList('x') === false,
    'поле списка: не заполнено / пусто / номера / неверно');

// 5. Черновик для строки готовности — текущей схемы.
$d = DefinitionSnapshot::draft(snimok2()['process'], snimok2()['stages']);
ok($d['schema'] === 3 && $d['run'] === 1 && DefinitionSnapshot::valid($d), 'черновик определения — схема 3, проверяется так же');
ok(!DefinitionSnapshot::valid(DefinitionSnapshot::draft(snimok1()['process'], snimok1()['stages'])),
    'строки без полей 1.11 (база не обновлена) — черновик недействителен, запуск не начнётся');

itog();
