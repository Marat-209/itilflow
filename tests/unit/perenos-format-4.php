<?php

/**
 * Перенос маршрутов (экспорт/импорт) знает «Запускать, если»: формат файла версии 4,
 * у маршрута поле start_if — объект StartRule или null. Файлы версий 1–3 читаются как раньше
 * (проверяет perenos-fajl.php). Без базы.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Transfer;
use GlpiPlugin\Itilflow\TransferError;
use GlpiPlugin\Itilflow\TransferMapper;

if (!estKlass(Transfer::class)) {
    itog();
}

function fajl113(mixed $start): array
{
    return [
        'format' => 'itilflow-routes', 'version' => 4, 'plugin_version' => '1.13.0',
        'glpi_version' => '11.0.8', 'date' => '2026-10-21T18:00:00+03:00',
        'routes' => [[
            'id' => 5, 'name' => 'Запуск по ответу формы', 'comment' => null, 'itemtype' => 'Ticket',
            'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'enforcement' => 'audit',
            'block_resolution' => 1, 'public_log' => 1, 'helpdesk_tab' => 1, 'allow_withdraw' => 1,
            'calendars_id' => 0, 'outcome_contract' => 'structured_v1', 'forms_forms_id' => 0, 'forms_destinations_id' => 0,
            'start_if' => $start,
            'stages' => [[
                'id' => 51, 'name' => 'Этап 10', 'ranking' => 10, 'execution_mode' => 'inline',
                'entity_strategy' => 'inherit', 'entities_id_target' => 0, 'groups_id' => 7, 'users_id' => 0,
                'itilcategories_id' => 0, 'slas_id' => 0, 'duration' => 0, 'is_optional' => 0,
                'completion_comment' => 'optional', 'deadline_minutes' => 0, 'approval_percent' => 100,
                'approver_source' => 'fixed', 'ticket_status' => 0, 'on_reject' => 'block',
                'plugin_itilflow_stages_id_reject' => 0, 'content' => '<p>Сделать</p>',
                'department_result_source' => '', 'negative_policy' => 'block', 'return_targets' => [],
                'form_validationsteps_id' => 0, 'approver_users' => null, 'approver_groups' => null,
                'approver_group_mode' => 'any', 'run_if' => null, 'decision_items' => null, 'unknown_policy' => 'run',
            ]],
            'data_requirements' => [
                ['itemtype' => 'Entity', 'id' => 0, 'name' => 'Корень', 'completename' => 'Корень'],
                ['itemtype' => 'Group', 'id' => 7, 'name' => 'Линия 1', 'completename' => 'ИТ > Линия 1'],
            ],
        ]],
    ];
}

function prichina113(array $f): array
{
    try {
        return ['', Transfer::parse(json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
    } catch (TransferError $e) {
        return [$e->getMessage(), null];
    } catch (\Throwable $e) {
        return ['ОШИБКА PHP: ' . get_class($e) . ': ' . $e->getMessage(), null];
    }
}

[$oshibka, $f] = prichina113(fajl113(['version' => 1, 'mode' => 'always']));
ok($oshibka === '', 'файл версии 4 с «Запускать, если» читается', $oshibka);
ok(($f['routes'][0]['start_if']['mode'] ?? null) === 'always', '«Запускать, если» маршрута переносится', json_encode($f['routes'][0]['start_if'] ?? null));

[$oshibka, $f] = prichina113(fajl113(null));
ok($oshibka === '' && array_key_exists('start_if', $f['routes'][0] ?? []) && $f['routes'][0]['start_if'] === null,
    'без запуска по форме — null, как в 1.12', $oshibka);

[$oshibka] = prichina113(fajl113(['version' => 1, 'mode' => 'conditions', 'rule' => ['version' => 1, 'join' => 'all',
    'clauses' => [['source' => 'ticket', 'field' => 'category_id', 'operator' => 'in', 'value' => [42]]]]]));
ok($oshibka !== '', 'в «Запускать, если» поле заявки не переносится — причина словами', $oshibka);
ok(str_contains($oshibka, 'доступны только ответы выбранной формы'), 'причина отказа называет, что можно: только ответы формы', $oshibka);

// Условия запуска должны самостоятельно потребовать вопрос, даже без условий этапов.
$q = ['id' => 301, 'uuid' => 'question-301', 'label' => 'Вид обращения',
    'native_type' => 'Glpi\\Form\\QuestionType\\QuestionTypeDropdown', 'type' => 'choice',
    'multiple' => false, 'options' => [['key' => 'a', 'label' => 'Вариант А'], ['key' => 'b', 'label' => 'Вариант Б']],
    'itemtype' => null, 'supported' => true, 'reason' => null];
$start = ['version' => 1, 'mode' => 'conditions', 'rule' => ['version' => 1, 'join' => 'all',
    'clauses' => [['source' => 'form_answer', 'question_uuid' => $q['uuid'], 'question' => $q, 'operator' => 'in', 'value' => ['a']]]]];
$valid = fajl113($start);
$valid['routes'][0]['forms_forms_id'] = 201;
$valid['routes'][0]['data_requirements'][] = ['itemtype' => \GlpiPlugin\Itilflow\TransferMapper::FORM,
    'id' => 201, 'name' => 'Форма', 'uuid' => 'form-201'];
$valid['routes'][0]['data_requirements'][] = ['itemtype' => \GlpiPlugin\Itilflow\TransferMapper::QUESTION,
    'id' => 301, 'name' => $q['label'], 'uuid' => $q['uuid'], 'forms_forms_id' => 201, 'form_uuid' => 'form-201', 'descriptor' => $q];
[$error, $parsed] = prichina113($valid);
$key = \GlpiPlugin\Itilflow\TransferMapper::key(\GlpiPlugin\Itilflow\TransferMapper::QUESTION, 301);
ok($error === '' && ($parsed['routes'][0]['forms_destinations_id'] ?? -1) === 0
    && ($parsed['routes'][0]['problems'] ?? ['missing']) === [], 'условие запуска и любое назначение читаются без этапного run_if', $error);
ok(isset($parsed['routes'][0]['data_requirements'][$key])
    && in_array('Запускать, если', $parsed['routes'][0]['uses'][$key] ?? [], true), 'вопрос запуска остаётся требованием с человеческим местом использования');
ok(str_contains($parsed['routes'][0]['start_summary'] ?? '', 'Любое назначение'), 'предпросмотр поясняет любое назначение формы');
ok(str_starts_with($parsed['routes'][0]['start_summary'] ?? '', 'если «Вид обращения» — Вариант А'),
    'в предпросмотре условие запуска — словами, с названиями из файла', $parsed['routes'][0]['start_summary'] ?? '');
[, $bezZapuska] = prichina113(fajl113(null));
ok(($bezZapuska['routes'][0]['start_summary'] ?? '') === 'не запускается по форме', 'маршрут без запуска по форме — как в блоке «Запуск маршрута»',
    $bezZapuska['routes'][0]['start_summary'] ?? '');
$bad = $valid; array_pop($bad['routes'][0]['data_requirements']);
[$error] = prichina113($bad);
ok($error !== '', 'без сведений о вопросе запуска файл отвергается');
$bad = $valid; $bad['routes'][0]['data_requirements'][3]['form_uuid'] = 'other-form';
[$error] = prichina113($bad);
ok($error !== '', 'вопрос другой формы не становится условием запуска');
$bad = $valid; $bad['routes'][0]['data_requirements'][3]['descriptor']['options'][0]['key'] = 'changed';
[$error] = prichina113($bad);
ok($error !== '', 'несовместимые варианты вопроса отвергаются');
$renamed = $valid; $renamed['routes'][0]['data_requirements'][3]['descriptor']['label'] = 'Новое название';
[$error] = prichina113($renamed);
ok($error === '', 'переименование вопроса не ломает совместимость описаний', $error);
foreach ([[], ['version' => 1, 'mode' => 'conditions', 'rule' => ['version' => 1, 'join' => 'all', 'clauses' => []]],
    ['version' => 1, 'mode' => 'always', 'extra' => true], 'always', false] as $broken) {
    [$error] = prichina113(fajl113($broken));
    ok($error !== '' && !str_starts_with($error, 'ОШИБКА PHP'), 'повреждённый запуск отвергается понятной причиной', $error);
}
$old = fajl113(null); $old['version'] = 3; unset($old['routes'][0]['start_if']);
[$error, $parsed] = prichina113($old);
ok($error === '' && array_key_exists('start_if', $parsed['routes'][0] ?? []) && $parsed['routes'][0]['start_if'] === null,
    'версия3 читается с выключенным запуском по форме', $error);
$old['routes'][0]['start_if'] = ['version' => 1, 'mode' => 'always'];
[$error] = prichina113($old);
ok($error !== '', 'версия3 не разрешает спрятать новый запуск в старом формате');
$bad = $valid; $bad['routes'][0]['start_if']['rule']['clauses'][0]['question_uuid'] = 'other-question';
[$error] = prichina113($bad);
ok($error !== '', 'несовпадение идентификатора вопроса и его описания отвергается');

// Виды вопросов и сравнения 1.13 в «Запускать, если»: «любой из» списком, сравнения без значения,
// краткий ответ, файл, дата. Описание вопроса — как его сохраняет редактор (с required и conditional).
function vopros113(int $id, string $type, ?string $itemtype, string $native): array
{
    return ['id' => $id, 'uuid' => 'question-' . $id, 'label' => 'Вопрос ' . $id,
        'native_type' => 'Glpi\\Form\\QuestionType\\' . $native, 'type' => $type, 'multiple' => false,
        'options' => [], 'itemtype' => $itemtype, 'supported' => true, 'reason' => null,
        'required' => false, 'conditional' => false];
}

function uslovie113(array $q, string $operator, mixed $value): array
{
    return ['source' => 'form_answer', 'question_uuid' => $q['uuid'], 'question' => $q, 'operator' => $operator, 'value' => $value];
}

/** Файл версии 4: маршрут формы 201 с «Запускать, если» из $clauses; сведения о вопросах и объектах. */
function zapusk113(array $clauses, array $questions, array $objects = []): array
{
    $f = fajl113(['version' => 1, 'mode' => 'conditions', 'rule' => ['version' => 1, 'join' => 'any', 'clauses' => $clauses]]);
    $f['routes'][0]['forms_forms_id'] = 201;
    $f['routes'][0]['data_requirements'][] = ['itemtype' => TransferMapper::FORM, 'id' => 201, 'name' => 'Форма', 'uuid' => 'form-201'];
    foreach ($questions as $q) {
        $f['routes'][0]['data_requirements'][] = ['itemtype' => TransferMapper::QUESTION, 'id' => $q['id'], 'name' => $q['label'],
            'uuid' => $q['uuid'], 'forms_forms_id' => 201, 'form_uuid' => 'form-201', 'descriptor' => $q];
    }
    foreach ($objects as $o) { $f['routes'][0]['data_requirements'][] = $o; }
    return $f;
}

