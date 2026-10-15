<?php

/**
 * 1.10.0: перенос маршрутов — экспорт и импорт с базой GLPI. Всё в транзакции проверки, откатывается.
 *
 * - Экспорт всех маршрутов базы читается строгой проверкой; в файле нет рабочих данных и паролей.
 * - Тот же файл в ту же базу: у каждого маршрута «уже есть — будет пропущен», импорт ничего не создаёт.
 * - Копия маршрута: создаётся выключенной, этапы и ссылки — как у исходного; повторный экспорт копии
 *   совпадает с исходным (туда и обратно). Существующие маршруты и этапы не меняются ни на байт.
 * - Сопоставление: по полному имени при чужом номере; по номеру, если полное имя другое, а имя то же;
 *   не найдено; найдено несколько — со списком; замена; «оставить пустым» и что из этого следует;
 *   замена на невидимый объект не принимается.
 * - Пропуск из списка; одна транзакция — сбой второго маршрута отменяет первый; без права — ничего.
 * - Страницы шагов: названия из файла — текстом, скрытые поля и ключ CSRF на месте.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\Transfer;
use GlpiPlugin\Itilflow\TransferError;
use GlpiPlugin\Itilflow\TransferMapper;

global $DB, $PLUGIN_HOOKS;
if (!estKlass(Transfer::class) || !estKlass(TransferMapper::class)) {
    itog();
}
$admin = adminGlpi();
vojti($admin, 'process');

$P = Process::getTable();
$S = Stage::getTable();

/** Слепок таблиц определения: номер => md5 строки. */
function slepok(): array
{
    global $DB;
    $out = [];
    foreach (['glpi_plugin_itilflow_processes', 'glpi_plugin_itilflow_stages'] as $t) {
        foreach ($DB->request(['FROM' => $t, 'ORDER' => 'id']) as $row) {
            $out[$t . '#' . $row['id']] = md5(serialize($row));
        }
    }
    return $out;
}

function mapper(): TransferMapper
{
    return new TransferMapper(Session::getActiveEntities());
}

function vseMarshruty(): array
{
    global $DB;
    $out = [];
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_itilflow_processes', 'ORDER' => 'id']) as $row) {
        $p = new Process();
        $p->getFromDB((int) $row['id']);
        $out[] = $p;
    }
    return $out;
}

/** Маршрут файла без номеров и имени — для сравнения туда и обратно. Ссылки этапов — порядком этапа. */
function bezNomerov(array $route): array
{
    $rank = array_column($route['stages'], 'ranking', 'id');
    unset($route['id'], $route['name'], $route['is_active']);
    foreach ($route['stages'] as &$s) {
        $s['return_targets'] = $s['return_targets'] === null ? null : array_map(static fn(int $t): int => $rank[$t], $s['return_targets']);
        $s['plugin_itilflow_stages_id_reject'] = $s['plugin_itilflow_stages_id_reject'] > 0 ? $rank[$s['plugin_itilflow_stages_id_reject']] : 0;
        unset($s['id']);
    }
    unset($s);
    return $route;
}

function soobshcheniya(): array
{
    $out = [];
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [] as $list) {
        foreach ($list as $m) {
            $out[] = (string) $m;
        }
    }
    return $out;
}

$vse = vseMarshruty();
ok($vse !== [], 'в базе есть маршруты');
$do = slepok();

// 1. Экспорт всех.
$exp = Transfer::export($vse);
ok(str_ends_with($exp['filename'], '.json') && preg_match('/^[a-z0-9.-]+$/', $exp['filename']) === 1, 'имя файла — латиницей, .json', $exp['filename']);
$file = Transfer::parse($exp['json']);
ok(count($file['routes']) === count($vse), 'свой экспорт проходит строгую проверку: ' . count($vse) . ' маршрутов');
$syroj = json_decode($exp['json'], true);
ok($syroj['plugin_version'] !== '' && $syroj['glpi_version'] === GLPI_VERSION && $syroj['date'] !== '', 'в файле версии формата, плагина, GLPI и дата');
foreach (['instances', 'steps', 'violations', 'attempts', 'outcomes', 'password', 'api_token', 'personal_token', 'definition_snapshot'] as $slovo) {
    ok(!str_contains($exp['json'], '"' . $slovo), 'в файле нет «' . $slovo . '»');
}
ok(!array_filter($syroj['routes'], static fn(array $r): bool => array_key_exists('version', $r) || array_key_exists('date_mod', $r)),
    'у маршрутов нет версии определения и дат — это данные исходной базы');
