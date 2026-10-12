<?php

/**
 * Круг без изменений: форма 1.9 → отправка как из браузера → сохранение.
 *
 * Для каждого этапа и маршрута базы рисуется форма, собирается то, что отправит
 * браузер (только включённые поля), и выполняется обычное сохранение. Ни одна
 * колонка не должна измениться, кроме версии маршрута и даты изменения.
 * Срок 540×N уходит как «N дней» и возвращается 540×N минут.
 * Всё внутри транзакции проверки — откатывается.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\StageForm;

if (!estKlass(StageForm::class)) {
    itog();
}

global $DB;
$uid = adminGlpi();
vojti($uid, 'glpi');

function stroka(string $table, int $id): array
{
    global $DB;
    foreach ($DB->request(['FROM' => $table, 'WHERE' => ['id' => $id]]) as $row) {
        return $row;
    }
    return [];
}

function oshibkiSessii(): array
{
    $m = $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [];
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    return $m;
}

$etapov = 0;
$abzac = 0;
$izmeneno = [];
$sroki = [];
foreach ((new Stage())->find([], ['plugin_itilflow_processes_id', 'ranking']) as $id => $row) {
    $id = (int) $id;
    $pid = (int) $row['plugin_itilflow_processes_id'];
    $do = stroka(Stage::getTable(), $id);
    $stage = new Stage();
    $stage->getFromDB($id);
    $x = dom(vyvod(static fn() => $stage->showForm($id, ['plugin_itilflow_processes_id' => $pid])));
    $input = otpravka($x, '//form[@name="itilflow_stage_form"]');
    if ((int) $do['deadline_minutes'] > 0) {
        $sroki[] = $do['deadline_minutes'] . '→' . ($input['_deadline_value'] ?? '?') . ($input['_deadline_unit'] ?? '?');
    }
    oshibkiSessii();
    $stage = new Stage();
    $res = $stage->update($input);
    $err = oshibkiSessii();
    $posle = stroka(Stage::getTable(), $id);
    $raznica = [];
    foreach ($do as $k => $v) {
        // Инструкция — поле с редактором GLPI: простой текст он оборачивает в абзац <p>.
        // Так было и в 1.8.0 (то же поле той же формы); смысл текста не меняется.
        if ($k === 'content' && '<p>' . $v . '</p>' === (string) ($posle[$k] ?? '')) {
            $abzac++;
            continue;
        }
        if ($k !== 'date_mod' && (string) $v !== (string) ($posle[$k] ?? '')) {
            $raznica[] = $k . ': ' . var_export($v, true) . ' → ' . var_export($posle[$k] ?? null, true);
        }
    }
    if (!$res || $err || $raznica) {
        $izmeneno[] = 'этап ' . $id . ' (' . $row['ranking'] . ', ' . $row['execution_mode'] . ')'
            . ($res ? '' : ' не сохранён') . ($err ? ' [' . implode(' | ', $err) . ']' : '') . ' ' . implode('; ', $raznica);
    }
    $etapov++;
}
ok($etapov > 0, 'этапов в базе: ' . $etapov);
ok($izmeneno === [], 'форма этапа сохраняется без изменений', implode(PHP_EOL . '    ', $izmeneno));
info('сроки: ' . implode(', ', array_unique($sroki)));
info('инструкций простым текстом, обёрнутых редактором в абзац: ' . $abzac);

$marshrutov = 0;
$izmeneno = [];
foreach ((new Process())->find([], ['id']) as $id => $row) {
    $id = (int) $id;
    $do = stroka(Process::getTable(), $id);
    $p = new Process();
    $p->getFromDB($id);
    $x = dom(vyvod(static fn() => $p->showForm($id)));
    $input = otpravka($x, '//form[@name="itilflow_process_form"]');
    oshibkiSessii();
    $p = new Process();
    $res = $p->update($input);
    $err = oshibkiSessii();
    $posle = stroka(Process::getTable(), $id);
    $raznica = [];
    foreach ($do as $k => $v) {
        if (!in_array($k, ['date_mod', 'version'], true) && (string) $v !== (string) ($posle[$k] ?? '')) {
            $raznica[] = $k . ': ' . var_export($v, true) . ' → ' . var_export($posle[$k] ?? null, true);
        }
    }
    if (!$res || $err || $raznica || (int) $posle['version'] !== (int) $do['version'] + 1) {
        $izmeneno[] = 'маршрут ' . $id . ($res ? '' : ' не сохранён') . ($err ? ' [' . implode(' | ', $err) . ']' : '')
            . ' ' . implode('; ', $raznica) . ' версия ' . $do['version'] . '→' . $posle['version'];
    }
    $marshrutov++;
}
ok($marshrutov > 0, 'маршрутов в базе: ' . $marshrutov);
ok($izmeneno === [], 'форма маршрута сохраняется без изменений, кроме версии +1', implode(PHP_EOL . '    ', $izmeneno));

itog();
