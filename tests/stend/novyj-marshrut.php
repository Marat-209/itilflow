<?php

/**
 * Новый маршрут с нуля — сразу в новом порядке исходов.
 *
 * Как человек: пустой маршрут из формы → согласование «при прохождении» →
 * дочерняя заявка отделу → задача «итог заявителю», всё на умолчаниях формы →
 * запуск на тестовой заявке. Ждём: маршрут сохраняется пустым, затем «Готов к
 * запуску», снимок определения, попытка 1, после визы — дочерняя заявка.
 * Умолчания нового этапа (решение 12.10): «Ответить заявителю отказом и
 * завершить маршрут», «Исполнитель отмечает результат», отмечен «этот этап».
 * Всё — в транзакции проверки, откатывается.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Attempt;
use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Readiness;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\Step;

global $DB;
$admin = adminGlpi();
vojti($admin, 'process');

function soobshch(): array
{
    $out = [];
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [] as $list) {
        foreach ($list as $m) {
            $out[] = (string) $m;
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    return $out;
}

// Группа-исполнитель и организация маршрута, где она видна.
$g = $DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1],
    'ORDER' => 'id', 'LIMIT' => 1])->current();
ok($g !== null, 'есть группа-исполнитель');
$E = (int) $g['entities_id'];

// 1. Пустой маршрут из формы.
$x = dom(vyvod(static function (): void { (new Process())->showForm(-1); }));
$input = kakBrauzer($x, '//form[@name="itilflow_process_form"]', ['name' => '[ТЕСТ 1.9] новый маршрут', 'entities_id' => (string) $E]);
ok(($input['outcome_contract'] ?? '') === DefinitionSnapshot::MODE, 'форма нового маршрута отправляет новый порядок');
soobshch();
$p = new Process();
$pid = (int) $p->add($input);
$m = soobshch();
ok($pid > 0, 'пустой маршрут нового порядка сохраняется', implode(' | ', $m));
$p->getFromDB($pid);
ok(($p->fields['outcome_contract'] ?? '') === DefinitionSnapshot::MODE && !$p->getStages(), 'маршрут в новом порядке, этапов нет');
$r = Readiness::check($p);
ok(!$r['ready'] && $r['problems'][0]['text'] === 'Нет ни одного этапа.', 'строка готовности: «Нет ни одного этапа.»', $r['title']);

// Сохранение пустого маршрута ещё раз — тоже проходит.
$x = dom(vyvod(static function () use ($p): void { $p->showForm($p->getID()); }));
$res = (new Process())->update(otpravka($x, '//form[@name="itilflow_process_form"]'));
ok((bool) $res, 'пустой маршрут нового порядка сохраняется и повторно', implode(' | ', soobshch()));

// 2. Этапы — из формы, на умолчаниях.
function novyjEtap(int $pid, array $izmenit): array
{
    $x = dom(vyvod(static function () use ($pid): void { (new Stage())->showForm(-1, ['plugin_itilflow_processes_id' => $pid]); }));
    $input = kakBrauzer($x, '//form[@name="itilflow_stage_form"]', $izmenit);
    soobshch();
    $st = new Stage();
    $id = (int) $st->add($input);
    $st = new Stage();
    $st->getFromDB($id);
    return [$id, $st->fields, soobshch(), $input];
}

[$s1, $f1, $m1] = novyjEtap($pid, ['name' => '[ТЕСТ 1.9] виза руководителя', 'execution_mode' => 'approval',
    'approver_source' => 'runtime', 'content' => 'Проверить заявку.']);
ok($s1 > 0, 'этап «согласование при прохождении» добавлен', implode(' | ', $m1));
ok(($f1['negative_policy'] ?? '') === 'abort', 'умолчание: «Ответить заявителю отказом и завершить маршрут»');
ok(($f1['return_targets'] ?? '') === '[' . $s1 . ']', 'умолчание: точка возврата — этот этап, номер подставлен', (string) ($f1['return_targets'] ?? ''));
ok((int) $f1['ticket_status'] === \CommonITILObject::APPROVAL, 'статус по режиму — «Согласование»', (string) $f1['ticket_status']);
ok((int) $f1['ranking'] === 10, 'номер первого этапа — 10', (string) $f1['ranking']);

[$s2, $f2, $m2] = novyjEtap($pid, ['name' => '[ТЕСТ 1.9] работа отдела', 'execution_mode' => 'delegated',
    'groups_id' => (string) $g['id'], 'content' => 'Сделать работу.']);
ok($s2 > 0, 'этап «дочерняя заявка» добавлен', implode(' | ', $m2));
ok(($f2['department_result_source'] ?? '') === 'assignee_action', 'умолчание: «Исполнитель отмечает результат»');
ok(($f2['negative_policy'] ?? '') === 'abort' && ($f2['return_targets'] ?? '') === '[' . $s2 . ']', 'умолчания отказа и точки возврата у дочерней');
ok((int) $f2['ticket_status'] === \CommonITILObject::WAITING, 'статус по режиму — «Ожидание»');

[$s3, $f3, $m3] = novyjEtap($pid, ['name' => '[ТЕСТ 1.9] итог заявителю', 'execution_mode' => 'inline',
    'groups_id' => (string) $g['id'], 'completion_comment' => 'required', 'content' => 'Ответить заявителю.']);
ok($s3 > 0, 'этап «задача — итог заявителю» добавлен', implode(' | ', $m3));
ok(($f3['negative_policy'] ?? '') === 'block', 'у задачи «что делать при отказе» заполнено само — «остановить»');
ok((int) $f3['ranking'] === 30, 'номера идут десятками', (string) $f3['ranking']);

$p->getFromDB($pid);
$r = Readiness::check($p);
ok($r['ready'] && $r['title'] === 'Готов к запуску · правила запуска нет: маршрут запускают вручную на вкладке заявки.',
    'строка готовности: «Готов к запуску», запуск вручную', $r['title'] . ' ' . implode(' | ', array_column($r['problems'], 'text')));
ok(DefinitionSnapshot::valid(['schema' => 1, 'process' => $p->fields, 'stages' => $p->getStages()]), 'проверка запуска пропускает');

// 3. Запуск на тестовой заявке.
vojti($admin, 'glpi');
$t = new Ticket();
$tid = (int) $t->add(['name' => '[ТЕСТ 1.9] заявка для нового маршрута', 'content' => 'Проверка запуска.',
    'entities_id' => $E, '_users_id_requester' => $admin, '_skip_rules' => true]);
ok($tid > 0, 'тестовая заявка создана');
$t->getFromDB($tid);
$instance = Engine::start($t, $pid);
ok($instance !== null, 'маршрут запущен', implode(' | ', soobshch()));
if ($instance !== null) {
    $instance->getFromDB($instance->getID());
    ok(DefinitionSnapshot::load($instance) !== null, 'снимок определения закреплён');
    $attempt = Attempt::current($instance);
    ok($attempt !== null && (int) $attempt->fields['number'] === 1, 'попытка 1');
    $step = $instance->getCurrentStep();
    ok($step !== null && (int) $step->fields['plugin_itilflow_stages_id'] === $s1, 'идёт этап согласования');
    // Исполнитель указывает согласующего, согласующий даёт визу — открывается дочерняя.
    $ok = $step !== null && Engine::designateApprover($step, [], [$admin], 'Проверка 1.9.', bin2hex(random_bytes(32)),
        (int) $attempt->getID());
    ok($ok, 'согласующий указан', implode(' | ', soobshch()));
    $viza = $DB->request(['FROM' => 'glpi_ticketvalidations', 'WHERE' => ['tickets_id' => $tid,
        'status' => \CommonITILValidation::WAITING], 'LIMIT' => 1])->current();
    ok($viza !== null, 'виза создана');
    if ($viza !== null) {
        // Как кнопка «Согласовать» в ленте: GLPI отправляет и того, кто отвечает.
        $v = new TicketValidation();
        $res = $v->update(['id' => $viza['id'], 'users_id_validate' => $admin,
            'status' => \CommonITILValidation::ACCEPTED, 'comment_validation' => 'Согласовано.']);
        ok((bool) $res, 'виза согласована', implode(' | ', soobshch()));
        $sh = $DB->request(['FROM' => Step::getTable(), 'WHERE' => ['plugin_itilflow_instances_id' => $instance->getID(),
            'plugin_itilflow_stages_id' => $s2], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        ok($sh !== null && $sh['artifact_itemtype'] === 'Ticket' && (int) $sh['artifact_items_id'] > 0,
            'дочерняя заявка отделу создана', json_encode($sh, JSON_UNESCAPED_UNICODE));
    }
}

itog();