$kto = vopros113(311, 'ref', 'User', 'QuestionTypeRequester');
$gruppa = vopros113(312, 'ref', 'Group', 'QuestionTypeAssignee');
$kategoriya = vopros113(313, 'ref', 'ITILCategory', 'QuestionTypeItemDropdown');
$tekst = vopros113(314, 'text', null, 'QuestionTypeLongText');
$fajl = vopros113(315, 'file', null, 'QuestionTypeFile');
$data = vopros113(316, 'date', null, 'QuestionTypeDateTime');
$lyudi = [['itemtype' => 'User', 'id' => 9, 'name' => 'ivanov'], ['itemtype' => 'User', 'id' => 12, 'name' => 'petrov'],
    ['itemtype' => 'ITILCategory', 'id' => 5, 'name' => 'Доступ', 'completename' => 'Сервисы > Доступ']];
$vse = [$kto, $gruppa, $kategoriya, $tekst, $fajl, $data];
$f = zapusk113([
    uslovie113($kto, 'in', [['itemtype' => 'User', 'id' => 9], ['itemtype' => 'User', 'id' => 12]]),
    uslovie113($gruppa, 'filled', null),
    uslovie113($kategoriya, 'in', [['itemtype' => 'ITILCategory', 'id' => 5]]),
], $vse, $lyudi);
[$error, $parsed] = prichina113($f);
$r = $parsed['routes'][0] ?? [];
ok($error === '' && ($r['problems'] ?? ['нет']) === [], '«любой из» списком и «заполнено» у пользователя, группы и категории читаются', $error);
ok(($r['uses']['User#12'] ?? []) === ['Запускать, если'] && ($r['uses']['ITILCategory#5'] ?? []) === ['Запускать, если'],
    'каждый объект списка «любой из» — ссылка «Запускать, если» со сведениями для поиска',
    json_encode($r['uses'] ?? null, JSON_UNESCAPED_UNICODE));