$etapov = (int) countElementsInTable($S, ['plugin_itilflow_processes_id' => array_map(static fn(Process $p): int => $p->getID(), $vse)]);
ok(array_sum(array_map(static fn(array $r): int => count($r['stages']), $file['routes'])) === $etapov, 'все этапы в файле: ' . $etapov);
$gruppy = 0;
foreach ($file['routes'] as $r) {
    foreach ($r['data_requirements'] as $req) {
        if ($req['itemtype'] === 'Group') {
            $gruppy++;
            $g = new Group();
            $g->getFromDB($req['id']);
            ok($req['completename'] === $g->fields['completename'] && $req['name'] === $g->fields['name'],
                'группа ' . $req['id'] . ': имя и полное имя как в базе');
        }
    }
}
info('групп в сведениях: ' . $gruppy);

// 2. Тот же файл в ту же базу — всё «уже есть», импорт ничего не создаёт.
$prev = Transfer::preview($file, mapper());
ok(count($prev) === count($vse) && !array_filter($prev, static fn(array $r): bool => $r['status'] !== Transfer::EXISTS),
    'тот же файл: у каждого маршрута «уже есть — будет пропущен»',
    implode('; ', array_map(static fn(array $r): string => $r['name'] . ': ' . $r['status'], $prev)));
$res = Transfer::import($file, mapper());
ok($res['ok'] && !array_filter($res['rows'], static fn(array $r): bool => $r['status'] === 'imported'), 'импорт того же файла ничего не создаёт');
ok(slepok() === $do, 'таблицы маршрутов и этапов не изменились');

// 3. Копия маршрута — где больше всего режимов и есть точки возврата.
$src = null;
$best = -1;
foreach ($file['routes'] as $r) {
    $modes = count(array_unique(array_column($r['stages'], 'execution_mode')));
    $vozvr = count(array_filter($r['stages'], static fn(array $s): bool => !empty($s['return_targets']) || $s['plugin_itilflow_stages_id_reject'] > 0));
    $score = $modes * 100 + $vozvr * 10 + count($r['stages']);
    if ($score > $best) {
        $best = $score;
        $src = $r;
    }
}
info('копия маршрута ' . $src['id'] . ' «' . $src['name'] . '»: этапов ' . count($src['stages']));
$drugie = array_values(array_diff(array_column($file['routes'], 'id'), [$src['id']]));
$prev = Transfer::preview($file, mapper(), $drugie, [$src['id']]);
ok(count($prev) === 1 && $prev[0]['status'] === Transfer::READY && $prev[0]['copy'] === true
    && $prev[0]['new_name'] === $src['name'] . ' (копия)', 'копия: «Готов к импорту», имя «… (копия)»', json_encode($prev, JSON_UNESCAPED_UNICODE));
