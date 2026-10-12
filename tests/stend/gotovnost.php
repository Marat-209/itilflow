<?php

/**
 * Строка готовности совпадает с тем, что проверяет плагин при запуске.
 *
 * - У каждого маршрута нового порядка в базе «готов» тогда и только тогда, когда
 *   определение проходит DefinitionSnapshot::valid и нет других причин из списка
 *   (выключен, правило не видит маршрут, группа не видна в организации).
 * - Каждая порча — своё сообщение, с номером этапа и ссылкой
 *   на этап. Порча делается в базе внутри вложенной транзакции и откатывается.
 * - Служебная строка про автоматическое действие — только с правом «Настройка».
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Readiness;
use GlpiPlugin\Itilflow\Stage;

if (!estKlass(Readiness::class)) {
    itog();
}

global $DB;
$uid = adminGlpi();
vojti($uid, 'glpi');

function snimok(int $pid): array
{
    global $DB;
    $p = [];
    foreach ($DB->request(['FROM' => Process::getTable(), 'WHERE' => ['id' => $pid]]) as $row) {
        $p = $row;
    }
    $stages = [];
    foreach ($DB->request(['FROM' => Stage::getTable(), 'WHERE' => ['plugin_itilflow_processes_id' => $pid],
        'ORDER' => ['ranking', 'id']]) as $row) {
        $stages[] = $row;
    }
    return ['schema' => 1, 'process' => $p, 'stages' => $stages];
}

function gotovnost(int $pid): array
{
    $p = new Process();
    $p->getFromDB($pid);
    return Readiness::check($p);
}

function teksty(array $r): array
{
    return array_map(static fn(array $x): string => $x['text'], $r['problems']);
}

// 1. Маршруты базы: «готов» ⇔ valid (новый порядок).
$novye = [];
$starye = [];
foreach ((new Process())->find([], ['id']) as $id => $row) {
    $r = gotovnost((int) $id);
    $structured = ($row['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;
    if ($structured) {
        $valid = DefinitionSnapshot::valid(snimok((int) $id));
        $extra = array_filter($r['problems'], static fn($x) => !($x['snapshot'] ?? false));
        ok($r['ready'] === ($valid && !$extra), 'маршрут ' . $id . ': «' . ($r['ready'] ? 'готов' : 'не готов')
            . '» совпадает с проверкой запуска (valid=' . ($valid ? 'да' : 'нет') . ')', implode(' | ', teksty($r)));
        $novye[] = (int) $id;
    } else {
        $starye[] = (int) $id;
    }
    info('маршрут ' . $id . ': ' . $r['title'] . ($r['problems'] ? ' — ' . implode(' | ', teksty($r)) : ''));
}
ok($novye !== [] && $starye !== [], 'есть маршруты обоих порядков');

// Подопытный маршрут нового порядка: есть дочерняя, согласование, правило запуска.
$P = 0;
foreach ($novye as $pid) {
    $p = new Process();
    $p->getFromDB($pid);
    $modes = array_column($p->getStages(), 'execution_mode');
    if (in_array('delegated', $modes, true) && in_array('approval', $modes, true) && count($modes) >= 3
        && gotovnost($pid)['ready'] && gotovnost($pid)['rules'] !== []) {
        $P = $pid;
        break;
    }
}
ok($P > 0, 'найден готовый маршрут нового порядка с дочерней, согласованием и правилом');
if ($P === 0) {
    itog();
}
$pr = new Process();
$pr->getFromDB($P);
$etapy = $pr->getStages();
$del = array_values(array_filter($etapy, static fn($s) => $s['execution_mode'] === 'delegated'))[0];
$app = array_values(array_filter($etapy, static fn($s) => $s['execution_mode'] === 'approval'))[0];
$first = $etapy[0];
$last = $etapy[count($etapy) - 1];
$nazv = static fn(array $s): string => 'Этап ' . $s['ranking'] . ' «' . $s['name'] . '»';

/**
 * Порча в базе → ожидаемое сообщение → откат.
 *
 * @param callable $porcha        что испортить
 * @param string   $zhdem         начало сообщения (без хвоста)
 * @param bool     $snapshotLevel ломается ли и проверка запуска
 */
function porcha(string $imya, int $pid, callable $porcha, string $zhdem, ?int $etap, bool $snapshotLevel): void
{
    global $DB;
    $DB->beginTransaction();
    try {
        $porcha();
        $r = gotovnost($pid);
        $najdeno = null;
        foreach ($r['problems'] as $x) {
            if (str_starts_with($x['text'], $zhdem)) {
                $najdeno = $x;
                break;
            }
        }
        ok($najdeno !== null && !$r['ready'], $imya . ': «' . $zhdem . '…»', implode(' | ', teksty($r)));
        if ($najdeno !== null && $etap !== null) {
            ok(str_contains((string) ($najdeno['url'] ?? ''), 'stage.form.php?id=' . $etap), $imya . ': ссылка на этап');
        }
        if ($snapshotLevel) {
            ok(!DefinitionSnapshot::valid(snimok($pid)), $imya . ': проверка запуска тоже не пропускает');
        }
    } finally {
        $DB->rollBack();
    }
}

