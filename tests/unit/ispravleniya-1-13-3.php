<?php
/**
 * 1.13.3: три исправления к 1.13.2 — без базы. Номер раздела — номер исправления.
 * 1 — вопросы этапа у согласования для согласующего в самообслуживании;
 * 2 — «Решить заявку — только после всех этапов» без оглядки на «Если действие не по порядку этапов»;
 * 3 — «Ничего не отмечено — вернуть на уточнение нельзя.» — только когда ничего не отмечено.
 * Где нужно соединение — подменённое, как в ispravleniya-1-13-2: записывает SQL и отдаёт заданные строки.
 */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\{DefinitionSnapshot, Instance, Process, StepLine, Terms, TransactionScope};

final class FakeResult1133
{
    public int $num_rows;
    public function __construct(public array $rows) { $this->num_rows = count($rows); }
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
}

final class FakeDbh1133
{
    public function begin_transaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollback(): bool { return true; }
    public function savepoint($name): bool { return true; }
    public function release_savepoint($name): bool { return true; }
}

/** Соединение GLPI без сервера: запрос, в котором есть ключ $selects, отдаёт его строки. */
class FakeDB1133 extends DBmysql
{
    public array $sql = [];
    public array $selects = [];
    public function doQuery($query)
    {
        $this->sql[] = $query;
        if (str_starts_with($query, 'SELECT @@session.autocommit')) { return new FakeResult1133([['autocommit' => '1']]); }
        foreach ($this->selects as $needle => $rows) {
            if (str_contains($query, $needle)) { return new FakeResult1133($rows); }
        }
        return new FakeResult1133([]);
    }
    public function numrows($result) { return $result->num_rows; }
    public function fetchAssoc($result) { return $result->fetch_assoc(); }
}

