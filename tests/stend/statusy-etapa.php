<?php

/**
 * «Статус заявки на этапе» у нового этапа — по типу объекта маршрута.
 *
 * Маршрут для заявок, изменений и проблем; новый этап — как из формы:
 * форма предлагает статусы своего типа, front/stage.form.php делает check(-1)
 * и add() тем же объектом. Каждый предложенный статус сохраняется, и правка
 * сохранённого этапа на него — тоже. Всё — в транзакции проверки, откатывается.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;

global $DB;
vojti(adminGlpi(), 'process');

$g = $DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1],
    'ORDER' => 'id', 'LIMIT' => 1])->current();
ok($g !== null, 'есть группа-исполнитель');
if ($g === null) {
    itog();
}

foreach (['Ticket', 'Change', 'Problem'] as $itemtype) {
    // Маршрут — как из формы нового маршрута, с выбранным типом объекта.
    $x = dom(vyvod(static function (): void { (new Process())->showForm(-1); }));
    $input = kakBrauzer($x, '//form[@name="itilflow_process_form"]', ['name' => '[ТЕСТ 1.9] маршрут: ' . $itemtype,
        'entities_id' => (string) $g['entities_id'], 'itemtype' => $itemtype]);
    $p = new Process();
    $p->check(-1, CREATE, $input);
    $pid = (int) $p->add($input);
    $p->getFromDB($pid);
    ok($pid > 0 && $p->fields['itemtype'] === $itemtype, $itemtype . ': маршрут сохранён');
    if ($pid <= 0) {
        continue;
    }

    $statusy = array_diff_key(Stage::getStatusChoices($itemtype), [0 => true]);
    $x = dom(vyvod(static function () use ($pid): void { (new Stage())->showForm(-1, ['plugin_itilflow_processes_id' => $pid]); }));
    $vforme = [];
    foreach ($x->query('//form[@name="itilflow_stage_form"]//select[@name="ticket_status"]//option') as $o) {
        $vforme[] = (int) $o->getAttribute('value');
    }
    ok(array_diff(array_keys($statusy), $vforme) === [], $itemtype . ': форма нового этапа предлагает статусы своего типа',
        implode(',', $vforme));

    $ne = [];
    $pervyj = 0;
    foreach ($statusy as $status => $imya) {
        $x = dom(vyvod(static function () use ($pid): void { (new Stage())->showForm(-1, ['plugin_itilflow_processes_id' => $pid]); }));
        $input = kakBrauzer($x, '//form[@name="itilflow_stage_form"]', ['name' => '[ТЕСТ 1.9] этап «' . $imya . '»',
            'execution_mode' => 'inline', 'groups_id' => (string) $g['id'], 'ticket_status' => (string) $status]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        // Как front/stage.form.php: проверка права на новый объект, затем добавление тем же объектом.
        $st = new Stage();
        $st->check(-1, CREATE, $input);
        $id = (int) $st->add($input);
        $st = new Stage();
        if ($id <= 0 || !$st->getFromDB($id) || (int) $st->fields['ticket_status'] !== (int) $status) {
            $ne[] = $imya . ' (' . $status . '): ' . implode(' ', array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]])));
        }
        $pervyj = $pervyj ?: $id;
    }
    ok($ne === [], $itemtype . ': новый этап сохраняется с каждым статусом своего типа (' . count($statusy) . ')', implode('; ', $ne));

    // Правка сохранённого этапа на каждый статус.
    $ne = [];
    foreach ($statusy as $status => $imya) {
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        $st = new Stage();
        $st->check($pervyj, UPDATE);
        if (!$st->update(['id' => $pervyj, 'ticket_status' => (string) $status])) {
            $ne[] = $imya . ' (' . $status . ')';
        }
    }
    ok($pervyj > 0 && $ne === [], $itemtype . ': сохранённый этап принимает каждый статус своего типа', implode('; ', $ne));

    // Чужой и закрывающий статусы по-прежнему не проходят.
    $x = dom(vyvod(static function () use ($pid): void { (new Stage())->showForm(-1, ['plugin_itilflow_processes_id' => $pid]); }));
    $input = kakBrauzer($x, '//form[@name="itilflow_stage_form"]', ['name' => '[ТЕСТ 1.9] этап «Закрыто»',
        'execution_mode' => 'inline', 'groups_id' => (string) $g['id'], 'ticket_status' => (string) \CommonITILObject::CLOSED]);
    $st = new Stage();
    $st->check(-1, CREATE, $input);
    ok(!$st->add($input), $itemtype . ': статус «Закрыто» этапу не выставить');
}

itog();
