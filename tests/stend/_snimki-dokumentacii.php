<?php

/**
 * HTML для снимков документации — на вымышленных данных, без следа в базе.
 *
 * Внутри транзакции проверки заводятся вымышленная организация, группы,
 * календарь «8/5», категория, правило запуска, маршрут нового порядка с тремя
 * этапами и маршрут прежнего порядка. Рисуются форма маршрута, форма этапа,
 * вкладка «Этапы» и плашка старого маршрута; HTML пишется в /tmp/itilflow-snimki
 * со стилями GLPI с адреса ITILFLOW_GLPI_URL. Всё откатывается.
 * Запуск: sh tests/zapusk-stend.sh _snimki-dokumentacii, затем снимки — безголовым Chrome.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;

global $DB;
vojti(adminGlpi(), 'process');
$base = getenv('ITILFLOW_GLPI_URL') ?: 'http://localhost';
$out = '/tmp/itilflow-snimki';
@mkdir($out, 0777, true);

$vstavit = static function (string $table, array $row): int {
    global $DB;
    $DB->insert($table, $row);
    return (int) $DB->insertId();
};

$E = $vstavit('glpi_entities', ['name' => 'Служба поддержки', 'entities_id' => 0, 'level' => 2,
    'completename' => 'Головная организация > Служба поддержки']);
$g1 = $vstavit('glpi_groups', ['name' => 'Первая линия', 'completename' => 'Первая линия', 'entities_id' => $E,
    'is_recursive' => 1, 'is_assign' => 1, 'level' => 1]);
$g2 = $vstavit('glpi_groups', ['name' => 'Отдел исполнения', 'completename' => 'Отдел исполнения', 'entities_id' => $E,
    'is_recursive' => 1, 'is_assign' => 1, 'level' => 1]);
$cal = $vstavit('glpi_calendars', ['name' => '8/5', 'entities_id' => $E, 'is_recursive' => 1]);
foreach ([1, 2, 3, 4, 5] as $d) {
    $vstavit('glpi_calendarsegments', ['calendars_id' => $cal, 'entities_id' => $E, 'is_recursive' => 1,
        'day' => $d, 'begin' => '08:00:00', 'end' => '17:00:00']);
}
$cat = $vstavit('glpi_itilcategories', ['name' => 'Этап маршрута', 'completename' => 'Этап маршрута', 'entities_id' => $E,
    'is_recursive' => 1, 'level' => 1, 'is_helpdeskvisible' => 1, 'is_incident' => 1, 'is_request' => 1]);

$P = $vstavit(Process::getTable(), ['name' => 'Запрос с согласованием', 'entities_id' => $E, 'is_recursive' => 1,
    'is_active' => 1, 'itemtype' => 'Ticket', 'enforcement' => 'audit', 'version' => 7, 'block_resolution' => 1,
    'public_log' => 1, 'helpdesk_tab' => 1, 'allow_withdraw' => 1, 'calendars_id' => $cal,
    'outcome_contract' => 'structured_v1',
    'comment' => 'Запрос с согласованием руководителя: согласование, работа отдела, итог заявителю.']);
$s1 = $vstavit(Stage::getTable(), ['plugin_itilflow_processes_id' => $P, 'name' => 'Согласование руководителя', 'ranking' => 10,
    'execution_mode' => 'approval', 'approver_source' => 'runtime', 'deadline_minutes' => 540, 'ticket_status' => 10,
    'negative_policy' => 'abort', 'return_targets' => '[]', 'content' => 'Проверьте, что запрос обоснован.']);
$DB->update(Stage::getTable(), ['return_targets' => '[' . $s1 . ']'], ['id' => $s1]);
$s2 = $vstavit(Stage::getTable(), ['plugin_itilflow_processes_id' => $P, 'name' => 'Исполнение отделом', 'ranking' => 20,
    'execution_mode' => 'delegated', 'groups_id' => $g2, 'entity_strategy' => 'inherit', 'entities_id_target' => $E, 'itilcategories_id' => $cat,
    'deadline_minutes' => 1620, 'ticket_status' => 4, 'department_result_source' => 'assignee_action',
    'negative_policy' => 'abort', 'return_targets' => '[]', 'content' => 'Выполните работу и опишите результат для заявителя.']);
$DB->update(Stage::getTable(), ['return_targets' => '[' . $s1 . ',' . $s2 . ']'], ['id' => $s2]);
$s3 = $vstavit(Stage::getTable(), ['plugin_itilflow_processes_id' => $P, 'name' => 'Итог заявителю', 'ranking' => 30,
    'execution_mode' => 'inline', 'groups_id' => $g1, 'completion_comment' => 'required', 'deadline_minutes' => 360,
    'ticket_status' => 2, 'negative_policy' => 'block', 'return_targets' => '[]', 'content' => 'Сформулируйте ответ заявителю.']);
$rule = $vstavit('glpi_rules', ['entities_id' => $E, 'is_recursive' => 1, 'sub_type' => 'RuleTicket', 'ranking' => 1,
    'name' => 'Запуск: запрос с согласованием', 'match' => 'AND', 'is_active' => 1, 'condition' => 1]);
$vstavit('glpi_ruleactions', ['rules_id' => $rule, 'action_type' => 'assign', 'field' => '_plugin_itilflow_processes_id',
    'value' => (string) $P]);

$L = $vstavit(Process::getTable(), ['name' => 'Работа внутри отдела', 'entities_id' => $E, 'is_recursive' => 1,
    'is_active' => 1, 'itemtype' => 'Ticket', 'enforcement' => 'audit', 'version' => 3, 'block_resolution' => 1,
    'public_log' => 1, 'helpdesk_tab' => 1, 'allow_withdraw' => 1, 'calendars_id' => $cal, 'outcome_contract' => 'legacy']);
$l1 = $vstavit(Stage::getTable(), ['plugin_itilflow_processes_id' => $L, 'name' => 'Разбор заявки', 'ranking' => 10,
    'execution_mode' => 'inline', 'groups_id' => $g1, 'deadline_minutes' => 540]);
$vstavit(Stage::getTable(), ['plugin_itilflow_processes_id' => $L, 'name' => 'Согласование работ', 'ranking' => 20,
    'execution_mode' => 'approval', 'groups_id' => $g1, 'on_reject' => 'back', 'plugin_itilflow_stages_id_reject' => $l1]);
$vstavit(Stage::getTable(), ['plugin_itilflow_processes_id' => $L, 'name' => 'Выполнение работ', 'ranking' => 30,
    'execution_mode' => 'inline', 'groups_id' => $g2, 'deadline_minutes' => 1080, 'completion_comment' => 'required']);

// Новая организация — в список доступных сессии (кэш подчинённых GLPI о ней не знает).
$_SESSION['glpiactiveentities'][$E] = $E;
$_SESSION['glpiactiveentities_string'] = "'" . implode("', '", $_SESSION['glpiactiveentities']) . "'";

// Стили и скрипты — как в шапке страницы GLPI 11, с адреса ITILFLOW_GLPI_URL.
$css = '';
foreach (['/lib/base.min.css', '/lib/tabler.min.css', '/css_compiled/css_glpi.min.css', '/css_compiled/css_core_palettes.min.css'] as $c) {
    $css .= '<link rel="stylesheet" href="' . $base . $c . '">' . "\n";
}
$js = '<script>window.CFG_GLPI = {root_doc: "' . $base . '", url_base: "' . $base . '", language: "ru_RU"};</script>';
foreach (['/lib/base.min.js', '/js/common_ajax_controller.min.js', '/js/common.min.js', '/lib/tinymce.min.js',
    '/lib/select2/js/i18n/ru.min.js', '/js/misc.min.js'] as $j) {
    $js .= '<script src="' . $base . $j . '"></script>' . "\n";
}
$stranica = static function (string $imya, string $zagolovok, string $html) use ($css, $js, $out): void {
    $doc = '<!DOCTYPE html><html lang="ru" data-glpi-theme="lightblue"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . $css . $js
        . '<style>.tooltip-invisible{display:none}</style>'
        . '</head><body class="p-3" style="max-width:1366px">'
        . '<div class="card"><div class="card-header"><h3 class="card-title">' . htmlspecialchars($zagolovok) . '</h3></div>'
        . $html . '</div></body></html>';
    file_put_contents($out . '/' . $imya . '.html', $doc);
    ok(is_file($out . '/' . $imya . '.html'), 'нарисовано: ' . $imya);
};

$p = new Process();
$p->getFromDB($P);
$stranica('ui-route-form', 'Маршрут этапов — Запрос с согласованием',
    vyvod(static fn() => $p->showForm($P)));
$st = new Stage();
$st->getFromDB($s2);
$stranica('ui-stage-form', 'Этап — Исполнение отделом',
    vyvod(static fn() => $st->showForm($s2, ['plugin_itilflow_processes_id' => $P])));
$stranica('ui-stages-list', 'Маршрут этапов — Запрос с согласованием — Этапы',
    '<div class="card-body">' . vyvod(static fn() => Stage::showForProcess($p)) . '</div>');
$l = new Process();
$l->getFromDB($L);
$stranica('ui-legacy-route', 'Маршрут этапов — Работа внутри отдела',
    vyvod(static fn() => $l->showForm($L)));

itog();