$res = Transfer::import($file, mapper(), $drugie, [$src['id']]);
$novyj = $res['rows'][0]['id'] ?? 0;
ok($res['ok'] && count($res['rows']) === 1 && $res['rows'][0]['status'] === 'imported' && $novyj > 0, 'копия импортирована', $res['error']);
$p = new Process();
ok($novyj > 0 && $p->getFromDB($novyj), 'маршрут-копия есть в базе');
ok((int) ($p->fields['is_active'] ?? 1) === 0, 'маршрут создан выключенным');
foreach (['itemtype', 'entities_id', 'is_recursive', 'enforcement', 'block_resolution', 'public_log', 'helpdesk_tab', 'allow_withdraw',
    'calendars_id', 'outcome_contract', 'comment'] as $pole) {
    ok((string) $p->fields[$pole] === (string) $src[$pole], 'поле маршрута ' . $pole . ' — как у исходного');
}
$obratno = Transfer::parse(Transfer::export([$p])['json'])['routes'][0];
ok(bezNomerov($obratno) === bezNomerov($src), 'туда и обратно: повторный экспорт копии совпадает с исходным (этапы, ссылки, точки возврата)');
if (($p->fields['outcome_contract'] ?? '') === DefinitionSnapshot::MODE) {
    ok(DefinitionSnapshot::valid(['schema' => 1, 'process' => $p->fields, 'stages' => $p->getStages()]),
        'копия нового порядка проходит проверку определения при запуске');
}
$posle = slepok();
ok(array_intersect_key($posle, $do) === $do, 'существующие маршруты и этапы не изменились ни на байт');
ok(count($posle) - count($do) === 1 + count($src['stages']), 'добавлены только маршрут-копия и её этапы');
ok(soobshcheniya() === [], 'на следующую страницу сообщений не осталось', implode(' | ', soobshcheniya()));

// Маршрут прежнего порядка с «Вернуться на этап»: номер этапа возврата — номером копии.
$L = marshrutPrezhnegoPoryadka();
if ($L > 0) {
    $lp = new Process();
    $lp->getFromDB($L);
    $lf = Transfer::parse(Transfer::export([$lp])['json']);
    $lr = $lf['routes'][0];
    ok((bool) array_filter($lr['stages'], static fn(array $s): bool => $s['on_reject'] === 'back' && $s['plugin_itilflow_stages_id_reject'] > 0),
        'в файле прежнего порядка есть «Вернуться на этап»');
    $res = Transfer::import($lf, mapper(), [], [$L]);
    $lk = new Process();
    ok($res['ok'] && $lk->getFromDB($res['rows'][0]['id'] ?? 0), 'копия маршрута прежнего порядка импортирована', $res['error']);
    if (!$lk->isNewItem()) {
        $lo = Transfer::parse(Transfer::export([$lk])['json'])['routes'][0];
        ok(bezNomerov($lo) === bezNomerov($lr), 'прежний порядок туда и обратно: «Вернуться на этап» и пустые точки возврата сохранены');
        $chuzhie = array_filter($lk->getStages(), static fn(array $s): bool => (int) $s['plugin_itilflow_stages_id_reject'] > 0
            && (new Stage())->find(['id' => (int) $s['plugin_itilflow_stages_id_reject'], 'plugin_itilflow_processes_id' => $lk->getID()]) === []);
        ok($chuzhie === [], 'этап возврата — этап самой копии, не исходного маршрута');
    }
}

// Вторая копия того же маршрута — «(копия 2)».
$prev = Transfer::preview($file, mapper(), $drugie, [$src['id']]);
ok($prev[0]['new_name'] === $src['name'] . ' (копия 2)', 'следующая копия — «(копия 2)»', $prev[0]['new_name']);

// 4. Сопоставление. Маршрут с группой-исполнителем — переименованный, чтобы имя было свободно.
$sGr = null;
foreach ($file['routes'] as $r) {
    foreach ($r['data_requirements'] as $k => $req) {
        if ($req['itemtype'] === 'Group' && $sGr === null) {
            $sGr = [$r, $k];
        }
    }
}
[$rg, $kg] = $sGr;
$rg['name'] = '[проверка 1.10] сопоставление ' . mt_rand();
$fileG = ['routes' => [$rg]] + $file;
$gid = $rg['data_requirements'][$kg]['id'];
$req = $rg['data_requirements'][$kg];

$m = mapper();
ok($m->resolve($req) === ['state' => TransferMapper::FOUND, 'id' => $gid, 'candidates' => []], 'группа найдена по полному имени');
$chuzhoj = ['id' => 999999999] + $req;
ok(mapper()->resolve($chuzhoj)['state'] === TransferMapper::FOUND && mapper()->resolve($chuzhoj)['id'] === $gid,
    'чужой номер, то же полное имя — найдена по полному имени');