ok(array_values(array_filter(array_keys($r['uses'] ?? []), static fn(string $k): bool => str_starts_with($k, 'Group#'))) === ['Group#7']
    && ($r['uses']['Group#7'] ?? []) === ['этап 10 «Этап 10» — группа'], 'сравнение без значения ссылок не порождает',
    json_encode($r['uses'] ?? null, JSON_UNESCAPED_UNICODE));

$f = zapusk113([
    uslovie113($tekst, 'filled', null), uslovie113($fajl, 'empty', null),
    uslovie113($data, 'before_days', 3),
], $vse);
[$error, $parsed] = prichina113($f);
ok($error === '' && ($parsed['routes'][0]['problems'] ?? ['нет']) === [], 'краткий и долгий ответ, файл, дата «раньше, чем через N дней» читаются', $error);
$f = zapusk113([uslovie113($data, 'unknown', null), uslovie113($data, 'after_days', 30)], $vse);
[$error] = prichina113($f);
ok($error === '', 'дата: «неизвестно» и «позже, чем через N дней»', $error);

foreach ([
    'без сведений об одном из списка' => [zapusk113([uslovie113($kto, 'in', [['itemtype' => 'User', 'id' => 9], ['itemtype' => 'User', 'id' => 12]])],
        $vse, [$lyudi[0]]), 'User#12'],
    'группа в списке пользователей' => [zapusk113([uslovie113($kto, 'in', [['itemtype' => 'Group', 'id' => 7]])], $vse), ''],
    'повтор в списке' => [zapusk113([uslovie113($kto, 'in', [['itemtype' => 'User', 'id' => 9], ['itemtype' => 'User', 'id' => 9]])], $vse, $lyudi), ''],
    'пустой список' => [zapusk113([uslovie113($kto, 'in', [])], $vse), ''],
    '«заполнено» со значением' => [zapusk113([uslovie113($gruppa, 'filled', ['itemtype' => 'Group', 'id' => 7])], $vse), ''],
    'текст «равно»' => [zapusk113([uslovie113($tekst, 'eq', true)], $vse), ''],
    'через 0 дней' => [zapusk113([uslovie113($data, 'before_days', 0)], $vse), ''],
    'дни строкой' => [zapusk113([uslovie113($data, 'after_days', '3')], $vse), ''],
    'файл с текстом ответа' => [zapusk113([uslovie113($fajl, 'filled', 'report.pdf')], $vse), ''],
] as $chto => [$plohoj, $zhdem]) {
    [$error] = prichina113($plohoj);
    ok($error !== '' && !str_starts_with($error, 'ОШИБКА PHP') && ($zhdem === '' || str_contains($error, $zhdem)),
        'запуск отвергается понятной причиной: ' . $chto, $error === '' ? 'принят' : $error);
}

