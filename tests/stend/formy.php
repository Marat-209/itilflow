<?php

/**
 * Формы этапа и маршрута, как их увидит человек.
 *
 * Для первого маршрута каждого порядка исходов рисуется форма каждого этапа
 * в каждом из трёх режимов — от администратора GLPI и от администратора
 * процессов без права «Настройка». Проверяется:
 *  - видны поля режима и порядка (правила StageForm), чужие скрыты и отключены;
 *  - поля другого порядка исходов не выводятся вовсе;
 *  - «Дополнительно» свёрнуто и со строкой-сводкой;
 *  - служебная строка про автоматическое действие — только администратору GLPI;
 *  - в списке точек возврата — только этот этап и предыдущие;
 *  - новые подписи вместо прежних служебных слов.
 * Форма маршрута: шесть основных полей и «Дополнительно», у старого маршрута —
 * плашка «Перевести на новый порядок», у нового выбора порядка нет.
 * Режим меняется в базе внутри транзакции проверки и откатывается.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\StageForm;

if (!estKlass(StageForm::class)) {
    itog();
}

const STARYE_SLOVA = ['Организационная единица', 'сущност', 'Целевая', 'Режим исполнения', 'Персональный ответственный',
    'Норматив', 'Обработка исходов', 'Источник исхода', 'Отрицательный исход', 'Допустимые точки возврата',
    'CLI cron', 'экземпляр', 'синхронный вызов', 'инициатор'];

$uid = adminGlpi();
ok($uid > 0, 'найден администратор GLPI для входа');

marshrutPrezhnegoPoryadka();
$marshruty = ['new' => 0, 'legacy' => 0];
foreach ((new Process())->find([], ['id']) as $row) {
    $k = ($row['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE ? 'new' : 'legacy';
    if ($marshruty[$k] === 0 && count((new Stage())->find(['plugin_itilflow_processes_id' => $row['id']])) > 0) {
        $marshruty[$k] = (int) $row['id'];
    }
}
ok($marshruty['new'] > 0 && $marshruty['legacy'] > 0, 'есть маршрут нового и прежнего порядка с этапами',
    json_encode($marshruty));

function formaEtapa(int $id, int $pid): string
{
    $stage = new Stage();
    $stage->getFromDB($id);
    return vyvod(static fn() => $stage->showForm($id, ['plugin_itilflow_processes_id' => $pid]));
}

function vidimye(DOMXPath $x): array
{
    $out = [];
    foreach ($x->query('//*[@data-itilflow-field]') as $el) {
        if (!skryt($el)) {
            $out[] = $el->getAttribute('data-itilflow-field');
        }
    }
    return $out;
}

/** Подписи и подсказки формы — без названий этапов и текстов, которые ввели люди. */
function podpisi(DOMXPath $x): string
{
    $out = [];
    foreach ($x->query('//label[contains(@class,"col-form-label")] | //*[contains(@class,"itilflow-hint")]'
        . ' | //div[contains(@class,"collapse") and contains(@class,"form-text")] | //*[contains(@class,"alert")]'
        . ' | //*[contains(@class,"card-subtitle")] | //span[contains(@class,"fw-semibold")]'
        . ' | //span[contains(@class,"text-muted") and contains(@class,"small")]') as $n) {
        $out[] = $n->textContent;
    }
    return implode(' ', $out);
}