$poNomeru = ['completename' => 'Нет такой > ' . $req['name']] + $req;
ok(mapper()->resolve($poNomeru)['state'] === TransferMapper::BY_ID && mapper()->resolve($poNomeru)['id'] === $gid,
    'полное имя другое, номер и имя те же — найдена по номеру');
$net = ['id' => 999999999, 'name' => 'Нет такой', 'completename' => 'Нет такой > группы'] + $req;
ok(mapper()->resolve($net)['state'] === TransferMapper::MISSING, 'нет ни по имени, ни по номеру — не найдена');
$imyaDrugoe = ['name' => 'Другое имя', 'completename' => 'Нет такой > группы'] + $req;
ok(mapper()->resolve($imyaDrugoe)['state'] === TransferMapper::MISSING, 'номер тот же, имя другое — не найдена');

// Нет в базе → «Не может быть импортирован», в «Устранять проблемы» — с местом в маршруте.
$rgNet = $rg;
$rgNet['data_requirements'][$kg] = ['id' => $gid] + $net;
$fileNet = ['routes' => [$rgNet]] + $file;
$prev = Transfer::preview($fileNet, mapper());
ok($prev[0]['status'] === Transfer::INVALID && $prev[0]['resolve'] && in_array('Не найдено объектов GLPI: 1.', $prev[0]['details'], true),
    'не найдена → «Не может быть импортирован», «Не найдено объектов GLPI: 1.»', json_encode($prev[0], JSON_UNESCAPED_UNICODE));
$iss = Transfer::issues($rgNet, mapper());
ok(count($iss) === 1 && $iss[0]['state'] === TransferMapper::MISSING && $iss[0]['itemtype'] === 'Group' && $iss[0]['uses'] !== [],
    '«Устранять проблемы»: одна строка — группа, где она в маршруте');
$res = Transfer::import($fileNet, mapper());
ok(!array_filter($res['rows'], static fn(array $r): bool => $r['status'] === 'imported'), 'не сопоставленный маршрут не импортируется');

// Замена — «Готов к импорту»; та же группа выбрана вручную.
$m = mapper();
$m->addReplacement('Group', $gid, $gid);
$prev = Transfer::preview($fileNet, $m);
ok($prev[0]['status'] === Transfer::READY, 'группа выбрана вручную — «Готов к импорту»', json_encode($prev[0]['details'], JSON_UNESCAPED_UNICODE));
ok(Transfer::issues($rgNet, $m)[0]['state'] === TransferMapper::REPLACED, 'выбранное остаётся в «Устранять проблемы» — можно поменять');

// Оставить пустым — у этапа без исполнителя причина словами.
$m = mapper();
$m->addReplacement('Group', $gid, 0);
$prev = Transfer::preview($fileNet, $m);
$nuzhenIsp = false;
foreach ($rgNet['stages'] as $s) {
    $runtime = $s['execution_mode'] === 'approval' && $s['approver_source'] !== 'fixed';
    $nuzhenIsp = $nuzhenIsp || ($s['groups_id'] === $gid && $s['users_id'] === 0 && !$runtime);
}
if ($nuzhenIsp) {
    ok($prev[0]['status'] === Transfer::INVALID && (bool) array_filter($prev[0]['details'],
        static fn(string $d): bool => str_contains($d, 'не выбран исполнитель')),
        'группа оставлена пустой — «не выбран исполнитель», маршрут не импортируется', json_encode($prev[0]['details'], JSON_UNESCAPED_UNICODE));
}
ok(in_array('Оставлено пустым: 1.', $prev[0]['details'], true), 'в предпросмотре — «Оставлено пустым: 1.»');

// Замена на несуществующий объект не принимается.
$m = mapper();
$m->addReplacement('Group', $gid, 999999999);
ok($m->resolve($rgNet['data_requirements'][$kg])['state'] === TransferMapper::MISSING, 'замена на несуществующую группу не принимается');
$m = mapper();
$m->addReplacement('Entity', 0, 999999999);
ok($m->resolve(['itemtype' => 'Entity', 'id' => 0, 'name' => 'x', 'completename' => 'Нет такой'])['state'] === TransferMapper::MISSING,
    'замена на несуществующую организацию не принимается');