// Номера этой базы у значений условий: списки «любой из» переназначаются целиком, без повторов.
$znachenie = new ReflectionMethod(Transfer::class, 'mapValue');
$karta = ['User#9' => 90, 'User#12' => 90, 'Group#7' => 70, 'ITILCategory#5' => 50, 'ITILCategory#6' => 50];
$ref = static fn(string $type, int $id): int => $karta[TransferMapper::key($type, $id)] ?? 0;
ok($znachenie->invoke(null, uslovie113($kto, 'in', [['itemtype' => 'User', 'id' => 9], ['itemtype' => 'User', 'id' => 12]]), $ref)
    === [['itemtype' => 'User', 'id' => 90]], 'список пользователей — номера этой базы; два исходных в один — один');
ok($znachenie->invoke(null, uslovie113($gruppa, 'eq', ['itemtype' => 'Group', 'id' => 7]), $ref) === ['itemtype' => 'Group', 'id' => 70],
    'группа одним значением — номер этой базы');
ok($znachenie->invoke(null, uslovie113($kategoriya, 'in', [['itemtype' => 'ITILCategory', 'id' => 5], ['itemtype' => 'ITILCategory', 'id' => 6]]), $ref)
    === [['itemtype' => 'ITILCategory', 'id' => 50]], 'список категорий вопроса формы — номера этой базы');