global $DB;
foreach (['glpi', 'process'] as $kak) {
    vojti($uid, $kak);
    foreach ($marshruty as $poryadok => $pid) {
        $novyj = $poryadok === 'new';
        $process = new Process();
        $process->getFromDB($pid);
        $etapy = $process->getStages();
        $oshibki = [];
        foreach ($etapy as $s) {
            foreach (['inline', 'delegated', 'approval'] as $mode) {
                $DB->update(Stage::getTable(), ['execution_mode' => $mode], ['id' => $s['id']]);
                $html = formaEtapa((int) $s['id'], $pid);
                $x = dom($html);
                $f = $s;
                $f['execution_mode'] = $mode;
                $zhdali = StageForm::visibleFields($f, $novyj, (string) $process->fields['itemtype']);
                $zhdali = array_merge($zhdali['main'], $zhdali['more']);
                $vid = vidimye($x);
                sort($vid);
                sort($zhdali);
                $metka = $kak . '/' . $poryadok . '/этап ' . $s['ranking'] . '/' . $mode;
                if ($vid !== $zhdali) {
                    $oshibki[] = $metka . ': видны ' . implode(',', $vid) . ' вместо ' . implode(',', $zhdali);
                }
                // Скрытые отключены, видимые — нет.
                foreach ($x->query('//*[@data-itilflow-field]//input | //*[@data-itilflow-field]//select | //*[@data-itilflow-field]//textarea') as $el) {
                    if ($el->getAttribute('type') === 'hidden' && $el->getAttribute('name') === '') {
                        continue;
                    }
                    if (skryt($el) !== $el->hasAttribute('disabled')) {
                        $oshibki[] = $metka . ': поле ' . $el->getAttribute('name') . (skryt($el) ? ' скрыто, но не отключено' : ' видно, но отключено');
                    }
                }
                // Поля другого порядка не выводятся.
                $chuzhie = $novyj ? ['on_reject', 'plugin_itilflow_stages_id_reject'] : ['department_result_source', 'negative_policy', 'return_targets', 'return_targets_retry'];
                foreach ($chuzhie as $c) {
                    if ($x->query('//*[@data-itilflow-field="' . $c . '"]')->length > 0) {
                        $oshibki[] = $metka . ': выведено поле другого порядка ' . $c;
                    }
                }
                // Режим — три кнопки.
                $knopki = $x->query('//input[@type="radio" and @name="execution_mode"]');
                $vybran = $x->query('//input[@type="radio" and @name="execution_mode" and @checked]');
                if ($knopki->length !== 3 || $vybran->length !== 1 || $vybran->item(0)->getAttribute('value') !== $mode) {
                    $oshibki[] = $metka . ': режим не тремя кнопками';
                }
                // Точки возврата — этот и предыдущие.
                foreach ($x->query('//input[@name="_structured_return_targets[]"]') as $cb) {
                    $t = new Stage();
                    if ($t->getFromDB((int) $cb->getAttribute('value')) && (int) $t->fields['ranking'] > (int) $s['ranking']) {
                        $oshibki[] = $metka . ': в точках возврата более поздний этап ' . $t->fields['ranking'];
                    }
                }
                // «Дополнительно» свёрнуто, со сводкой.
                $dop = $x->query('//*[@data-itilflow-more]');
                if ($dop->length !== 1 || str_contains(' ' . $dop->item(0)->getAttribute('class') . ' ', ' show ')
                    || $x->query('//*[@data-itilflow-summary]')->length !== 1) {
                    $oshibki[] = $metka . ': «Дополнительно» не свёрнуто или без сводки';
                }
                // Служебное — только администратору GLPI и только в новом порядке.
                $sluzh = $x->query('//*[@data-itilflow-service]')->length > 0;
                if ($sluzh !== ($kak === 'glpi' && $novyj)) {
                    $oshibki[] = $metka . ': служебная строка ' . ($sluzh ? 'видна' : 'не видна');
                }
                $tekst = podpisi($x);
                foreach (STARYE_SLOVA as $slovo) {
                    if (mb_stripos($tekst, $slovo) !== false) {
                        $oshibki[] = $metka . ': прежнее слово «' . $slovo . '»';
                    }
                }
                if ($kak === 'process' && str_contains($tekst, 'itilflow_status_sync')) {
                    $oshibki[] = $metka . ': служебное имя видно администратору процессов';
                }
            }
            $DB->update(Stage::getTable(), ['execution_mode' => $s['execution_mode']], ['id' => $s['id']]);
        }
        ok($oshibki === [], $kak . ': формы этапов маршрута ' . ($novyj ? 'нового' : 'прежнего') . ' порядка, '
            . count($etapy) . ' этапов × 3 режима', implode(PHP_EOL . '    ', array_slice(array_unique($oshibki), 0, 12)));
    }
}