// Найдено несколько: две группы с одинаковым полным именем в двух организациях.
$ents = array_values(array_slice(Session::getActiveEntities(), 0, 2));
if (count($ents) === 2) {
    $imya = '[проверка 1.10] дубль ' . mt_rand();
    $g1 = (int) (new Group())->add(['name' => $imya, 'entities_id' => $ents[0], 'is_assign' => 1]);
    $g2 = (int) (new Group())->add(['name' => $imya, 'entities_id' => $ents[1], 'is_assign' => 1]);
    $r = mapper()->resolve(['itemtype' => 'Group', 'id' => 999999999, 'name' => $imya, 'completename' => $imya]);
    ok($r['state'] === TransferMapper::SEVERAL && array_keys($r['candidates']) === [$g1, $g2],
        'две группы с одним полным именем — «найдено несколько», обе в списке', json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = mapper()->resolve(['itemtype' => 'Group', 'id' => $g2, 'name' => $imya, 'completename' => $imya]);
    ok($r['state'] === TransferMapper::BY_ID && $r['id'] === $g2, 'несколько, но номер и имя совпали — берётся по номеру');
} else {
    info('у администратора одна организация — «найдено несколько» не проверено');
}

// 5. Пропуск: убранный из списка не показывается и не создаётся.
$prev = Transfer::preview($file, mapper(), [$src['id']], [$src['id']]);
ok(!in_array($src['id'], array_column($prev, 'id'), true), 'убранный из списка маршрут не показывается');
$pered = countElementsInTable($P);
Transfer::import($file, mapper(), array_column($file['routes'], 'id'), array_column($file['routes'], 'id'));
ok(countElementsInTable($P) === $pered, 'все убраны — ничего не создано');

// 6. Одна транзакция: второй маршрут падает на записи этапа — первый тоже не остаётся.
$a = $rg;
$b = $rg;
$b['id'] = $rg['id'] + 1000000;
$b['name'] = $rg['name'] . ' второй';
$sdvig = 1000000;
foreach ($b['stages'] as &$s) {
    $s['id'] += $sdvig;
    $s['name'] = '[сбой записи] ' . $s['name'];
    $s['return_targets'] = $s['return_targets'] === null ? null : array_map(static fn(int $t): int => $t + $sdvig, $s['return_targets']);
    $s['plugin_itilflow_stages_id_reject'] = $s['plugin_itilflow_stages_id_reject'] > 0 ? $s['plugin_itilflow_stages_id_reject'] + $sdvig : 0;
}
unset($s);
$fileAB = ['routes' => [$a, $b]] + $file;
ok(count(array_filter(Transfer::preview($fileAB, mapper()), static fn(array $r): bool => $r['status'] === Transfer::READY)) === 2,
    'два маршрута «Готов к импорту»');
$PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_ADD]['itilflow'][Stage::class] = static function (CommonDBTM $item): void {
    if (is_array($item->input) && str_starts_with((string) ($item->input['name'] ?? ''), '[сбой записи]')) {
        $item->input = false;
    }
};
$pered = slepok();
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['до импорта']];
$res = Transfer::import($fileAB, mapper());
unset($PLUGIN_HOOKS[\Glpi\Plugin\Hooks::PRE_ITEM_ADD]['itilflow'][Stage::class]);
ok(!$res['ok'] && str_contains($res['error'], 'не создан'), 'сбой записи — импорт не удался, причина названа', $res['error']);
ok(slepok() === $pered, 'одна транзакция: первый маршрут тоже не записан, этапов нет');
ok(countElementsInTable($P, ['name' => $a['name']]) === 0, 'маршрута «' . $a['name'] . '» нет');
ok(!array_filter($res['rows'], static fn(array $r): bool => $r['status'] === 'imported'), 'в результате — ни одного «Импортировано»');
ok(soobshcheniya() === ['до импорта'], 'сообщения сессии — как до импорта', implode(' | ', soobshcheniya()));
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

