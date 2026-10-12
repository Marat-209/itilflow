<?php

/**
 * Вкладка «Этапы» маршрута показывает то, что действует.
 *
 * Столбцы: № · Этап · Как выполняется · Кто · Срок · Если отказ.
 * - «Если отказ» — по порядку маршрута: в новом — «отказ заявителю» или
 *   «остановить» и точки возврата номерами; в прежнем — «вернуть на N»,
 *   «остановить маршрут», «прервать маршрут»; у задачи — прочерк.
 * - Согласование «при прохождении» — «согласующий — при прохождении»,
 *   а не красное «не назначен».
 * - Срок — в рабочих днях или часах календаря маршрута.
 * - Строка этапа с проблемой из строки готовности помечена.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Deadline;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;

global $DB;
vojti(adminGlpi(), 'process');

function vkladka(Process $p): DOMXPath
{
    return dom(vyvod(static fn() => Stage::showForProcess($p)));
}

function yachejka(DOMXPath $x, int $sid, string $col): string
{
    $n = $x->query('//tr[@data-itilflow-stage="' . $sid . '"]/td[@data-itilflow-col="' . $col . '"]')->item(0);
    return $n === null ? '(нет ячейки)' : trim(preg_replace('/\s+/u', ' ', $n->textContent));
}

$oshibki = [];
$marshrutov = 0;
$P = null;
foreach ((new Process())->find([], ['id']) as $pid => $row) {
    $p = new Process();
    $p->getFromDB((int) $pid);
    $etapy = $p->getStages();
    if (!$etapy) {
        continue;
    }
    $marshrutov++;
    $novyj = ($row['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;
    if ($novyj && $P === null) {
        $P = $p;
    }
    $x = vkladka($p);
    $shapka = [];
    foreach ($x->query('//table//thead//th') as $th) {
        $shapka[] = trim($th->textContent);
    }
    if ($shapka !== ['№', 'Этап', 'Как выполняется', 'Кто', 'Срок', 'Если отказ']) {
        $oshibki[] = 'маршрут ' . $pid . ': столбцы ' . implode(' · ', $shapka);
    }
    if (str_contains($x->document->textContent, 'не назначен')) {
        $oshibki[] = 'маршрут ' . $pid . ': «не назначен»';
    }
    $po_id = [];
    foreach ($etapy as $s) {
        $po_id[(int) $s['id']] = $s;
    }
    $day = Deadline::dayMinutes((int) $p->fields['calendars_id']);
    foreach ($etapy as $s) {
        $sid = (int) $s['id'];
        $m = 'маршрут ' . $pid . ', этап ' . $s['ranking'];
        $kto = yachejka($x, $sid, 'who');
        if ($s['execution_mode'] === 'approval' && $s['approver_source'] !== 'fixed' && !str_contains($kto, 'согласующий — при прохождении')) {
            $oshibki[] = $m . ': «Кто» — ' . $kto;
        }
        if ($s['execution_mode'] === 'delegated' && !str_contains($kto, 'в организации')) {
            $oshibki[] = $m . ': у дочерней не сказано, в какой организации — ' . $kto;
        }
        $srok = yachejka($x, $sid, 'deadline');
        if ($srok !== Deadline::label((int) $s['deadline_minutes'], $day)) {
            $oshibki[] = $m . ': срок «' . $srok . '» вместо «' . Deadline::label((int) $s['deadline_minutes'], $day) . '»';
        }
        $otkaz = yachejka($x, $sid, 'reject');
        if ($s['execution_mode'] === 'inline') {
            $zhdem = '—';
        } elseif ($novyj) {
            $nomera = array_map(static fn(int $t): string => isset($po_id[$t]) ? (string) $po_id[$t]['ranking'] : '?',
                DefinitionSnapshot::targets($s['return_targets']) ?? []);
            $zhdem = ($s['negative_policy'] === 'abort' ? 'отказ заявителю' : 'остановить')
                . ' возврат: ' . ($nomera ? implode(', ', $nomera) : 'нет');
        } else {
            $zhdem = match ($s['on_reject']) {
                'back' => 'вернуть на ' . ($po_id[(int) $s['plugin_itilflow_stages_id_reject']]['ranking'] ?? '?'),
                'abort' => 'прервать маршрут',
                default => 'остановить маршрут',
            };
        }
        if ($otkaz !== $zhdem) {
            $oshibki[] = $m . ': «Если отказ» — «' . $otkaz . '» вместо «' . $zhdem . '»';
        }
    }
}
ok($marshrutov > 0, 'маршрутов с этапами: ' . $marshrutov);
ok($oshibki === [], 'столбцы, «Кто», срок и «Если отказ» по порядку маршрута',
    implode(PHP_EOL . '    ', array_slice($oshibki, 0, 15)));

// Строка этапа с проблемой помечается.
if ($P !== null) {
    $s = $P->getStages()[0];
    $DB->beginTransaction();
    $DB->update(Stage::getTable(), ['negative_policy' => ''], ['id' => $s['id']]);
    $x = vkladka($P);
    $tr = $x->query('//tr[@data-itilflow-stage="' . $s['id'] . '"]')->item(0);
    ok($tr !== null && str_contains($tr->getAttribute('class'), 'itilflow-problem')
        && str_contains($tr->textContent, 'не выбрано, что делать при отказе'), 'строка этапа с проблемой помечена');
    $drugie = $x->query('//tr[contains(@class,"itilflow-problem")]')->length;
    ok($drugie === 1, 'помечена только она', (string) $drugie);
    $DB->rollBack();
}

itog();