// Новый этап в маршруте нового порядка: умолчания решения 12.10.
vojti($uid, 'process');
$html = vyvod(static function () use ($marshruty): void {
    $stage = new Stage();
    $stage->showForm(-1, ['plugin_itilflow_processes_id' => $marshruty['new']]);
});
$x = dom($html);
$checked = static fn(string $name): string => (string) ($x->query('//input[@name="' . $name . '" and @checked]')->item(0)?->getAttribute('value') ?? '');
ok($checked('negative_policy') === 'abort', 'новый этап: «Ответить заявителю отказом и завершить маршрут»');
ok($checked('department_result_source') === 'assignee_action', 'новый этап: «Исполнитель отмечает результат»');
ok($x->query('//input[@name="_structured_return_targets[]" and @value="self" and @checked]')->length >= 1,
    'новый этап: отмечен «этот этап»');
ok($checked('execution_mode') === 'inline', 'новый этап: режим «Задача в заявке»');
$st = $x->query('//select[@name="ticket_status"]/option[@selected]')->item(0)?->getAttribute('value');
ok($st === '2', 'новый этап: статус по режиму задачи — «В работе (назначена)»', (string) $st);
ok($x->query('//*[@data-itilflow-status-by-mode]')->length === 1, 'новый этап: статус по режиму подставляет форма');
$rank = (int) ($x->query('//input[@name="ranking"]')->item(0)?->getAttribute('value') ?? 0);
$max = 0;
foreach ((new Stage())->find(['plugin_itilflow_processes_id' => $marshruty['new']]) as $r) {
    $max = max($max, (int) $r['ranking']);
}
ok($rank > $max, 'новый этап: порядок — после последнего', $rank . ' при последнем ' . $max);
ok(str_contains($x->document->textContent, 'этот этап'), 'новый этап: «этот этап» в списке');

// Срок: дни календаря маршрута.
$s = (new Process());
$s->getFromDB($marshruty['new']);
$sdl = null;
foreach ($s->getStages() as $row) {
    if ((int) $row['deadline_minutes'] > 0) { $sdl = $row; break; }
}
if ($sdl !== null) {
    $x = dom(formaEtapa((int) $sdl['id'], $marshruty['new']));
    $day = \GlpiPlugin\Itilflow\Deadline::dayMinutes((int) $s->fields['calendars_id']);
    [$v, $u] = \GlpiPlugin\Itilflow\Deadline::split((int) $sdl['deadline_minutes'], $day);
    ok($x->query('//input[@name="_deadline_value"]')->item(0)?->getAttribute('value') === $v
        && $x->query('//select[@name="_deadline_unit"]/option[@selected]')->item(0)?->getAttribute('value') === $u,
        'срок показан как ' . $v . ' ' . $u . ' (' . $sdl['deadline_minutes'] . ' мин, день ' . $day . ')');
    ok($x->query('//input[@name="deadline_minutes"]')->length === 0, 'поля «минут» в форме нет');
}