// 7. Без права создавать маршруты — ничего.
$pravo = $_SESSION['glpiactiveprofile']['plugin_itilflow_process'];
$_SESSION['glpiactiveprofile']['plugin_itilflow_process'] = READ;
$pered = slepok();
$res = Transfer::import(['routes' => [$a]] + $file, mapper());
$_SESSION['glpiactiveprofile']['plugin_itilflow_process'] = $pravo;
ok(!$res['ok'] && str_contains($res['error'], 'нет права') && slepok() === $pered, 'без права создавать — «нет права», ничего не записано', $res['error']);

// 8. Страницы шагов: названия из файла — текстом; скрытые поля и ключ CSRF на месте.
$xss = $rg;
$xss['name'] = '<script>alert(1)</script> «маршрут»';
$fileX = ['routes' => [$xss]] + $file;
$json = json_encode(['format' => Transfer::FORMAT, 'version' => 1, 'plugin_version' => '1.10.0', 'glpi_version' => GLPI_VERSION,
    'date' => date('c'), 'routes' => [array_diff_key($xss, ['uses' => 1]) + ['data_requirements' => []]]], JSON_UNESCAPED_UNICODE);
$page = ['title' => 'Импорт маршрутов', 'menu' => ['admin', Process::class], 'action' => '/plugins/itilflow/front/import.php',
    'list' => Process::getSearchURL(), 'json' => $json, 'skipped' => [7], 'copies' => [8],
    'replacements' => [['itemtype' => 'Group', 'original_id' => $gid, 'replacement_id' => 0]]];
$tpl = \Glpi\Application\View\TemplateRenderer::getInstance();
try {
    $html = $tpl->render('@itilflow/import_step2_preview.html.twig', $page + [
        'rows' => Transfer::preview($fileX, mapper()), 'ready' => 1]);
    ok(!str_contains($html, '<script>alert(1)') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'предпросмотр: название из файла — текстом');
    $x = dom($html);
    $f = '//form[contains(@action, "step=execute")]';
    ok($x->query($f . '//input[@name="_glpi_csrf_token" and @value!=""]')->length === 1, 'предпросмотр: ключ CSRF в форме');
    ok($x->query($f . '//input[@name="json"]')->item(0)?->getAttribute('value') === $json, 'предпросмотр: файл — скрытым полем без искажений');
    ok($x->query($f . '//input[@name="skipped_routes[]" and @value="7"]')->length === 1
        && $x->query($f . '//input[@name="copy_routes[]" and @value="8"]')->length === 1
        && $x->query($f . '//input[@name="replacements[0][replacement_id]" and @value="0"]')->length === 1, 'предпросмотр: убранные, копии и замены — скрытыми полями');
    ok(str_contains($html, 'Готов к импорту') && str_contains($html, 'Предпросмотр импорта'), 'предпросмотр: слова GLPI');

    $html = $tpl->render('@itilflow/import_step3_issues.html.twig', $page + ['route' => array_replace($rgNet, ['name' => $xss['name']]),
        'issues' => Transfer::issues($rgNet, mapper()), 'active_entity' => 0]);
    ok(!str_contains($html, '<script>alert(1)') && str_contains($html, 'Устранять проблемы'), '«Устранять проблемы»: название — текстом');
    $x = dom($html);
    ok($x->query('//*[@name="replacements[1][replacement_id]"]')->length === 1
        && $x->query('//input[@name="replacements[1][original_id]" and @value="' . $gid . '"]')->length === 1,
        '«Устранять проблемы»: замена — после прежних, с номером в файле');

    $html = $tpl->render('@itilflow/import_step4_result.html.twig', $page + ['result' => ['ok' => false, 'error' => '<b>x</b>',
        'rows' => [['name' => $xss['name'], 'status' => 'not_imported', 'id' => 0, 'url' => '', 'details' => ['<i>y</i>']]]]]);
    ok(!str_contains($html, '<b>x</b>') && !str_contains($html, '<i>y</i>') && str_contains($html, 'Ничего не импортировано'),
        'результат: причины — текстом, «Ничего не импортировано»');
} catch (\Throwable $e) {
    ok(false, 'страницы шагов собираются', get_class($e) . ': ' . $e->getMessage());
}

itog();