function fakeDb1133(): FakeDB1133
{
    (new ReflectionProperty(TransactionScope::class, 'lost'))->setValue(null, null);
    (new ReflectionProperty(TransactionScope::class, 'owned'))->setValue(null, 0);
    $db = (new ReflectionClass(FakeDB1133::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(DBmysql::class, 'dbh'))->setValue($db, new FakeDbh1133());
    (new ReflectionProperty(DBmysql::class, 'transaction_level'))->setValue($db, 0);
    $GLOBALS['DB'] = $db;
    return $db;
}

$_SESSION = [];
$koren = dirname(__DIR__, 2);
$istochnik = static fn(string $put): string => (string) file_get_contents($koren . '/' . $put);
/** Код без комментариев: проверки смотрят на то, что выполняется. */
$kod = static fn(string $put): string => (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $istochnik($put));

// ---------------------------------------------------------------- 1. Вопросы этапа в самообслуживании
$front = $kod('front/decision.form.php');
ok(!str_contains($front, 'checkCentralAccess') && str_contains($front, 'Session::checkLoginUser();'),
    '1: «Сохранить ответы» и «Сохранить и одобрить» принимаются и из самообслуживания: вход обязателен, права проверяет команда');
ok(str_contains($front, 'Engine::saveDecision(') && str_contains($front, '!Authorizer::stepItemVisible($instance, $step)')
    && str_contains($front, 'StepLine::homeUrl('),
    '1: после ответа, если заявка больше не видна, — на свою главную, а не на «Доступ запрещен»');
$decision = $kod('src/Decision.php');
ok(str_contains($decision, 'if (!$central && !$byApproval) { return false; }')
    && str_contains($decision, 'if ($central && Authorizer::isAdministrator()) { return true; }')
    && !str_contains($decision, "!== 'central') { return false; }"),
    '1: в самообслуживании ответы этапа пишет только адресат запроса на согласование; права администратора маршрутов там не действуют');
ok(preg_match('/if \(\$byApproval\) \{\s*foreach \(StructuredAccess::roster\(\$step\) \?\? \[\] as \$row\) \{.*?ValidationAuthority::canAnswer\(\$v\) && \$v->can\(\$v->getID\(\), UPDATE\)/s',
    $decision) === 1, '1: адресат — та же проверка, что у ответа на запрос: GLPI разрешает ему ответить (canAnswer + UPDATE)');
$ui = $kod('src/Ui.php');
ok(str_contains($ui, 'if (Decision::canWrite($step)) {') && !str_contains($ui, '$staff && Decision::canWrite($step)'),
    '1: «Вопросы этапа» в строке — по Decision::canWrite, без условия основного интерфейса');
ok(str_contains($ui, "|| (!\$staff && in_array('decision', array_column(\$actions, 'action'), true))"),
    '1: согласующему в самообслуживании, которого ждут вопросы, — «Ждём вашего действия.»');
$inst = $kod('src/Instance.php');
ok(str_contains($inst, "!\$process->fields['helpdesk_tab'] && !self::questionsAwaitMe(\$inst)")
    && str_contains($inst, 'return Decision::canWrite($step);'),
    '1: без «Вкладка маршрута у инициатора» вкладку в самообслуживании видит согласующий, которого ждут вопросы этапа');

// Настоящий шаблон: вид самообслуживания (is_staff = false) — форма вопросов с «Сохранить и одобрить».
$korenT = $koren . '/templates';
$zagr = new \Twig\Loader\FilesystemLoader($korenT);
$zagr->addPath($korenT, 'itilflow');
$tw = new \Twig\Environment($zagr, ['autoescape' => 'html', 'strict_variables' => true]);
foreach (['csrf_token', 'call', 'path'] as $fn) { $tw->addFunction(new \Twig\TwigFunction($fn, static fn() => '')); }
$voprosy = [['key' => 'dostup', 'label' => 'Доступ на чтение?']];
$stroka = ['rank' => 20, 'step_id' => 7, 'name' => 'Согласование', 'mode_label' => 'Согласование', 'mode_class' => 'bg-purple-lt',
    'approval' => [], 'decisions' => [], 'owner' => 'Согласующий', 'artifact' => '', 'current' => true, 'try' => 0, 'history' => [],
    'due' => '', 'overdue' => false, 'took' => '', 'menu' => [],
    'result' => ['kind' => 'running', 'label' => 'Сейчас', 'summary' => 'Ждём вашего действия.', 'internal' => '', 'author' => '',
        'date' => '', 'class' => 'bg-blue-lt'],
    'actions' => [
        ['action' => 'decision', 'label' => Terms::STAGE_QUESTIONS, 'can_approve' => true, 'validation_id' => 301,
            'decision_items' => $voprosy, 'decision_answers' => [], 'decision_revision' => 0],
        ['action' => 'visa', 'label' => 'Одобрить или отказать', 'validation_id' => 301, 'validation_url' => '/front/ticketvalidation.form.php',
            'can_return' => false, 'targets' => [], 'decision_items' => $voprosy, 'decision_answers' => [], 'decision_revision' => 0],
    ]];
$html = $tw->render('flow_tab.html.twig', ['process_name' => 'Маршрут', 'only' => false, 'parent_link' => '',
    'state' => ['В работе', 'bg-blue-lt', ''], 'sheet_url' => '', 'header_menu' => [], 'can_resume' => false, 'withdraw' => false,
    'violations' => [], 'is_staff' => false, 'is_admin' => false, 'required_progress' => null, 'command' => ['key' => 'k', 'attempt_id' => 5],
    'instance' => ['fields' => ['id' => 1]], 'me' => 7, 'step_url' => '/s', 'outcome_url' => '/o', 'decision_url' => '/plugins/itilflow/front/decision.form.php',
    'rows' => [$stroka]]);
ok(str_contains($html, 'action="/plugins/itilflow/front/decision.form.php"') && str_contains($html, '>Сохранить и одобрить</button>')
    && str_contains($html, 'name="decision_answers[dostup]"') && str_contains($html, '<input type="hidden" name="validation_id" value="301">')
    && str_contains($html, '>Одобрить или отказать</button>'),
    '1: в самообслуживании — «Вопросы этапа» с «Сохранить и одобрить» рядом с «Одобрить или отказать», как в основном интерфейсе');

// ---------------------------------------------------------------- 2. «Решить заявку — только после всех этапов»
$process = ['id' => 5, 'name' => 'Маршрут', 'version' => 4, 'itemtype' => 'Ticket', 'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1,
    'enforcement' => Process::ENFORCE_WARN, 'block_resolution' => 1, 'public_log' => 1, 'helpdesk_tab' => 1, 'allow_withdraw' => 1,
    'calendars_id' => 0, 'outcome_contract' => DefinitionSnapshot::MODE, 'forms_forms_id' => 0, 'forms_destinations_id' => 0];
$etap = ['id' => 51, 'plugin_itilflow_processes_id' => 5, 'ranking' => 10, 'name' => 'Первый этап', 'execution_mode' => 'inline',
    'entity_strategy' => 'inherit', 'entities_id_target' => 0, 'groups_id' => 7, 'users_id' => 0, 'itilcategories_id' => 0, 'slas_id' => 0,
    'duration' => 0, 'is_optional' => 0, 'completion_comment' => 'optional', 'deadline_minutes' => 0, 'approval_percent' => 100,
    'approver_source' => 'fixed', 'ticket_status' => 0, 'on_reject' => 'block', 'plugin_itilflow_stages_id_reject' => 0, 'content' => '',
    'department_result_source' => '', 'negative_policy' => 'block', 'return_targets' => '[]', 'form_validationsteps_id' => 0,
    'approver_users' => null, 'approver_groups' => null, 'approver_group_mode' => 'any', 'run_if' => null, 'decision_items' => null,
    'unknown_policy' => 'run'];
/** Запуск с этим снимком: строка экземпляра, как её пишет startStructuredInternal. */
$zapusk = static function (array $snapshot): Instance {
    $raw = DefinitionSnapshot::encode($snapshot);
    $i = new Instance();
    $i->fields = ['id' => 9, 'outcome_contract' => DefinitionSnapshot::MODE, 'definition_snapshot' => $raw,
        'definition_sha256' => hash('sha256', $raw), 'plugin_itilflow_processes_id' => 5, 'process_version' => 4, 'itemtype' => 'Ticket'];
    return $i;
};

// Запуск на 1.13.3 — снимок с отметкой правила; схема снимка прежняя.
$db = fakeDb1133();
$scope = new TransactionScope();
$db->selects['`glpi_plugin_itilflow_processes` WHERE id=5'] = [$process + ['comment' => 'лишнее поле строки']];
$db->selects['`glpi_plugin_itilflow_stages` WHERE plugin_itilflow_processes_id=5'] = [$etap];
$p5 = new Process(); $p5->fields = ['id' => 5];
$snimok = null;
try { $snimok = DefinitionSnapshot::capture($p5); } catch (Throwable $e) { info('capture: ' . $e->getMessage()); }
$scope->rollback();
ok(is_array($snimok) && ($snimok['resolution'] ?? null) === 'all_stages' && $snimok['schema'] === 3 && DefinitionSnapshot::SCHEMA === 3
    && DefinitionSnapshot::valid($snimok),
    '2: запуск на 1.13.3 отмечает в снимке правило решения; схема снимка прежняя (3), снимок действителен', json_encode($snimok, JSON_UNESCAPED_UNICODE));
ok(is_array($snimok) && DefinitionSnapshot::resolutionAfterAllStages($zapusk($snimok)),
    '2: такой запуск решается по правилу 1.13.3');
$prezhnij = DefinitionSnapshot::draft($process, [$etap]);
ok(!array_key_exists('resolution', $prezhnij) && DefinitionSnapshot::valid($prezhnij)
    && !DefinitionSnapshot::resolutionAfterAllStages($zapusk($prezhnij)),
    '2: запуск, начатый до 1.13.3 (снимок без отметки), решается по прежнему правилу — по своей версии маршрута');
$legacy = new Instance(); $legacy->fields = ['outcome_contract' => 'legacy'];
$isporchen = $zapusk(is_array($snimok) ? $snimok : $prezhnij); $isporchen->fields['definition_sha256'] = str_repeat('0', 64);
$chuzhoj = $zapusk(is_array($snimok) ? array_replace($snimok, ['resolution' => 'other']) : $prezhnij);
ok(!DefinitionSnapshot::resolutionAfterAllStages($legacy) && !DefinitionSnapshot::resolutionAfterAllStages($isporchen)
    && !DefinitionSnapshot::resolutionAfterAllStages($chuzhoj),
    '2: маршрут прежнего порядка, повреждённый снимок и чужое значение отметки — прежнее правило');
ok(DefinitionSnapshot::load($zapusk(is_array($snimok) ? $snimok : $prezhnij))['process']['block_resolution'] === 1,
    '2: «Решить заявку — только после всех этапов» читается из снимка запуска, а не из текущего маршрута');

$state = 'маршрут «Маршрут» не пройден, открыт этап «Первый этап»';
ok(StepLine::earlyResolutionLog($state) === 'Решено до конца маршрута по праву «Закрытие этапа без ответа»: маршрут «Маршрут» не пройден, открыт этап «Первый этап».',
    '2: запись журнала — кто решил раньше времени и по какому праву', StepLine::earlyResolutionLog($state));
ok(StepLine::earlyResolutionMessage('маршрут «Маршрут» остановлен')
    === 'Решение до конца маршрута разрешено по праву «Закрытие этапа без ответа» и записано в «Журнал маршрута»: маршрут «Маршрут» остановлен.',
    '2: тому, кто решает, — что решение разрешено и записано в журнал');
foreach (Terms::FORBIDDEN as $re => $zamena) {
    if (preg_match($re, StepLine::earlyResolutionLog($state) . ' ' . StepLine::earlyResolutionMessage($state))) {
        ok(false, '2: в новых текстах нет запрещённых слов', $re . ' → ' . $zamena);
    }
}

$engine = $kod('src/Engine.php');
preg_match('/public static function denyResolution\(.*?\n    \}\n/s', $engine, $m);
$telo = $m[0] ?? '';
ok($telo !== '' && str_contains($telo, 'if (!DefinitionSnapshot::resolutionAfterAllStages($instance)) {')
    && str_contains($telo, 'return self::deny($instance, $itemtype, $items_id, $action, $blocked);'),
    '2: запуски, начатые раньше, — прежнее правило (Engine::deny с «Если действие не по порядку этапов»)');
ok($telo !== '' && !str_contains($telo, 'enforcement(') && !str_contains($telo, 'ENFORCE_')
    && preg_match('/Violation::record\(\$instance, \$itemtype, \$items_id, \$action, true, \$blocked\);\s*self::msg\(\$blocked, ERROR\);\s*return true;/', $telo) === 1,
    '2: у запуска 1.13.3 решение запрещено при «Только записать», «Предупредить» и «Запретить» одинаково — с записью «запрещено»');
ok($telo !== '' && preg_match('/if \(self::userMayBypass\(\)\) \{.*?isset\(self::\$earlyResolutions\[\$key\]\).*?Violation::record\(\$instance, \$itemtype, \$items_id, \$action, false, StepLine::earlyResolutionLog\(\$state\)\);.*?return false;/s', $telo) === 1,
    '2: право «Закрытие этапа без ответа» разрешает решение — одна запись «разрешено» в журнал на решение и смену статуса');
$hook = $kod('src/Hook.php');
ok(substr_count($hook, 'Engine::denyResolution(') === 2 && !str_contains($hook, "Engine::deny(\$instance, \$item->getType(), \$item->getID(), 'resolve_with_open_stages'")
    && !str_contains($hook, "Engine::deny(\$instance, \$itemtype, \$items_id, 'solution_with_open_stages'"),
    '2: и смена статуса на «Решена» / «Закрыта», и добавление решения проверяются новым правилом');
$poyasnenie = strip_tags(\GlpiPlugin\Itilflow\ProfileRights::explanation());
ok(str_contains($poyasnenie, '«Закрыть принудительно» текущий этап с причиной; решить заявку, пока маршрут не пройден, — с записью в «Журнал маршрута».'),
    '2: вкладка «Маршруты этапов» профиля говорит, что «Закрытие этапа без ответа» разрешает и решение до конца маршрута', $poyasnenie);

// ---------------------------------------------------------------- 3. «Ничего не отмечено — вернуть на уточнение нельзя.»
$shablon = $istochnik('templates/stage.html.twig');
ok(str_contains($shablon, "{% set return_checked = targets|filter(t => t.value in targets_selected)|length > 0 %}")
    && str_contains($shablon, "_self.noTargetsHint('Ничего не отмечено — вернуть на уточнение нельзя.', return_checked)")
    && !str_contains($shablon, "_self.hint('Ничего не отмечено — вернуть на уточнение нельзя.')"),
    '3: сервер рисует подсказку скрытой, если этап для возврата уже отмечен');
ok(str_contains($shablon, "form.querySelectorAll('[data-itilflow-none-checked]')")
    && str_contains($shablon, "hint.hidden = !!(box && box.querySelector('input[type=\"checkbox\"]:checked'));")
    && preg_match('/function apply\(\) \{.*?noneChecked\(\);.*?\n   \}/s', $shablon) === 1,
    '3: скрипт формы прячет и показывает подсказку при каждой галочке (apply вызывается на любое изменение формы)');
// Сам макрос подсказки — настоящим Twig: остальное в шаблоне формы этапа не выполняется.
$zagr3 = new \Twig\Loader\ChainLoader([
    new \Twig\Loader\ArrayLoader(['components/form/fields_macros.html.twig' => '',
        'proba' => "{% import 'stage.html.twig' as s %}[{{ s.noTargetsHint('Ничего не отмечено — вернуть на уточнение нельзя.', checked) }}]"]),
    new \Twig\Loader\FilesystemLoader($korenT)]);
$tw3 = new \Twig\Environment($zagr3, ['autoescape' => 'html', 'strict_variables' => true]);
foreach (['csrf_token', 'call', 'path', '__'] as $fn) { $tw3->addFunction(new \Twig\TwigFunction($fn, static fn() => '')); }
try {
    $pusto = $tw3->render('proba', ['checked' => false]);
    $otmecheno = $tw3->render('proba', ['checked' => true]);
    ok(str_contains($pusto, 'data-itilflow-none-checked') && !str_contains($pusto, 'hidden') && str_contains($pusto, 'Ничего не отмечено')
        && str_contains($otmecheno, 'data-itilflow-none-checked') && str_contains($otmecheno, 'hidden'),
        '3: ничего не отмечено — подсказка видна; отмечен этап — скрыта', $pusto . ' | ' . $otmecheno);
} catch (Throwable $e) {
    ok(false, '3: макрос подсказки рисуется', $e->getMessage());
}

itog();