ok($znachenie->invoke(null, ['source' => 'ticket', 'field' => 'category_id', 'operator' => 'not_in', 'value' => [5, 6]], $ref) === [50],
    'категории заявки «ни одна из» — номера этой базы без повторов');
ok($znachenie->invoke(null, uslovie113($gruppa, 'filled', null), $ref) === null
    && $znachenie->invoke(null, uslovie113($data, 'before_days', 3), $ref) === 3,
    'сравнение без значения и число дней не меняются');
try {
    $znachenie->invoke(null, uslovie113($kto, 'in', [['itemtype' => 'User', 'id' => 9], ['itemtype' => 'User', 'id' => 13]]), $ref);
    ok(false, 'несопоставленный объект списка не выпадает молча');
} catch (TransferError $e) {
    ok(str_contains($e->getMessage(), 'не сопоставлен'), 'несопоставленный объект списка не выпадает молча', $e->getMessage());
}

// Экспорт: повреждённая настройка запуска — отказ с названием маршрута, до чтения базы.
foreach (['{"version":1,"mode":"sometimes"}' => 'неверная настройка запуска', '{' => 'Условия или вопросы этапа записаны неверно'] as $zapis => $zhdem) {
    $process = new \GlpiPlugin\Itilflow\Process();
    $process->fields = ['id' => 5, 'name' => 'Маршрут с ошибкой', 'entities_id' => 0, 'calendars_id' => 0, 'start_if' => $zapis];
    try {
        Transfer::export([$process]);
        ok(false, 'экспорт с повреждённым «Запускать, если» называет маршрут', 'выгружен');
    } catch (TransferError $e) {
        ok(str_contains($e->getMessage(), '«Маршрут с ошибкой»') && str_contains($e->getMessage(), $zhdem),
            'экспорт с повреждённым «Запускать, если» называет маршрут', $e->getMessage());
    } catch (\Throwable $e) {
        ok(false, 'экспорт с повреждённым «Запускать, если» называет маршрут', get_class($e) . ': ' . $e->getMessage());
    }
}

// Срок этапа проверяется до записи: импорт не обещает «Готов», если срок не поместится.
$prepyatstviya = new ReflectionMethod(Transfer::class, 'blockers');
$dolgo = fajl113(null);
$dolgo['routes'][0]['outcome_contract'] = 'legacy';
$dolgo['routes'][0]['stages'][0]['deadline_minutes'] = 6000 * 1440;
[$error, $parsed] = prichina113($dolgo);
$prichiny = $error === '' ? $prepyatstviya->invoke(null, $parsed['routes'][0], ['Entity#0' => 0, 'Group#7' => 7]) : [$error];
ok(count(array_filter($prichiny, static fn(string $p): bool => str_contains($p, 'Этап 10 «Этап 10»') && str_contains($p, '2038'))) === 1,
    'срок дальше 2038 года — причина у этапа до импорта', implode(' | ', $prichiny));
$dolgo['routes'][0]['stages'][0]['deadline_minutes'] = 3 * 1440;
[, $parsed] = prichina113($dolgo);
ok($prepyatstviya->invoke(null, $parsed['routes'][0], ['Entity#0' => 0, 'Group#7' => 7]) === [], 'обычный срок импорту не мешает');

itog();
