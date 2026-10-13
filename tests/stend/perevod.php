<?php

/**
 * «Перевести на новый порядок» на копии старого маршрута.
 *
 * Копия маршрута прежнего порядка с «Вернуться на этап» → convertToStructured():
 * поля этапов по таблице соответствия, проверка запуска пропускает, версия +1,
 * сам исходный маршрут и его начатые заявки не тронуты. На форме старого
 * маршрута — плашка с кнопкой и диалог: что будет заполнено, сколько начатых
 * доходят по прежнему. Всё — в транзакции проверки, откатывается.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;

global $DB;
vojti(adminGlpi(), 'process');

if (!method_exists(Process::class, 'convertToStructured')) {
    ok(false, 'есть Process::convertToStructured');
    itog();
}

function stroki(string $table, array $where): array
{
    global $DB;
    $out = [];
    foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
        $out[(int) $row['id']] = $row;
    }
    return $out;
}

// Старый маршрут с «Вернуться на этап» (если в базе таких нет — заводится в транзакции проверки).
$L = marshrutPrezhnegoPoryadka();
ok($L > 0, 'найден маршрут прежнего порядка с «Вернуться на этап»');
if ($L === 0) {
    itog();
}
$do_L = stroki(Process::getTable(), ['id' => $L]);
$do_Ls = stroki(Stage::getTable(), ['plugin_itilflow_processes_id' => $L]);
$do_Li = stroki(Instance::getTable(), ['plugin_itilflow_processes_id' => $L]);

// Копия: маршрут и этапы как есть, ссылки «вернуть на этап» — на копии этапов.
$src = $do_L[$L];
unset($src['id'], $src['date_creation'], $src['date_mod']);
$src['name'] = '[ТЕСТ 1.9] копия маршрута ' . $L;
$DB->insert(Process::getTable(), $src);
$C = (int) $DB->insertId();
$karta = [];
foreach ($do_Ls as $sid => $s) {
    unset($s['id']);
    $s['plugin_itilflow_processes_id'] = $C;
    $DB->insert(Stage::getTable(), $s);
    $karta[$sid] = (int) $DB->insertId();
}
foreach ($do_Ls as $sid => $s) {
    if ((int) $s['plugin_itilflow_stages_id_reject'] > 0) {
        $DB->update(Stage::getTable(), ['plugin_itilflow_stages_id_reject' => $karta[(int) $s['plugin_itilflow_stages_id_reject']] ?? 0],
            ['id' => $karta[$sid]]);
    }
}
$copy = new Process();
$copy->getFromDB($C);
$versiya = (int) $copy->fields['version'];

// Плашка и диалог на форме копии.
$x = dom(vyvod(static function () use ($copy): void { $copy->showForm($copy->getID()); }));
$plashka = $x->query('//*[@data-itilflow-legacy]')->item(0);
ok($plashka !== null && $x->query('//form[@name="itilflow_convert_form"]//button[@name="convert"]')->length === 1,
    'у старого маршрута — плашка и кнопка «Перевести на новый порядок»');
$tekst = $plashka?->textContent ?? '';
ok(str_contains($tekst, 'Задач') && str_contains($tekst, 'Проверить и поправить можно в каждом этапе.')
    && str_contains($tekst, 'Новые заявки пойдут по новому порядку.'), 'диалог перечисляет, что будет заполнено',
    trim(preg_replace('/\s+/u', ' ', $tekst)));

// Перевод.
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
$itog = $copy->convertToStructured();
$soob = array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]]));
ok($itog !== null, 'перевод выполнен', implode(' | ', $soob));
$copy->getFromDB($C);
ok(($copy->fields['outcome_contract'] ?? '') === DefinitionSnapshot::MODE, 'маршрут в новом порядке');
ok((int) $copy->fields['version'] === $versiya + 1, 'версия +1', $versiya . '→' . $copy->fields['version']);
ok(DefinitionSnapshot::valid(['schema' => 1, 'process' => $copy->fields, 'stages' => $copy->getStages()]), 'проверка запуска пропускает');
ok(implode(' ', $soob) !== '' && str_contains(implode(' ', $soob), 'Маршрут переведён на новый порядок.'), 'сообщение-сводка после перевода',
    implode(' | ', $soob));

$oshibki = [];
$posle = stroki(Stage::getTable(), ['plugin_itilflow_processes_id' => $C]);
foreach ($do_Ls as $sid => $s) {
    $n = $posle[$karta[$sid]];
    $m = 'этап ' . $s['ranking'];
    if ($s['execution_mode'] === 'inline') {
        $np = 'block';
        $rt = '[]';
    } else {
        $np = $s['on_reject'] === 'abort' ? 'abort' : 'block';
        $cel = (int) $s['plugin_itilflow_stages_id_reject'];
        $rt = $s['on_reject'] === 'back' && isset($do_Ls[$cel]) && (int) $do_Ls[$cel]['ranking'] <= (int) $s['ranking']
            ? '[' . $karta[$cel] . ']' : '[]';
    }
    if ($n['negative_policy'] !== $np) {
        $oshibki[] = $m . ': при отказе ' . $n['negative_policy'] . ' вместо ' . $np;
    }
    if ((string) $n['return_targets'] !== $rt) {
        $oshibki[] = $m . ': точки возврата ' . $n['return_targets'] . ' вместо ' . $rt;
    }
    if ($s['execution_mode'] === 'delegated' && $n['department_result_source'] !== 'assignee_action') {
        $oshibki[] = $m . ': итог отдела ' . $n['department_result_source'];
    }
    foreach (['name', 'ranking', 'execution_mode', 'groups_id', 'users_id', 'on_reject', 'deadline_minutes', 'ticket_status', 'content'] as $k) {
        if ((string) $n[$k] !== (string) $s[$k]) {
            $oshibki[] = $m . ': изменилось ' . $k;
        }
    }
}
ok($oshibki === [], 'поля этапов — по таблице соответствия, остальное не тронуто', implode('; ', $oshibki));

ok(stroki(Process::getTable(), ['id' => $L]) === $do_L, 'исходный маршрут не тронут');
ok(stroki(Stage::getTable(), ['plugin_itilflow_processes_id' => $L]) === $do_Ls, 'этапы исходного маршрута не тронуты');
ok(stroki(Instance::getTable(), ['plugin_itilflow_processes_id' => $L]) === $do_Li, 'начатые заявки исходного маршрута не тронуты');

// Повторный перевод нового маршрута ничего не делает.
ok($copy->convertToStructured() === null, 'маршрут нового порядка повторно не переводится');

// Кнопка — обычная отправка формы с проверкой права и ключа CSRF.
$front = (string) file_get_contents($GLOBALS['itilflow_koren'] . '/front/process.form.php');
ok(str_contains($front, "isset(\$_POST['convert'])") && str_contains($front, 'UPDATE') && str_contains($front, 'convertToStructured'),
    'действие «convert» в front/process.form.php — POST с правом изменения');

itog();
