<?php

/**
 * Понятные сообщения при сохранении.
 *
 * - Точка возврата позже этапа: раньше сохранение молча не проходило, теперь —
 *   сообщение с номерами.
 * - Удаление этапа, который стоит точкой возврата у других: этап убирается из
 *   их списков в той же транзакции, с сообщением; без «Обновите страницу».
 * - Вместо одной фразы на три поля — три сообщения словами формы.
 * - Если сохранение этапа ломает другой этап маршрута, называется причина,
 *   а не «Изменение определения не подтверждено».
 * Всё — в транзакции проверки, откатывается.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;

global $DB;
vojti(adminGlpi(), 'process');

function soobshcheniya(): array
{
    $out = [];
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [] as $type => $list) {
        foreach ($list as $m) {
            $out[] = [$type, (string) $m];
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    return $out;
}

function est(array $msgs, string $nachalo, ?int $type = null): bool
{
    foreach ($msgs as [$t, $m]) {
        if (str_starts_with($m, $nachalo) && ($type === null || $t === $type)) {
            return true;
        }
    }
    return false;
}

function obshchaya(array $msgs): bool
{
    foreach ($msgs as [, $m]) {
        if (str_contains($m, 'Обновите страницу') || str_contains($m, 'политику отказа')) {
            return true;
        }
    }
    return false;
}

// Подопытный маршрут нового порядка: этап-цель, на который ссылаются другие.
$P = null;
$cel = null;
foreach ((new Process())->find(['outcome_contract' => DefinitionSnapshot::MODE], ['id']) as $pid => $row) {
    $p = new Process();
    $p->getFromDB((int) $pid);
    $etapy = $p->getStages();
    foreach ($etapy as $t) {
        $ssylki = array_filter($etapy, static fn($s) => (int) $s['id'] !== (int) $t['id']
            && in_array((int) $t['id'], DefinitionSnapshot::targets($s['return_targets']) ?? [], true));
        if (count($etapy) >= 3 && $ssylki) {
            $P = $p;
            $cel = $t;
            break 2;
        }
    }
}
ok($P !== null, 'найден маршрут нового порядка с этапом — точкой возврата других');
if ($P === null) {
    itog();
}
$etapy = $P->getStages();
$po_id = array_column($etapy, null, 'id');
$first = $etapy[0];
$last = $etapy[count($etapy) - 1];
$del = array_values(array_filter($etapy, static fn($s) => $s['execution_mode'] === 'delegated'))[0] ?? null;
$app = array_values(array_filter($etapy, static fn($s) => $s['execution_mode'] === 'approval'))[0] ?? null;

// 1. Точка возврата позже этапа.
$DB->beginTransaction();
soobshcheniya();
$st = new Stage();
$res = $st->update(['id' => $first['id'], '_structured_return_targets_present' => 1,
    '_structured_return_targets' => [(string) $last['id']]]);
$m = soobshcheniya();
ok($res === false && est($m, sprintf('Точка возврата «%d. %s» стоит позже этого этапа (%d)', $last['ranking'], $last['name'], $first['ranking']), ERROR),
    'точка возврата позже этапа — сообщение с номерами', json_encode($m, JSON_UNESCAPED_UNICODE));
$DB->rollBack();

// 2. Точка возврата не из этого маршрута.
$DB->beginTransaction();
$st = new Stage();
$res = $st->update(['id' => $first['id'], '_structured_return_targets_present' => 1, '_structured_return_targets' => ['2147483000']]);
$m = soobshcheniya();
ok($res === false && est($m, 'Точка возврата не найдена: этап удалён или из другого маршрута.', ERROR),
    'чужая или удалённая точка возврата — сообщение', json_encode($m, JSON_UNESCAPED_UNICODE));
$DB->rollBack();

// 3. Три поля — три сообщения.
if ($del !== null) {
    $DB->beginTransaction();
    $st = new Stage();
    $res = $st->update(['id' => $del['id'], 'department_result_source' => '']);
    $m = soobshcheniya();
    ok($res === false && est($m, 'Выберите, как отдел сообщает итог: без этого маршрут нельзя запустить.', ERROR) && !obshchaya($m),
        'итог отдела не выбран — своё сообщение', json_encode($m, JSON_UNESCAPED_UNICODE));
    $DB->rollBack();
}
if ($app !== null) {
    $DB->beginTransaction();
    $st = new Stage();
    $res = $st->update(['id' => $app['id'], 'negative_policy' => '']);
    $m = soobshcheniya();
    ok($res === false && est($m, 'Выберите, что делать при отказе: без этого маршрут нельзя запустить.', ERROR) && !obshchaya($m),
        'отказ не выбран — своё сообщение', json_encode($m, JSON_UNESCAPED_UNICODE));
    $DB->rollBack();
}

// Сообщения формы — словами формы и русского перевода GLPI: исполнитель, сотрудник, организация.
$DB->beginTransaction();
$st = new Stage();
$res = $st->update(['id' => $first['id'], 'execution_mode' => 'inline', 'groups_id' => 0, 'users_id' => 0]);
$m = soobshcheniya();
ok($res === false && est($m, 'Выберите исполнителя этапа: группу или сотрудника.', ERROR),
    'нет исполнителя — словами формы', json_encode($m, JSON_UNESCAPED_UNICODE));
$DB->rollBack();
if ($del !== null) {
    $DB->beginTransaction();
    $st = new Stage();
    $res = $st->update(['id' => $del['id'], 'entity_strategy' => 'by_group', 'groups_id' => 0, 'users_id' => adminGlpi()]);
    $m = soobshcheniya();
    ok($res === false && est($m, 'Где создать заявку отдела — «В организации группы»: выберите отдел (группу).', ERROR),
        '«В организации группы» без группы — словами формы, без «сущности»', json_encode($m, JSON_UNESCAPED_UNICODE));
    $DB->rollBack();
}
$DB->beginTransaction();
$st = new Stage();
$res = $st->update(['id' => $first['id'], '_structured_return_targets_present' => 1, '_structured_return_targets' => ['abc']]);
$m = soobshcheniya();
ok($res === false && est($m, 'Точки возврата заданы неверно — отметьте их заново.', ERROR),
    'неверные точки возврата — своё сообщение', json_encode($m, JSON_UNESCAPED_UNICODE));
$DB->rollBack();

// 4. Удаление этапа — точки возврата других.
$DB->beginTransaction();
$ssylayutsya = [];
foreach ($etapy as $s) {
    if ((int) $s['id'] !== (int) $cel['id'] && in_array((int) $cel['id'], DefinitionSnapshot::targets($s['return_targets']) ?? [], true)) {
        $ssylayutsya[] = (int) $s['ranking'];
    }
}
$st = new Stage();
$res = $st->delete(['id' => $cel['id']], true);
$m = soobshcheniya();
$ostalos = [];
foreach ((new Stage())->find(['plugin_itilflow_processes_id' => $P->getID()]) as $s) {
    if (in_array((int) $cel['id'], DefinitionSnapshot::targets($s['return_targets']) ?? [], true)) {
        $ostalos[] = $s['ranking'];
    }
}
ok((bool) $res && !obshchaya($m), 'этап — точка возврата удаляется без «Обновите страницу»', json_encode($m, JSON_UNESCAPED_UNICODE));
ok($ostalos === [], 'удалённый этап убран из точек возврата других', implode(',', $ostalos));
ok(est($m, sprintf('Этап %d «%s» удалён и убран из точек возврата этапов %s.', $cel['ranking'], $cel['name'], implode(', ', $ssylayutsya))),
    'сообщение называет этапы, у которых он был точкой возврата', json_encode($m, JSON_UNESCAPED_UNICODE));
$p2 = new Process();
$p2->getFromDB($P->getID());
ok(DefinitionSnapshot::valid(['schema' => 1, 'process' => $p2->fields, 'stages' => $p2->getStages()]), 'маршрут после удаления проходит проверку запуска');
$DB->rollBack();

// 5. Сохранение этапа ломает другой этап: называется причина, а не общая фраза.
$DB->beginTransaction();
$ssylka = null;
foreach ($etapy as $s) {
    foreach (DefinitionSnapshot::targets($s['return_targets']) ?? [] as $t) {
        if ($t !== (int) $s['id'] && isset($po_id[$t])) { $ssylka = [$s, $po_id[$t]]; break 2; }
    }
}
if ($ssylka !== null) {
    [$s, $t] = $ssylka;
    $st = new Stage();
    $res = $st->update(['id' => $t['id'], 'ranking' => (int) $last['ranking'] + 100]);
    $m = soobshcheniya();
    ok($res === false && !obshchaya($m) && est($m, sprintf('Этап %d «%s» — точка возврата «%d. %s» стоит позже этапа или удалена.',
        $s['ranking'], $s['name'], (int) $last['ranking'] + 100, $t['name'])),
        'перенос этапа, на который ссылаются, — причина с номерами', json_encode($m, JSON_UNESCAPED_UNICODE));
}
$DB->rollBack();

itog();
