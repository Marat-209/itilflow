<?php

/**
 * Названия маршрута и этапа и инструкция этапа — в выводе плагина текстом.
 *
 * GLPI 11 выводит сообщения после перехода как HTML, а содержимое задачи и
 * комментарий запроса на согласование — как HTML из редактора. Экранирует тот,
 * кто пишет. Маршрут прежнего порядка с тегом в названиях: запуск («Открыт
 * этап …»), задача этапа, форма этапа в ленте, запрет решения заявки, запрос на
 * согласование. Поведение движка то же — меняется только вывод.
 * Всё — в транзакции проверки, откатывается.
 */

require __DIR__ . '/_nachalo.php';

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;

global $DB;
$admin = adminGlpi();
ok($admin > 0, 'найден администратор GLPI для входа');
vojti($admin, 'glpi');
// Без права обхода: запрет решения должен сработать и показать сообщение.
$_SESSION['glpiactiveprofile']['plugin_itilflow_bypass'] = 0;

$zlo = '<img src=x onerror=alert(1)>';
$tost = static fn(): string => TemplateRenderer::getInstance()->render('components/messages_after_redirect_toasts.html.twig');
$tekstom = static fn(string $html, string $chto): bool => !str_contains($html, $chto) && str_contains($html, htmlescape($chto));
$kratko = static fn(string $html): string => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($html))), 0, 300);

// 1. Любое сообщение движка — текстом.
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
(new \ReflectionMethod(Engine::class, 'msg'))->invoke(null, 'Проверка: ' . $zlo, INFO);
ok($tekstom($tost(), $zlo), 'сообщение движка — текстом');

// 2. Маршрут прежнего порядка: задача (исполнитель — администратор), затем согласование.
// Ход этапов в ленту не пишется: запись в ленту стирает сообщения после перехода.
$DB->insert(Process::getTable(), ['name' => '[проверка] маршрут ' . $zlo, 'entities_id' => 0, 'is_recursive' => 1,
    'is_active' => 1, 'itemtype' => 'Ticket', 'enforcement' => Process::ENFORCE_STRICT, 'version' => 1,
    'block_resolution' => 1, 'public_log' => 0, 'helpdesk_tab' => 1, 'allow_withdraw' => 1, 'calendars_id' => 0,
    'outcome_contract' => 'legacy']);
$pid = (int) $DB->insertId();
$DB->insert(Stage::getTable(), ['plugin_itilflow_processes_id' => $pid, 'name' => $zlo, 'ranking' => 10,
    'execution_mode' => Stage::MODE_INLINE, 'users_id' => $admin, 'completion_comment' => 'optional',
    'content' => '<p>Сделать работу.</p><img src=x onerror=alert(2)>']);
$DB->insert(Stage::getTable(), ['plugin_itilflow_processes_id' => $pid, 'name' => $zlo . ' согласование', 'ranking' => 20,
    'execution_mode' => Stage::MODE_APPROVAL, 'users_id' => $admin, 'approver_source' => Stage::APPROVER_FIXED,
    'approval_percent' => 100, 'content' => '']);

$t = new \Ticket();
$tid = (int) $t->add(['name' => '[проверка] заявка для вывода названий', 'content' => 'Проверка.',
    'entities_id' => 0, '_users_id_requester' => $admin, '_skip_rules' => true]);
ok($tid > 0, 'тестовая заявка создана');
$t->getFromDB($tid);

// Запуск: «Открыт этап «…». Ответственный: …».
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
$instance = Engine::start($t, $pid);
ok($instance !== null, 'маршрут запущен');
$html = $tost();
ok($tekstom($html, $zlo), 'запуск: название этапа в сообщении — текстом', $kratko($html));
if ($instance === null) {
    itog();
}

// Задача этапа: название в заголовке задачи — текстом.
$step = $instance->getCurrentStep();
$zadacha = $step !== null && $step->fields['artifact_itemtype'] === 'TicketTask'
    ? $DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['id' => (int) $step->fields['artifact_items_id']]])->current() : null;
ok($zadacha !== null && $tekstom((string) $zadacha['content'], $zlo), 'задача этапа: название — текстом',
    (string) ($zadacha['content'] ?? 'задачи нет'));

// Форма этапа в ленте заявки: инструкция — без сценария, текст на месте.
$t->getFromDB($tid);
$lenta = TemplateRenderer::getInstance()->render('@itilflow/timeline_stage.html.twig', ['item' => $t]);
ok(str_contains($lenta, 'Сделать работу.') && !preg_match('/<[a-z][^>]*\sonerror\s*=/i', $lenta), 'форма этапа в ленте: инструкция без сценария',
    $kratko($lenta));