$T = Stage::getTable();
porcha('нет этапов', $P, static function () use ($DB, $T, $P): void {
    $DB->delete($T, ['plugin_itilflow_processes_id' => $P]);
}, 'Нет ни одного этапа.', null, true);

porcha('выключен', $P, static function () use ($DB, $P): void {
    $DB->update(Process::getTable(), ['is_active' => 0], ['id' => $P]);
}, 'Маршрут выключен — новые заявки его не запустят.', null, false);

porcha('нет исполнителя', $P, static function () use ($DB, $T, $del): void {
    $DB->update($T, ['groups_id' => 0, 'users_id' => 0], ['id' => $del['id']]);
}, $nazv($del) . ' — не выбран исполнитель: группа или сотрудник.', (int) $del['id'], true);

porcha('группа удалена', $P, static function () use ($DB, $T, $first): void {
    $DB->update($T, ['groups_id' => 2147483000, 'users_id' => 0, 'approver_source' => 'fixed'], ['id' => $first['id']]);
}, $nazv($first) . ' — не выбран исполнитель: группа или сотрудник.', (int) $first['id'], false);

porcha('нет организации', $P, static function () use ($DB, $T, $del): void {
    $DB->update($T, ['entity_strategy' => 'fixed', 'entities_id_target' => 2147483000], ['id' => $del['id']]);
}, $nazv($del) . ' — не выбрана организация для заявки отдела.', (int) $del['id'], false);

// Группа не видна в организации маршрута: группу переносим туда, где маршрута нет.
$pe = (int) $pr->fields['entities_id'];
$chuzhaya = null;
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'ORDER' => 'id']) as $e) {
    if ((int) $e['id'] !== $pe && !in_array((int) $e['id'], array_map('intval', getAncestorsOf('glpi_entities', $pe)), true)) {
        $chuzhaya = (int) $e['id'];
        break;
    }
}
porcha('группа не видна', $P, static function () use ($DB, $T, $del, $chuzhaya): void {
    $DB->update($T, ['entity_strategy' => 'inherit'], ['id' => $del['id']]);
    $DB->update('glpi_groups', ['entities_id' => $chuzhaya, 'is_recursive' => 0], ['id' => $del['groups_id']]);
}, $nazv($del) . ' — группа «', (int) $del['id'], false);

porcha('итог отдела не выбран', $P, static function () use ($DB, $T, $del): void {
    $DB->update($T, ['department_result_source' => ''], ['id' => $del['id']]);
}, $nazv($del) . ' — не выбрано, как отдел сообщает итог.', (int) $del['id'], true);

porcha('отказ не выбран', $P, static function () use ($DB, $T, $app): void {
    $DB->update($T, ['negative_policy' => ''], ['id' => $app['id']]);
}, $nazv($app) . ' — не выбрано, что делать при отказе.', (int) $app['id'], true);

porcha('точка возврата позже этапа', $P, static function () use ($DB, $T, $first, $last): void {
    $DB->update($T, ['return_targets' => '[' . (int) $last['id'] . ']'], ['id' => $first['id']]);
}, $nazv($first) . ' — точка возврата «' . $last['ranking'] . '. ' . $last['name'] . '» стоит позже этапа или удалена.', (int) $first['id'], true);

porcha('точка возврата удалена', $P, static function () use ($DB, $T, $first): void {
    $DB->update($T, ['return_targets' => '[2147483000]'], ['id' => $first['id']]);
}, $nazv($first) . ' — точка возврата «удалённый этап» стоит позже этапа или удалена.', (int) $first['id'], true);

porcha('сколько виз', $P, static function () use ($DB, $T, $app): void {
    $DB->update($T, ['approval_percent' => 0], ['id' => $app['id']]);
}, $nazv($app) . ' — сколько виз нужно: укажите от 1 до 100 %.', (int) $app['id'], true);

porcha('одинаковый порядок', $P, static function () use ($DB, $T, $etapy): void {
    $DB->update($T, ['ranking' => (int) $etapy[0]['ranking']], ['id' => $etapy[1]['id']]);
}, 'Этапы ' . $etapy[0]['ranking'] . ' и ' . $etapy[0]['ranking'] . ' — одинаковый порядок.', null, true);

// Правило описано там, где маршрут не виден.
$pravilo = $DB->request(['SELECT' => ['r.id', 'r.name'], 'FROM' => 'glpi_rules AS r',
    'INNER JOIN' => ['glpi_ruleactions AS a' => ['ON' => ['a' => 'rules_id', 'r' => 'id']]],
    'WHERE' => ['a.field' => '_plugin_itilflow_processes_id', 'a.value' => (string) $P, 'r.is_active' => 1],
    'LIMIT' => 1])->current();
porcha('правило не видит маршрут', $P, static function () use ($DB, $P, $pravilo, $chuzhaya): void {
    $DB->update(Process::getTable(), ['is_recursive' => 0], ['id' => $P]);
    $DB->update('glpi_rules', ['entities_id' => $chuzhaya, 'is_recursive' => 0], ['id' => $pravilo['id']]);
}, 'Правило «' . $pravilo['name'] . '» (организация «', null, false);

