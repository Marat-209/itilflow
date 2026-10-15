<?php

/**
 * 1.10.0: файл переноса маршрутов — строгая проверка по схеме формата 1.
 *
 * Правильный файл читается, этапы — по порядку, у ссылок — сведения для поиска.
 * Любое отличие от схемы — понятная причина, а не ошибка PHP: не JSON, чужой
 * формат, другая версия, лишнее или недостающее поле, не тот тип, повтор номера
 * или порядка, ссылка на этап другого маршрута, ссылка без сведений, слишком
 * большой файл. Без базы: чтение файла её не трогает.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Transfer;
use GlpiPlugin\Itilflow\TransferError;

if (!estKlass(Transfer::class)) {
    itog();
}

function etap(int $id, int $rank, array $izm = []): array
{
    return array_replace([
        'id' => $id, 'name' => 'Этап ' . $rank, 'ranking' => $rank, 'execution_mode' => 'inline',
        'entity_strategy' => 'inherit', 'entities_id_target' => 0, 'groups_id' => 7, 'users_id' => 0,
        'itilcategories_id' => 0, 'slas_id' => 0, 'duration' => 0, 'is_optional' => 0,
        'completion_comment' => 'optional', 'deadline_minutes' => 0, 'approval_percent' => 100,
        'approver_source' => 'fixed', 'ticket_status' => 0, 'on_reject' => 'block',
        'plugin_itilflow_stages_id_reject' => 0, 'content' => '<p>Сделать</p>',
        'department_result_source' => '', 'negative_policy' => 'block', 'return_targets' => [],
    ], $izm);
}

function fajl(): array
{
    $treb = [
        ['itemtype' => 'Entity', 'id' => 0, 'name' => 'Корень', 'completename' => 'Корень'],
        ['itemtype' => 'Entity', 'id' => 3, 'name' => 'Отдел', 'completename' => 'Корень > Отдел'],
        ['itemtype' => 'Group', 'id' => 7, 'name' => 'Линия 1', 'completename' => 'ИТ > Линия 1'],
        ['itemtype' => 'Calendar', 'id' => 2, 'name' => '8/5'],
        ['itemtype' => 'User', 'id' => 9, 'name' => 'ivanov'],
        ['itemtype' => 'ITILCategory', 'id' => 11, 'name' => 'Ноутбук', 'completename' => 'Техника > Ноутбук'],
    ];
    return [
        'format' => 'itilflow-routes', 'version' => 1, 'plugin_version' => '1.10.0',
        'glpi_version' => '11.0.8', 'date' => '2026-10-15T12:00:00+03:00',
        'routes' => [
            [
                'id' => 5, 'name' => 'Новый порядок', 'comment' => null, 'itemtype' => 'Ticket',
                'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'enforcement' => 'audit',
                'block_resolution' => 1, 'public_log' => 1, 'helpdesk_tab' => 1, 'allow_withdraw' => 1,
                'calendars_id' => 2, 'outcome_contract' => 'structured_v1',
                'stages' => [
                    // Не по порядку — файл может быть правлен руками.
                    etap(52, 20, ['execution_mode' => 'delegated', 'entity_strategy' => 'fixed', 'entities_id_target' => 3,
                        'itilcategories_id' => 11, 'department_result_source' => 'assignee_action', 'negative_policy' => 'abort',
                        'return_targets' => [51, 52]]),
                    etap(51, 10, ['execution_mode' => 'approval', 'approver_source' => 'runtime', 'groups_id' => 0,
                        'ticket_status' => 10, 'return_targets' => [51]]),
                    etap(53, 30, ['groups_id' => 0, 'users_id' => 9]),
                ],
                'data_requirements' => $treb,
            ],
            [
                'id' => 6, 'name' => 'Прежний порядок', 'comment' => 'Комментарий', 'itemtype' => 'Change',
                'entities_id' => 3, 'is_recursive' => 0, 'is_active' => 0, 'enforcement' => 'strict',
                'block_resolution' => 0, 'public_log' => 0, 'helpdesk_tab' => 0, 'allow_withdraw' => 0,
                'calendars_id' => 0, 'outcome_contract' => 'legacy',
                'stages' => [
                    etap(61, 10, ['negative_policy' => '', 'return_targets' => null]),
                    // Вернуться на более поздний этап — в прежнем порядке так бывает.
                    etap(62, 20, ['execution_mode' => 'approval', 'on_reject' => 'back',
                        'plugin_itilflow_stages_id_reject' => 63, 'negative_policy' => '', 'return_targets' => null]),
                    etap(63, 30, ['negative_policy' => '', 'return_targets' => null]),
                ],
                'data_requirements' => [$treb[1], $treb[2]],
            ],
        ],
    ];
}

function json(array $f): string
{
    return json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function prichina(string $json): string
{
    try {
        Transfer::parse($json);
        return '';
    } catch (TransferError $e) {
        return $e->getMessage();
    } catch (\Throwable $e) {
        return 'ОШИБКА PHP: ' . get_class($e) . ': ' . $e->getMessage();
    }
}

// 1. Правильный файл.
$f = Transfer::parse(json(fajl()));
ok(count($f['routes']) === 2, 'правильный файл читается: два маршрута');
$r = $f['routes'][0];
ok(array_column($r['stages'], 'ranking') === [10, 20, 30], 'этапы — по порядку, а не как в файле');
ok(array_keys($r['data_requirements']) === ['Entity#0', 'Entity#3', 'Group#7', 'Calendar#2', 'User#9', 'ITILCategory#11'],
    'нужные сведения об объектах — по ключу «тип#номер»', implode(', ', array_keys($r['data_requirements'])));
ok(($r['uses']['Group#7'] ?? []) === ['этап 20 «Этап 20» — группа'], 'у ссылки — где она в маршруте',
    json_encode($r['uses']['Group#7'] ?? null, JSON_UNESCAPED_UNICODE));
ok(($r['uses']['Entity#3'] ?? []) === ['этап 20 «Этап 20» — организация заявки отдела'], 'организация отдела «В указанной организации» — ссылка');
ok(array_keys($f['routes'][1]['data_requirements']) === ['Entity#3', 'Group#7'], 'у второго маршрута — только его объекты');
ok(Transfer::parse("\xEF\xBB\xBF" . json(fajl()))['routes'][1]['name'] === 'Прежний порядок', 'метка порядка байтов в начале не мешает');
$lishnee = fajl();
$lishnee['routes'][1]['data_requirements'][] = ['itemtype' => 'User', 'id' => 9, 'name' => 'ivanov'];
ok(!isset(Transfer::parse(json($lishnee))['routes'][1]['data_requirements']['User#9']), 'сведения без ссылки не просят сопоставления');

// 2. Плохие файлы — причина словами.
$plohie = [
    'пустой' => ['', 'не файл JSON'],
    'не JSON' => ['маршруты', 'не файл JSON'],
    'список вместо объекта' => ['[]', 'не файл маршрутов'],
    'чужой формат' => [json(['format' => 'glpi-forms'] + fajl()), 'не файл маршрутов'],
    'другая версия' => [json(array_replace(fajl(), ['version' => 2])), 'Версия формата файла — 2'],
    'версия строкой' => [json(array_replace(fajl(), ['version' => '1'])), 'Версия формата'],
    'нет маршрутов' => [json(array_replace(fajl(), ['routes' => []])), 'нет маршрутов'],
    'глубокая вложенность' => [str_repeat('[', 100) . str_repeat(']', 100), 'не файл JSON'],
    'не UTF-8' => ["{\"format\":\"itilflow-routes\",\"x\":\"\xC3\x28\"}", 'не файл JSON'],
    'больше 2 МБ' => [json(fajl()) . str_repeat(' ', Transfer::MAX_BYTES), 'больше 2 МБ'],
];
$izm = static function (callable $f): string {
    $d = fajl();
    $f($d);
    return json($d);
};
$plohie += [
    'лишнее поле файла' => [$izm(static function (&$d) { $d['token'] = 'x'; }), 'лишнее поле «token»'],
    'нет поля маршрута' => [$izm(static function (&$d) { unset($d['routes'][0]['enforcement']); }), 'нет поля «enforcement»'],
    'лишнее поле маршрута' => [$izm(static function (&$d) { $d['routes'][0]['instances'] = []; }), 'лишнее поле «instances»'],
    'номер строкой' => [$izm(static function (&$d) { $d['routes'][0]['id'] = '5'; }), '«id» — нужно целое число'],
    'отрицательная организация' => [$izm(static function (&$d) { $d['routes'][0]['entities_id'] = -1; }), '«entities_id» — нужно целое число'],
    'неизвестный тип объекта' => [$izm(static function (&$d) { $d['routes'][0]['itemtype'] = 'Computer'; }), '«itemtype» — недопустимое значение'],
    'флаг true вместо 1' => [$izm(static function (&$d) { $d['routes'][0]['is_active'] = true; }), '«is_active» — недопустимое значение'],
    'пустое имя' => [$izm(static function (&$d) { $d['routes'][0]['name'] = '  '; }), 'не пустой'],
    'имя длиннее 255' => [$izm(static function (&$d) { $d['routes'][0]['name'] = str_repeat('я', 256); }), 'до 255 знаков'],
    'повтор номера маршрута' => [$izm(static function (&$d) { $d['routes'][1]['id'] = 5; foreach ($d['routes'][1]['stages'] as &$s) { $s['id'] += 100; if ($s['plugin_itilflow_stages_id_reject']) { $s['plugin_itilflow_stages_id_reject'] += 100; } } }), 'номер 5 в файле повторяется'],
    'неизвестный режим этапа' => [$izm(static function (&$d) { $d['routes'][0]['stages'][0]['execution_mode'] = 'script'; }), '«execution_mode» — недопустимое значение'],
    'порог больше 100' => [$izm(static function (&$d) { $d['routes'][0]['stages'][0]['approval_percent'] = 150; }), '«approval_percent»'],
    'инструкция больше 64 КБ' => [$izm(static function (&$d) { $d['routes'][0]['stages'][0]['content'] = str_repeat('a', 70000); }), 'до 64 КБ'],
    'повтор номера этапа в файле' => [$izm(static function (&$d) { $d['routes'][1]['stages'][0]['id'] = 51; }), 'номер этапа 51 в файле повторяется'],
    'точки возврата строкой' => [$izm(static function (&$d) { $d['routes'][0]['stages'][0]['return_targets'] = '[51]'; }), 'точки возврата записаны неверно'],
    'повтор точки возврата' => [$izm(static function (&$d) { $d['routes'][0]['stages'][0]['return_targets'] = [51, 51]; }), 'точки возврата записаны неверно'],
    'ссылка без сведений' => [$izm(static function (&$d) { $d['routes'][0]['stages'][2]['users_id'] = 10; }), 'нет сведений об объекте GLPI User#10'],
    'организация маршрута без сведений' => [$izm(static function (&$d) { $d['routes'][1]['entities_id'] = 0; }), 'нет сведений об объекте GLPI Entity#0'],
    'неизвестный тип в сведениях' => [$izm(static function (&$d) { $d['routes'][0]['data_requirements'][] = ['itemtype' => 'Profile', 'id' => 4, 'name' => 'Super-Admin']; }), 'неизвестный тип'],
    'пароль в сведениях' => [$izm(static function (&$d) { $d['routes'][0]['data_requirements'][4]['password'] = 'x'; }), 'лишнее поле «password»'],
    'полное имя у пользователя' => [$izm(static function (&$d) { $d['routes'][0]['data_requirements'][4]['completename'] = 'x'; }), 'лишнее поле «completename»'],
    'нет полного имени у группы' => [$izm(static function (&$d) { unset($d['routes'][0]['data_requirements'][2]['completename']); }), 'нет поля «completename»'],
    'повтор сведений' => [$izm(static function (&$d) { $d['routes'][0]['data_requirements'][] = $d['routes'][0]['data_requirements'][2]; }), 'Group#7 в списке повторяется'],
    'нулевая группа в сведениях' => [$izm(static function (&$d) { $d['routes'][0]['data_requirements'][2]['id'] = 0; }), '«id» — нужно целое число от 1'],
    'этап — список' => [$izm(static function (&$d) { $d['routes'][0]['stages'][0] = [1, 2]; }), 'ожидается объект'],
];
foreach ($plohie as $chto => [$json, $zhdem]) {
    $p = prichina($json);
    ok($p !== '' && str_contains($p, $zhdem) && !str_starts_with($p, 'ОШИБКА PHP'), 'плохой файл: ' . $chto, $p === '' ? 'принят' : $p);
}

// 3. Ошибки смысла одного маршрута — «Не может быть импортирован» у него, файл читается.
$smysl = [
    'статус «Решена» на этапе' => [static function (&$d) { $d['routes'][0]['stages'][0]['ticket_status'] = 5; }, 0, 'статус, который выставляет этап, не подходит типу маршрута'],
    'неизвестный статус 99 у изменения' => [static function (&$d) { $d['routes'][1]['stages'][0]['ticket_status'] = 99; }, 1, 'статус, который выставляет этап'],
    'одинаковый порядок' => [static function (&$d) { $d['routes'][0]['stages'][2]['ranking'] = 10; }, 0, 'Два этапа с порядком 10.'],
    'порядок 0' => [static function (&$d) { $d['routes'][0]['stages'][2]['ranking'] = 0; }, 0, 'порядок должен быть больше нуля'],
    'точка возврата на этап другого маршрута' => [static function (&$d) { $d['routes'][0]['stages'][0]['return_targets'] = [61]; }, 0, 'точка возврата: этапа нет в этом маршруте'],
    '«Вернуться на этап» — нет этапа' => [static function (&$d) { $d['routes'][1]['stages'][1]['plugin_itilflow_stages_id_reject'] = 999; }, 1, '«Вернуться на этап»: этапа нет в этом маршруте'],
];
foreach ($smysl as $chto => [$f, $nomer, $zhdem]) {
    $d = fajl();
    $f($d);
    try {
        $r = Transfer::parse(json($d))['routes'];
        $drugoj = $r[1 - $nomer]['problems'];
        ok((bool) array_filter($r[$nomer]['problems'], static fn(string $p): bool => str_contains($p, $zhdem)) && $drugoj === [],
            'ошибка смысла у маршрута, файл читается: ' . $chto, json_encode($r[$nomer]['problems'], JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        ok(false, 'ошибка смысла у маршрута, файл читается: ' . $chto, $e->getMessage());
    }
}
ok(Transfer::parse(json(fajl()))['routes'][0]['problems'] === [], 'у правильного маршрута ошибок смысла нет');

// 4. Ссылки, которые этап не читает, сведений не требуют.
$d = fajl();
$d['routes'][0]['stages'][1]['groups_id'] = 77;      // согласование «при прохождении»: группа не нужна
$d['routes'][0]['stages'][2]['itilcategories_id'] = 78; // задача: категория — только у дочерней заявки
$d['routes'][0]['stages'][2]['entity_strategy'] = 'fixed';
$d['routes'][0]['stages'][2]['entities_id_target'] = 79;
try {
    $r = Transfer::parse(json($d))['routes'][0];
    ok(!isset($r['data_requirements']['Group#77']) && !isset($r['data_requirements']['ITILCategory#78']) && !isset($r['data_requirements']['Entity#79']),
        'поля, которые этап не читает, — не ссылки: сведений не нужно');
} catch (\Throwable $e) {
    ok(false, 'поля, которые этап не читает, — не ссылки: сведений не нужно', $e->getMessage());
}

// 5. Поля запроса.
ok(Transfer::requestIds(['3', 4, 'x', '-1', '5.0', '3', ['7']]) === [3, 4], 'номера из запроса — только целые, без повторов');
ok(Transfer::requestIds('12') === [12], 'один номер строкой');
ok(Transfer::requestReplacements([
    ['itemtype' => 'Group', 'original_id' => '7', 'replacement_id' => '0'],
    ['itemtype' => 'Profile', 'original_id' => '1', 'replacement_id' => '2'],
    ['itemtype' => 'User', 'original_id' => 'x', 'replacement_id' => '2'],
    ['itemtype' => 'Entity', 'original_id' => '3', 'replacement_id' => '3'],
    'мусор',
]) === [['Group', 7, 0], ['Entity', 3, 3]], 'замены — только известные типы и целые номера');
ok(Transfer::requestReplacements('[{"itemtype":"Group","original_id":7,"replacement_id":8},{"itemtype":"X","original_id":1,"replacement_id":1}]')
    === [['Group', 7, 8]], 'прежние замены — одним полем JSON');
ok(Transfer::requestReplacements('не json') === [] && Transfer::requestReplacements('[[[[[1]]]]]') === [], 'испорченное поле замен — пусто, без ошибки');

itog();