ok($tekstom($lenta, $zlo), 'форма этапа в ленте: название — текстом');

// Запрет решения заявки, пока маршрут идёт: названия маршрута и этапа — текстом.
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
$t->update(['id' => $tid, 'status' => \CommonITILObject::SOLVED]);
$t->getFromDB($tid);
$html = $tost();
ok((int) $t->fields['status'] !== \CommonITILObject::SOLVED, 'заявка не решена, пока маршрут идёт');
ok($tekstom($html, '[проверка] маршрут ' . $zlo) && $tekstom($html, $zlo), 'запрет решения: названия маршрута и этапа — текстом',
    $kratko($html));

// Этап 10 завершён — открыт этап согласования: название в комментарии запроса — текстом.
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
ok(Engine::completeStep($step, 'Сделано.'), 'этап-задача завершён');
$html = $tost();
ok($tekstom($html, $zlo . ' согласование'), 'открыт этап согласования: название в сообщении — текстом', $kratko($html));
$viza = $DB->request(['FROM' => 'glpi_ticketvalidations', 'WHERE' => ['tickets_id' => $tid], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
ok($viza !== null && $tekstom((string) $viza['comment_submission'], $zlo . ' согласование'),
    'запрос на согласование: название этапа — текстом', (string) ($viza['comment_submission'] ?? 'запроса нет'));

// 3. Новый порядок: описание заявки отдела. Инструкция этапа — HTML из редактора: в описании
// она должна остаться разметкой (без видимых «<p>»), а сценарий в ней GLPI вычищает при выводе.
// Текст основной заявки в описании — один раз экранированный: «A & B», а не «A &amp; B».
$g = $DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1],
    'ORDER' => 'id', 'LIMIT' => 1])->current();
ok($g !== null, 'есть группа-исполнитель');
if ($g !== null) {
    $E = (int) $g['entities_id'];
    $DB->insert(Process::getTable(), ['name' => '[проверка] маршрут нового порядка', 'entities_id' => $E, 'is_recursive' => 1,
        'is_active' => 1, 'itemtype' => 'Ticket', 'enforcement' => Process::ENFORCE_AUDIT, 'version' => 1,
        'block_resolution' => 1, 'public_log' => 0, 'helpdesk_tab' => 1, 'allow_withdraw' => 1, 'calendars_id' => 0,
        'outcome_contract' => 'structured_v1']);
    $pn = (int) $DB->insertId();
    $DB->insert(Stage::getTable(), ['plugin_itilflow_processes_id' => $pn, 'name' => 'Работа отдела', 'ranking' => 10,
        'execution_mode' => Stage::MODE_DELEGATED, 'groups_id' => (int) $g['id'], 'entity_strategy' => 'inherit',
        'department_result_source' => 'assignee_action', 'negative_policy' => Stage::REJECT_ABORT, 'return_targets' => '[]',
        'approval_percent' => 100, 'content' => '<p>Выполните работу.</p><p><img src=x onerror=alert(3)></p>']);
    $t2 = new \Ticket();
    $tid2 = (int) $t2->add(['name' => '[проверка] заявка нового порядка', 'content' => '<p>Нужно: A &amp; B.</p>',
        'entities_id' => $E, '_users_id_requester' => $admin, '_skip_rules' => true]);
    $t2->getFromDB($tid2);
    $i2 = $tid2 > 0 ? Engine::start($t2, $pn) : null;
    $sh = $i2 === null ? null : $DB->request(['FROM' => 'glpi_plugin_itilflow_steps',
        'WHERE' => ['plugin_itilflow_instances_id' => $i2->getID()], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
    $dochernyaya = $sh !== null && (int) $sh['artifact_items_id'] > 0
        ? $DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int) $sh['artifact_items_id']]])->current() : null;
    ok($dochernyaya !== null, 'новый порядок: заявка отдела создана', json_encode($sh, JSON_UNESCAPED_UNICODE));
    $opis = (string) ($dochernyaya['content'] ?? '');
    ok(str_contains($opis, '<p>Выполните работу.</p>') && !str_contains($opis, '&lt;p&gt;'),
        'описание заявки отдела: инструкция — разметкой, теги не видны', $opis);
    $pokaz = \Glpi\RichText\RichText::getEnhancedHtml($opis);
    ok(!preg_match('/<[a-z][^>]*\sonerror\s*=/i', $pokaz) && str_contains($pokaz, 'Выполните работу.'),
        'описание заявки отдела в GLPI: без сценария', $pokaz);
    ok(str_contains($opis, 'A &amp; B') && !str_contains($opis, '&amp;amp;'),
        'описание заявки отдела: текст основной заявки экранирован один раз', $opis);
}

itog();