// Правило шире маршрута: и маршрут, и правило — в организации с подчинёнными,
// правило действует и в подчинённых, а маршрут там не виден.
$sDetmi = null;
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'ORDER' => 'id']) as $e) {
    if (count(getSonsOf('glpi_entities', (int) $e['id'])) > 1) {
        $sDetmi = (int) $e['id'];
        break;
    }
}
if ($sDetmi !== null) {
    porcha('правило шире маршрута', $P, static function () use ($DB, $P, $pravilo, $sDetmi): void {
        $DB->update(Process::getTable(), ['is_recursive' => 0, 'entities_id' => $sDetmi], ['id' => $P]);
        $DB->update('glpi_rules', ['entities_id' => $sDetmi, 'is_recursive' => 1], ['id' => $pravilo['id']]);
    }, 'Правило «' . $pravilo['name'] . '» работает и в подчинённых организациях', null, false);
} else {
    info('организаций с подчинёнными нет — случай «правило шире маршрута» не проверен');
}

// Прежний порядок: «Вернуться на этап» без этапа.
$L = 0;
$Ls = null;
foreach ($starye as $pid) {
    $p = new Process();
    $p->getFromDB($pid);
    foreach ($p->getStages() as $s) {
        if ($s['execution_mode'] === 'approval') { $L = $pid; $Ls = $s; break 2; }
    }
}
if ($L > 0) {
    porcha('вернуться без этапа', $L, static function () use ($DB, $T, $Ls): void {
        $DB->update($T, ['on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => 0], ['id' => $Ls['id']]);
    }, $nazv($Ls) . ' — «Вернуться на этап», но этап для возврата не выбран.', (int) $Ls['id'], false);
}

// Готов: строка с числом этапов и правилом; без правила — запуск вручную.
$r = gotovnost($P);
ok(str_starts_with($r['title'], 'Готов к запуску · ' . count($etapy) . ' ') && str_contains($r['title'], 'запускает правило «'),
    'готовый маршрут: «Готов к запуску · N этапов · запускает правило «…»»', $r['title']);
$DB->beginTransaction();
$DB->update('glpi_rules', ['is_active' => 0], ['id' => $pravilo['id']]);
$r = gotovnost($P);
ok($r['ready'] && $r['title'] === 'Готов к запуску · правила запуска нет: маршрут запускают вручную на вкладке заявки.',
    'без правила — готов, запуск вручную', $r['title']);
$DB->rollBack();

// Служебная строка: автоматическое действие выключено.
$zadacha = new CronTask();
if ($zadacha->getFromDBbyName('GlpiPlugin\\Itilflow\\Cron', 'itilflow_status_sync')) {
    $DB->beginTransaction();
    $DB->update(CronTask::getTable(), ['state' => CronTask::STATE_DISABLE], ['id' => $zadacha->getID()]);
    $DB->update($T, ['ticket_status' => 4], ['id' => $del['id']]);
    vojti($uid, 'glpi');
    $r = gotovnost($P);
    $s = array_values(array_filter($r['notes'], static fn($n) => $n['service']));
    ok($r['ready'] && count($s) === 1 && str_starts_with($s[0]['text'], 'Автоматическое действие itilflow_status_sync выключено'),
        'администратор GLPI видит служебную строку, маршрут готов', json_encode($r['notes'], JSON_UNESCAPED_UNICODE));
    vojti($uid, 'process');
    $r = gotovnost($P);
    $txt = implode(' ', array_column($r['notes'], 'text'));
    ok($r['ready'] && str_contains($txt, 'Статус заявки по этапам сейчас не меняется — сообщите администратору GLPI.')
        && !str_contains($txt, 'itilflow_status_sync'), 'администратор процессов видит «сообщите администратору GLPI»', $txt);
    $DB->rollBack();
    vojti($uid, 'glpi');
}

// Вывод: строка готовности на форме маршрута, над таблицей этапов и на этапе с проблемой.
$x = dom(vyvod(static function () use ($pr): void { $pr->showForm($pr->getID()); }));
ok($x->query('//*[@data-itilflow-readiness]')->length === 1, 'строка готовности на форме маршрута');
$x = dom(vyvod(static function () use ($pr): void { Stage::showForProcess($pr); }));
ok($x->query('//*[@data-itilflow-readiness]')->length === 1, 'строка готовности над таблицей этапов');
$DB->beginTransaction();
$DB->update($T, ['department_result_source' => ''], ['id' => $del['id']]);
$st = new Stage();
$st->getFromDB((int) $del['id']);
$x = dom(vyvod(static function () use ($st, $P): void { $st->showForm($st->getID(), ['plugin_itilflow_processes_id' => $P]); }));
$blok = $x->query('//*[@data-itilflow-readiness]')->item(0);
ok($blok !== null && str_contains($blok->textContent, 'не выбрано, как отдел сообщает итог'), 'на форме этапа — его недостающее');
$DB->rollBack();

itog();