// Форма маршрута.
function formaMarshruta(int $id): DOMXPath
{
    $p = new Process();
    if ($id > 0) {
        $p->getFromDB($id);
    }
    return dom(vyvod(static fn() => $p->showForm($id)));
}
foreach (['glpi', 'process'] as $kak) {
    vojti($uid, $kak);
    foreach ($marshruty as $poryadok => $pid) {
        $x = formaMarshruta($pid);
        $vid = vidimye($x);
        $osn = [];
        $dop = [];
        foreach ($x->query('//*[@data-itilflow-field]') as $el) {
            $in = false;
            for ($n = $el->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
                if ($n->hasAttribute('data-itilflow-more')) { $in = true; break; }
            }
            $in ? $dop[] = $el->getAttribute('data-itilflow-field') : $osn[] = $el->getAttribute('data-itilflow-field');
        }
        ok($osn === ['name', 'entities_id', 'is_recursive', 'calendars_id', 'is_active', 'comment'],
            $kak . '/' . $poryadok . ': шесть основных полей маршрута', implode(',', $osn));
        ok($dop === ['itemtype', 'enforcement', 'block_resolution', 'public_log', 'helpdesk_tab', 'allow_withdraw', 'version'],
            $kak . '/' . $poryadok . ': «Дополнительно» на семь', implode(',', $dop));
        $more = $x->query('//*[@data-itilflow-more]')->item(0);
        ok($more !== null && !str_contains(' ' . $more->getAttribute('class') . ' ', ' show ')
            && $x->query('//*[@data-itilflow-summary]')->length === 1, $kak . '/' . $poryadok . ': «Дополнительно» свёрнуто со сводкой');
        ok($x->query('//select[@name="outcome_contract"] | //input[@name="outcome_contract"]')->length === 0,
            $kak . '/' . $poryadok . ': выбора порядка исходов нет');
        $plashka = $x->query('//*[@data-itilflow-legacy]')->length === 1
            && $x->query('//form[@name="itilflow_convert_form"]//button[@name="convert"]')->length === 1;
        ok($plashka === ($poryadok === 'legacy'), $kak . '/' . $poryadok . ': плашка «Перевести на новый порядок» '
            . ($poryadok === 'legacy' ? 'есть' : 'нет'));
        if ($poryadok === 'legacy') {
            // У alert в Tabler display:flex: всё содержимое плашки — в одной оболочке,
            // иначе раскрытый диалог встаёт справа от текста, а не под ним.
            $deti = $x->query('//*[@data-itilflow-legacy]/*');
            ok($deti->length === 1 && $x->query('//*[@data-itilflow-legacy]/div/form[@name="itilflow_convert_form"]'
                . ' | //*[@data-itilflow-legacy]/div//div[contains(@class,"collapse")]//form[@name="itilflow_convert_form"]')->length === 1,
                $kak . ': содержимое плашки — одним блоком, диалог под текстом', (string) $deti->length);
        }
        ok($x->query('//*[@data-itilflow-readiness]')->length === 1, $kak . '/' . $poryadok . ': строка готовности на форме');
        $tekst = podpisi($x);
        $slova = array_filter(['Организационная единица', 'Виден в подчинённых единицах', 'Режим контроля', 'инициатор',
            'Обработка исходов', 'Версия определения'], static fn($w) => mb_stripos($tekst, $w) !== false);
        ok($slova === [], $kak . '/' . $poryadok . ': новые подписи вместо прежних', implode(', ', $slova));
    }
}
vojti($uid, 'process');
$x = formaMarshruta(-1);
ok($x->query('//input[@type="hidden" and @name="outcome_contract" and @value="structured_v1"]')->length === 1,
    'новый маршрут создаётся сразу в новом порядке');
ok($x->query('//select[@name="outcome_contract"]')->length === 0, 'у нового маршрута выбора порядка нет');
ok($x->query('//*[@data-itilflow-field="version"]')->length === 0, 'у нового маршрута версии нет');
ok($x->query('//*[@data-itilflow-legacy]')->length === 0, 'у нового маршрута плашки нет');

// Режим контроля: новые подписи видны и на вкладке заявки администратору.
ok(Process::getEnforcementLabels() === [
    'audit' => 'Журнал — разрешить и записать',
    'warn' => 'Предупреждение — разрешить с предупреждением',
    'strict' => 'Запрет — не дать сделать',
], 'подписи режима контроля');

itog();
