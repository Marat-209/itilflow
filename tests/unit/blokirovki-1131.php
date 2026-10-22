<?php
/**
 * 1.13.1, MySQL 8: одновременные ответы на разные заявки с общим
 * согласующим вставали во взаимную блокировку (1213) — HTTP 500 у инициатора, этап согласования стоял.
 * Без базы: подменённое соединение GLPI записывает SQL и по заказу отвечает отказом сервера.
 *
 * 1. Сужение блокировок: справочники — общей блокировкой; прежняя связь запроса с этапом ищется
 *    по этапам запусков своей заявки, а не по всем этапам согласования с FOR UPDATE.
 * 2. Отказ сервера 1213/1205 — не «потеря транзакции»: откат известен, команда повторяется.
 * 3. Обработчики комментария и ответа согласующего не выпускают исключение в ядро.
 * 4. Догоняющая сверка: выбор ответа, который решил этап; автоматическое действие.
 */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\{TransactionScope, TransactionLost, TransactionConflict, CurrentRead, Engine, Step, Hook, Instance, Outcome};

final class FakeResult1131
{
    public int $num_rows;
    public function __construct(public array $rows) { $this->num_rows = count($rows); }
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
}

/** Вместо mysqli: начало, фиксация и откат транзакции и точки сохранения — только записью. */
final class FakeDbh1131
{
    public array $log = [];
    public int $insert_id = 1;
    public function begin_transaction(): bool { $this->log[] = 'BEGIN'; return true; }
    public function commit(): bool { $this->log[] = 'COMMIT'; return true; }
    public function rollback(): bool { $this->log[] = 'ROLLBACK'; return true; }
    public function savepoint($name): bool { $this->log[] = 'SAVEPOINT ' . $name; return true; }
    public function release_savepoint($name): bool { $this->log[] = 'RELEASE ' . $name; return true; }
}

/** Соединение GLPI без сервера: методы транзакций — штатные DBmysql, запросы — записью. */
class FakeDB1131 extends DBmysql
{
    public array $sql = [];
    public array $requests = [];
    /** FROM => строки для обычного чтения (request) */
    public array $tables = [];
    /** подстрока SQL => строки для текущего чтения (doQuery) */
    public array $selects = [];
    /** @var list<callable(string): void> перед выполнением запроса; может бросить отказ сервера */
    public array $traps = [];
    public function doQuery($query)
    {
        $this->sql[] = $query;
        foreach ($this->traps as $trap) { $trap($query); }
        if (str_starts_with($query, 'SELECT @@session.autocommit')) { return new FakeResult1131([['autocommit' => '1']]); }
        foreach ($this->selects as $needle => $rows) {
            if (str_contains($query, $needle)) { return new FakeResult1131($rows); }
        }
        return new FakeResult1131([]);
    }
    public function request($criteria)
    {
        $this->requests[] = $criteria;
        return new ArrayIterator($this->tables[is_array($criteria) ? ($criteria['FROM'] ?? '') : ''] ?? []);
    }
    public function numrows($result) { return $result->num_rows; }
    public function fetchAssoc($result) { return $result->fetch_assoc(); }
}

/** Соединение, у которого и обычное чтение отказывает с 1213. */
class FakeDBOtkaz1131 extends FakeDB1131
{
    public function request($criteria) { throw dedlok1131('request'); }
}

function sbros1131(): void
{
    (new ReflectionProperty(TransactionScope::class, 'lost'))->setValue(null, null);
    (new ReflectionProperty(TransactionScope::class, 'owned'))->setValue(null, 0);
}

function fakeDb1131(): FakeDB1131
{
    sbros1131();
    $db = (new ReflectionClass(FakeDB1131::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(DBmysql::class, 'dbh'))->setValue($db, new FakeDbh1131());
    (new ReflectionProperty(DBmysql::class, 'transaction_level'))->setValue($db, 0);
    $GLOBALS['DB'] = $db;
    return $db;
}

function uroven1131(DBmysql $db): int
{
    return (int) (new ReflectionProperty(DBmysql::class, 'transaction_level'))->getValue($db);
}

function dedlok1131(string $sql = 'SELECT 1'): RuntimeException
{
    return new RuntimeException('MySQL query error: Deadlock found when trying to get lock; try restarting transaction (1213) in SQL query "'
        . $sql . '".');
}

$savedDB = $GLOBALS['DB'] ?? null;
$_SESSION = [];

// ---------------------------------------------------------------- 1. Сужение блокировок
ok(CurrentRead::lockClause(\User::class) === ' LOCK IN SHARE MODE' && CurrentRead::lockClause(new \Group_User()) === ' LOCK IN SHARE MODE'
    && CurrentRead::lockClause(\Profile_User::class) === ' LOCK IN SHARE MODE' && CurrentRead::lockClause(\ValidatorSubstitute::class) === ' LOCK IN SHARE MODE'
    && CurrentRead::lockClause(\RuleTicket::class) === ' LOCK IN SHARE MODE',
    'справочники (люди, группы, профили, замещения, правила) — общей блокировкой');
ok(CurrentRead::lockClause(Step::class) === ' FOR UPDATE' && CurrentRead::lockClause(\TicketValidation::class) === ' FOR UPDATE'
    && CurrentRead::lockClause(\Ticket::class) === ' FOR UPDATE' && CurrentRead::lockClause(Instance::class) === ' FOR UPDATE',
    'этапы, запуски, заявки и запросы согласования — по-прежнему исключительной');

$db = fakeDb1131();
$scope = new TransactionScope();
$db->sql = [];
CurrentRead::load(new \User(), 2);
$sqlUser = (string) end($db->sql);
ok(str_contains($sqlUser, '`glpi_users`') && str_ends_with($sqlUser, ' LOCK IN SHARE MODE') && !str_contains($sqlUser, 'FOR UPDATE'),
    'строка согласующего (Authorizer::userFitsItem) читается общей блокировкой — две команды с одним согласующим не ждут друг друга', $sqlUser);

// Прежняя связь запроса с этапом — среди этапов запусков своей заявки.
$db->sql = []; $db->requests = [];
$db->tables['glpi_ticketvalidations'] = [['id' => 101, 'tickets_id' => 55]];
$db->selects['`glpi_plugin_itilflow_instances` WHERE `itemtype`'] = [['id' => 7, 'itemtype' => 'Ticket', 'items_id' => 55]];
$db->selects['`glpi_plugin_itilflow_steps` WHERE `plugin_itilflow_instances_id`'] = [['id' => 70, 'plugin_itilflow_instances_id' => 7,
    'execution_mode' => 'approval', 'artifact_itemtype' => 'TicketValidation', 'artifact_items_id' => 101,
    'approver_items_ids' => '101', 'approval_snapshot_source' => '']];
$kandidaty = Step::legacyCandidates('TicketValidation', [101]);
$shagi = array_values(array_filter($db->sql, static fn(string $q): bool => str_contains($q, '`glpi_plugin_itilflow_steps`')));
ok(array_keys($kandidaty) === [70] && count($shagi) === 1 && str_contains($shagi[0], "`plugin_itilflow_instances_id` IN ('7')")
    && str_ends_with($shagi[0], ' FOR UPDATE'),
    'этапы для прежней связи — только запуска своей заявки (индекс `instance`), а не все этапы согласования',
    json_encode($db->sql, JSON_UNESCAPED_UNICODE));
ok(!preg_grep('/`glpi_plugin_itilflow_steps` WHERE `execution_mode`/', $db->sql),
    'нет сплошного FOR UPDATE по всем этапам согласования', json_encode($db->sql, JSON_UNESCAPED_UNICODE));
ok(($db->requests[0]['FROM'] ?? '') === 'glpi_ticketvalidations' && !preg_grep('/glpi_ticketvalidations/', $db->sql),
    'заявка запроса читается обычным чтением, без блокировки строки запроса');
$map = Step::ownersMap('TicketValidation', [101]);
ok(($map[101] ?? []) === [70], 'владелец запроса по прежней связи найден', json_encode($map));

// Связь этапа чужой заявки с тем же запросом (повреждение данных) видна — без блокировки чужих строк.
$db->sql = []; $db->requests = [];
$db->tables['glpi_plugin_itilflow_steps'] = [['id' => 72, 'plugin_itilflow_instances_id' => 8, 'execution_mode' => 'approval',
    'artifact_itemtype' => 'TicketValidation', 'artifact_items_id' => 500, 'approver_items_ids' => '500,101', 'approval_snapshot_source' => '']];
$map = Step::ownersMap('TicketValidation', [101]);
$zablokirovany = array_values(array_filter($db->sql, static fn(string $q): bool => str_contains($q, '`glpi_plugin_itilflow_steps`')));
ok(($map[101] ?? []) === [70, 72] && count($zablokirovany) === 1 && str_contains($zablokirovany[0], "IN ('7')"),
    'запрос, связанный и с этапом чужой заявки, виден (два владельца — действие откажет), а строки чужих этапов не блокируются',
    json_encode(['map' => $map, 'sql' => $db->sql], JSON_UNESCAPED_UNICODE));
$db->tables['glpi_plugin_itilflow_steps'] = [];

// Запроса уже нет (снят) — его заявку не узнать: полный просмотр, но обычным чтением.
$db->sql = []; $db->requests = [];
$db->tables['glpi_ticketvalidations'] = [];
$db->tables['glpi_plugin_itilflow_steps'] = [['id' => 71, 'execution_mode' => 'approval', 'artifact_itemtype' => 'TicketValidation',
    'artifact_items_id' => 999, 'approver_items_ids' => '999', 'approval_snapshot_source' => '']];
$kandidaty = Step::legacyCandidates('TicketValidation', [999]);
ok(isset($kandidaty[71]) && !preg_grep('/glpi_plugin_itilflow_steps/', $db->sql)
    && in_array('glpi_plugin_itilflow_steps', array_column($db->requests, 'FROM'), true),
    'снятый запрос: прежняя связь ищется полным просмотром без блокировок', json_encode($db->sql, JSON_UNESCAPED_UNICODE));
$scope->rollback();

// ---------------------------------------------------------------- 2. Отказ сервера из-за блокировок
ok(TransactionScope::isConflict(dedlok1131())
    && TransactionScope::isConflict(new RuntimeException('MySQL query error: Lock wait timeout exceeded; try restarting transaction (1205) in SQL query "x".'))
    && TransactionScope::isConflict(new LogicException('обёртка', 0, dedlok1131()))
    && TransactionScope::isConflict(new mysqli_sql_exception('Deadlock', 1213)),
    '1213 и 1205 — отказ сервера из-за блокировок (сообщение GLPI, вложенное, mysqli)');
ok(TransactionScope::isDeadlock(dedlok1131()) && TransactionScope::isDeadlock(new mysqli_sql_exception('Deadlock', 1213))
    && !TransactionScope::isDeadlock(new RuntimeException('MySQL query error: Lock wait timeout exceeded; try restarting transaction (1205) in SQL query "x".'))
    && (new TransactionConflict(dedlok1131()))->retryable
    && !(new TransactionConflict(new RuntimeException('MySQL query error: Lock wait timeout exceeded; try restarting transaction (1205) in SQL query "x".')))->retryable,
    'повторяется только взаимная блокировка (1213), не истёкшее ожидание (1205)');
ok(!TransactionScope::isConflict(new RuntimeException('MySQL query error: SAVEPOINT x does not exist (1305) in SQL query "x".'))
    && !TransactionScope::isConflict(new RuntimeException('Не удалось сохранить изменение маршрута.'))
    && !TransactionScope::isConflict(new RuntimeException('текст (1213) в середине')),
    'другие ошибки — не отказ из-за блокировок');

/** Сервер для TransactionScope: откат 1213 убирает транзакцию и все точки сохранения. */
final class Server1131
{
    public int $depth = 0;
    public bool $active = false;
    public array $points = [];
    public array $calls = [];
    public function call(string $operation, ?string $sql): mixed
    {
        $this->calls[] = $operation . ($sql !== null ? ' ' . $sql : '');
        return match ($operation) {
            'depth' => $this->depth,
            'autocommit' => 1,
            'begin' => (function () { if ($this->depth === 0) { $this->active = true; } $this->depth++; return null; })(),
            'flatten' => (function () { $this->depth = 1; return true; })(),
            'rollback', 'commit' => (function () { $this->depth--; if ($this->depth === 0) { $this->active = false; $this->points = []; } return null; })(),
            'query' => (function () use ($sql) {
                if (str_starts_with((string) $sql, 'SAVEPOINT ')) { if ($this->active) { $this->points[substr($sql, 10)] = true; } return true; }
                $key = substr((string) $sql, 18);
                if (!isset($this->points[$key])) { throw new RuntimeException('MySQL query error: SAVEPOINT ' . $key . ' does not exist (1305) in SQL query "x".'); }
                unset($this->points[$key]); return true;
            })(),
        };
    }
    /** Взаимная блокировка: сервер откатил транзакцию целиком. */
    public function deadlock(): void { $this->active = false; $this->points = []; }
}

sbros1131();
$srv = new Server1131();
$scope = new TransactionScope($srv->call(...));
$srv->deadlock();
$poteryana = false;
$posle = count($srv->calls);
try { $scope->rollbackAfterConflict(); } catch (TransactionLost) { $poteryana = true; }
$otkat = array_slice($srv->calls, $posle);
$novyj = false;
try { $s2 = new TransactionScope($srv->call(...)); $s2->commit(); $novyj = true; } catch (TransactionLost) {}
ok(!$poteryana && $srv->depth === 0 && !TransactionScope::hasActive() && $novyj
    && $otkat === ['depth', 'rollback', 'depth'],
    'отказ 1213 в своей транзакции верхнего уровня: откат подтверждён, транзакция не «потеряна», можно начать новую',
    json_encode($srv->calls, JSON_UNESCAPED_UNICODE));

sbros1131();
$srv = new Server1131();
$scope = new TransactionScope($srv->call(...));
$srv->depth = 3; // ядро открыло внутри команды ещё два уровня, их точек сохранения уже нет
$srv->deadlock();
$scope->rollbackAfterConflict();
ok($srv->depth === 0 && in_array('flatten', $srv->calls, true) && !TransactionScope::hasActive(),
    'вложенные уровни ядра внутри команды сбрасываются, затем откат');

// То же на штатном соединении GLPI: уровни ядра внутри команды (их точек сохранения уже нет) сброшены.
$db = fakeDb1131();
$scope = new TransactionScope();
(new ReflectionProperty(DBmysql::class, 'transaction_level'))->setValue($db, 3);
$poteryana = false;
try { $scope->rollbackAfterConflict(); } catch (TransactionLost) { $poteryana = true; }
$zhurnal = (new ReflectionProperty(DBmysql::class, 'dbh'))->getValue($db)->log;
ok(!$poteryana && uroven1131($db) === 0 && end($zhurnal) === 'ROLLBACK' && !TransactionScope::hasActive(),
    'штатное соединение: счётчик уровней ядра сброшен, откат подтверждён', json_encode($zhurnal));

sbros1131();
$srv = new Server1131(); $srv->depth = 1; $srv->active = true; // внешняя транзакция не наша
$scope = new TransactionScope($srv->call(...));
$srv->deadlock();
$prichina = '';
try { $scope->rollbackAfterConflict(); } catch (TransactionLost $e) { $prichina = $e->reason; }
ok($prichina === 'conflict_in_outer_transaction', 'внутри чужой транзакции сервер откатил и её — это по-прежнему потеря', $prichina);

sbros1131();
$srv = new Server1131();
$scope = new TransactionScope($srv->call(...));
$broshen = false;
try { TransactionScope::keepGoing(dedlok1131()); } catch (RuntimeException) { $broshen = true; }
$ne = true;
try { TransactionScope::keepGoing(new RuntimeException('обычная ошибка')); } catch (Throwable) { $ne = false; }
$scope->rollback();
$vne = true;
try { TransactionScope::keepGoing(dedlok1131()); } catch (Throwable) { $vne = false; }
ok($broshen && $ne && $vne, 'catch, который продолжает работу, отказ из-за блокировок не глотает (в команде), прочее — как раньше');

// ---------------------------------------------------------------- 3. Повтор команды (исполнитель команд)
$pauzy = [];
(new ReflectionProperty(Engine::class, 'conflictPause'))->setValue(null, static function (int $n) use (&$pauzy): void { $pauzy[] = $n; });
$locked = new ReflectionMethod(Engine::class, 'locked');
$instance = new Instance();
$instance->fields = ['id' => 7];
$podgotovit = static function (): FakeDB1131 {
    $db = fakeDb1131();
    $db->selects['`glpi_plugin_itilflow_instances` WHERE id = 7 FOR UPDATE'] = [['id' => 7, 'itemtype' => 'Ticket', 'items_id' => 55,
        'outcome_contract' => 'legacy', 'current_attempt_id' => 0]];
    return $db;
};

// Взаимная блокировка на блокировке запуска — повтор.
$db = $podgotovit(); $pauzy = []; $vyzovov = 0; $raz = 0;
$db->traps[] = static function (string $q) use (&$raz): void {
    if (str_contains($q, '`glpi_plugin_itilflow_instances` WHERE id = 7 FOR UPDATE') && $raz++ === 0) { throw dedlok1131($q); }
};
$rez = $locked->invoke(null, $instance, static function () use (&$vyzovov): bool { $vyzovov++; return true; });
$zhurnal = (new ReflectionProperty(DBmysql::class, 'dbh'))->getValue($db)->log;
ok($rez === true && $vyzovov === 1 && $pauzy === [1] && uroven1131($db) === 0 && !TransactionScope::hasActive()
    && (array_count_values($zhurnal)['BEGIN'] ?? 0) === 2 && (array_count_values($zhurnal)['ROLLBACK'] ?? 0) === 1,
    '1213 — откат и повтор после паузы; вторая попытка прошла, транзакция не «потеряна»',
    json_encode(['rez' => $rez, 'vyzovov' => $vyzovov, 'pauzy' => $pauzy], JSON_UNESCAPED_UNICODE));

// Отказ внутри самой операции (1213) — операция выполняется заново, с полями объектов из базы.
$db = $podgotovit(); $pauzy = []; $popytki = [];
$db->selects["`glpi_plugin_itilflow_steps` WHERE `id`='9'"] = [['id' => 9, 'state' => 'running']];
$db->tables['glpi_plugin_itilflow_steps'] = [['id' => 9, 'state' => 'running']];
$shag = new Step();
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [INFO => ['было']];
$rez = $locked->invoke(null, $instance, static function () use (&$popytki, $shag): bool {
    CurrentRead::load($shag, 9);
    $popytki[] = $shag->fields['state'];
    \Session::addMessageAfterRedirect('сообщение попытки');
    if (count($popytki) === 1) {
        $shag->fields['state'] = 'done'; // изменение откаченной попытки в памяти
        throw dedlok1131('UPDATE glpi_plugin_itilflow_steps');
    }
    return true;
});
$soobshcheniya = array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]]));
ok($rez === true && $popytki === ['running', 'running'] && $pauzy === [1],
    '1213 внутри операции — повтор; объект, прочитанный в откаченной попытке, перечитан из базы', json_encode($popytki));
ok(count(array_keys($soobshcheniya, 'сообщение попытки', true)) === 1 && in_array('было', $soobshcheniya, true),
    'сообщения откаченной попытки не повторяются', json_encode($soobshcheniya, JSON_UNESCAPED_UNICODE));

// Все попытки отказаны — обычный отказ команды, без «потери транзакции» и без исключения.
$db = $podgotovit(); $pauzy = []; $vyzovov = 0;
$db->traps[] = static function (string $q): void {
    if (str_contains($q, '`glpi_plugin_itilflow_instances` WHERE id = 7 FOR UPDATE')) { throw dedlok1131($q); }
};
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
$rez = $locked->invoke(null, $instance, static function () use (&$vyzovov): bool { $vyzovov++; return true; });
$soobshcheniya = array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]]));
ok($rez === false && $vyzovov === 0 && $pauzy === [1, 2] && Engine::CONFLICT_TRIES === 3 && !TransactionScope::hasActive()
    && (bool) preg_grep('/заявку в этот момент меняли другие/u', $soobshcheniya),
    'три отказа подряд — команда не выполнена, понятное сообщение, транзакция не «потеряна»', json_encode($soobshcheniya, JSON_UNESCAPED_UNICODE));

// 1205 (ожидание блокировки — до 50 с) — без повтора: сразу понятный отказ, как после трёх неудач.
// Иначе три ожидания по 50 с — дольше таймаута nginx на проде (60 с).
$db = $podgotovit(); $pauzy = []; $vyzovov = 0;
$db->traps[] = static function (string $q) use (&$vyzovov): void {
    if (str_contains($q, '`glpi_plugin_itilflow_instances` WHERE id = 7 FOR UPDATE')) {
        $vyzovov++;
        throw new RuntimeException('MySQL query error: Lock wait timeout exceeded; try restarting transaction (1205) in SQL query "x".');
    }
};
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
$rez = $locked->invoke(null, $instance, static fn(): bool => true);
$soobshcheniya = array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]]));
$zhurnal = (new ReflectionProperty(DBmysql::class, 'dbh'))->getValue($db)->log;
ok($rez === false && $vyzovov === 1 && $pauzy === [] && !TransactionScope::hasActive() && uroven1131($db) === 0
    && (array_count_values($zhurnal)['ROLLBACK'] ?? 0) === 1 && in_array(Engine::CONFLICT_MESSAGE, $soobshcheniya, true),
    '1205 — одна попытка, без паузы и повтора; откат подтверждён, сообщение «…меняли другие. Повторите.»',
    json_encode(['vyzovov' => $vyzovov, 'pauzy' => $pauzy, 'soob' => $soobshcheniya], JSON_UNESCAPED_UNICODE));

// Ответ согласующего: он уже сохранён GLPI — после отказа из-за блокировок не «Повторите», а правда.
$otvet = new \TicketValidation();
$otvet->fields = ['id' => 101, 'tickets_id' => 55, 'entities_id' => 0, 'status' => \CommonITILValidation::ACCEPTED,
    'itemtype_target' => 'User', 'items_id_target' => 2, 'users_id_validate' => 2];
$otvet->updates = ['status'];
foreach (['новый порядок (structured_v1)' => true, 'прежний порядок' => false] as $poryadok => $novyjPoryadok) {
    $db = $podgotovit(); $pauzy = [];
    $db->tables['glpi_ticketvalidations'] = [$otvet->fields];
    $db->tables['glpi_plugin_itilflow_instances'] = [['id' => 7, 'itemtype' => 'Ticket', 'items_id' => 55, 'entities_id' => 0,
        'outcome_contract' => $novyjPoryadok ? 'structured_v1' : 'legacy', 'current_attempt_id' => 1, 'state' => 'running']];
    $db->tables['glpi_plugin_itilflow_steps'] = [['id' => 70, 'plugin_itilflow_instances_id' => 7, 'plugin_itilflow_stages_id' => 3,
        'execution_mode' => 'approval', 'state' => 'running', 'attempt_id' => 1, 'artifact_itemtype' => 'TicketValidation',
        'artifact_items_id' => 101, 'approver_items_ids' => $novyjPoryadok ? '' : '101',
        'approval_snapshot_source' => $novyjPoryadok ? 'definition' : '']];
    $db->tables[\GlpiPlugin\Itilflow\OutcomeValidation::getTable()] = $novyjPoryadok
        ? [['id' => 1, 'validation_itemtype' => 'TicketValidation', 'validations_id' => 101, 'plugin_itilflow_steps_id' => 70]] : [];
    $db->traps[] = static function (string $q): void {
        if (str_contains($q, '`glpi_plugin_itilflow_instances` WHERE id = 7 FOR UPDATE')) { throw dedlok1131($q); }
    };
    // Запись журнала доходит до INSERT: столбцы таблицы журнала — как в схеме.
    $db->selects['SHOW COLUMNS FROM `glpi_plugin_itilflow_violations`'] = array_map(static fn(string $f): array => ['Field' => $f],
        ['id', 'plugin_itilflow_instances_id', 'entities_id', 'users_id', 'itemtype', 'items_id', 'action', 'was_blocked', 'message', 'date']);
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    $vyshlo = '';
    try { Engine::validationUpdated($otvet); } catch (Throwable $e) { $vyshlo = get_class($e) . ': ' . $e->getMessage(); }
    $preduprezhdeniya = $_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING] ?? [];
    $soobshcheniya = array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]]));
    ok($vyshlo === '' && $pauzy === [1, 2] && in_array(Engine::VOTE_SAVED_MESSAGE, $preduprezhdeniya, true)
        && !in_array(Engine::CONFLICT_MESSAGE, $soobshcheniya, true),
        'ответ согласующего, три отказа (' . $poryadok . '): «Ваш ответ сохранён… учтёт при автоматической сверке», не «Повторите»',
        json_encode(['vyshlo' => $vyshlo, 'pauzy' => $pauzy, 'soob' => $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? null], JSON_UNESCAPED_UNICODE));
    $zapis = array_values(array_filter($db->sql, static fn(string $q): bool => str_contains($q, 'glpi_plugin_itilflow_violations')
        && str_contains($q, 'vote_failed')));
    ok(count($zapis) === 1, 'администратору — запись журнала «vote_failed» (' . $poryadok . ')', json_encode($zapis, JSON_UNESCAPED_UNICODE));
}
ok(str_contains(Engine::VOTE_SAVED_MESSAGE, 'сохранён') && str_contains(Engine::VOTE_SAVED_MESSAGE, 'автоматической сверке')
    && !str_contains(Engine::VOTE_SAVED_MESSAGE, 'Повторите'), 'текст согласующему — правда: ответ сохранён, учтёт сверка');
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

// Запуск маршрута (правилом, формой) — тот же исполнитель.
$istochnik = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Engine.php');
ok(str_contains($istochnik, 'return self::retrying(static fn(): ?Instance => self::startOnce(')
    && str_contains($istochnik, 'return self::retrying(static fn(): bool => self::lockedOnce('),
    'повтор — в одном месте (Engine::retrying) для команд и запуска');
(new ReflectionProperty(Engine::class, 'conflictPause'))->setValue(null, null);

// ---------------------------------------------------------------- 4. Обработчики не выпускают исключение в ядро
$_SESSION['glpiID'] = 5;
$followup = new \ITILFollowup();
$followup->fields = ['id' => 1, 'itemtype' => 'Ticket', 'items_id' => 55, 'users_id' => 5, 'is_private' => 0, 'content' => 'Ответ.'];
$val = new \TicketValidation();
$val->fields = ['id' => 101, 'tickets_id' => 55, 'status' => 3];
$val->updates = ['status'];
$sluchai = [
    'ошибка SQL (1213) при каждом чтении' => static function (): void {
        sbros1131();
        $db = (new ReflectionClass(FakeDBOtkaz1131::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DBmysql::class, 'dbh'))->setValue($db, new FakeDbh1131());
        (new ReflectionProperty(DBmysql::class, 'transaction_level'))->setValue($db, 0);
        $db->traps[] = static function (string $q): void { if (!str_contains($q, 'SAVEPOINT') && !str_contains($q, 'autocommit')) { throw dedlok1131($q); } };
        $GLOBALS['DB'] = $db;
    },
    'транзакция «потеряна» раньше в этом запросе' => static function (): void {
        fakeDb1131();
        try { TransactionScope::markLost('synthetic'); } catch (TransactionLost) {}
    },
    'соединения нет' => static function (): void { sbros1131(); $GLOBALS['DB'] = new stdClass(); },
];
foreach ($sluchai as $imya => $podmena) {
    $vyshlo = [];
    foreach ([
        'Hook::postFollowupAdd' => static fn() => Hook::postFollowupAdd($followup),
        'Engine::initiatorAnswered' => static fn() => Engine::initiatorAnswered($followup),
        'Hook::postValidationUpdate' => static fn() => Hook::postValidationUpdate($val),
        'Engine::validationUpdated' => static fn() => Engine::validationUpdated($val),
        'Engine::structuredValidationUpdated' => static fn() => Engine::structuredValidationUpdated($val),
    ] as $kto => $vyzov) {
        $podmena();
        try { $vyzov(); } catch (Throwable $e) { $vyshlo[] = $kto . ': ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 120); }
    }
    ok($vyshlo === [], 'обработчики комментария и ответа согласующего не выпускают исключение в ядро — ' . $imya, implode(' | ', $vyshlo));
}
sbros1131();
$hook = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Hook.php');
ok(str_contains($hook, "self::safely('postFollowupAdd'") && str_contains($hook, "self::safely('postValidationUpdate'"),
    'ITEM_ADD комментария и ITEM_UPDATE согласования — через Hook::safely');
unset($_SESSION['glpiID']);

// ---------------------------------------------------------------- 5. Догоняющая сверка
$rows = [
    11 => ['status' => \CommonITILValidation::ACCEPTED, 'users_id_validate' => 5, 'validation_date' => '2026-10-22 10:00:00'],
    12 => ['status' => \CommonITILValidation::ACCEPTED, 'users_id_validate' => 6, 'validation_date' => '2026-10-22 11:00:00'],
    13 => ['status' => \CommonITILValidation::REFUSED, 'users_id_validate' => 7, 'validation_date' => '2026-10-22 12:00:00'],
    14 => ['status' => \CommonITILValidation::WAITING, 'users_id_validate' => 0, 'validation_date' => null],
    15 => ['status' => \CommonITILValidation::ACCEPTED, 'users_id_validate' => 0, 'validation_date' => '2026-10-22 13:00:00'],
];
ok(Engine::decisiveVote($rows, Outcome::COMPLETED) === 12 && Engine::decisiveVote($rows, Outcome::REFUSED) === 13
    && Engine::decisiveVote([14 => $rows[14]], Outcome::COMPLETED) === null
    && Engine::decisiveVote([20 => $rows[11], 21 => $rows[11]], Outcome::COMPLETED) === 21,
    'сверка: ответ, решивший этап, — последний ответ нужного вида с автором');
$cron = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cron.php');
ok(str_contains($cron, 'Engine::catchUpApprovals()') && str_contains($cron, 'учесть пропущенные ответы согласующих'),
    'сверка идёт в автоматическом действии «Сроки этапов» (каждые 15 минут)');

// Источник перехода в автоматическом действии — автор применяемого действия (письмо, сверка).
$_SESSION['glpicronuserrunning'] = 'cron_itilflow_deadlines';
unset($_SESSION['glpiID']);
$proxy = new ReflectionProperty(Engine::class, 'proxyActor');
$proxy->setValue(null, 47806);
$vCron = Engine::sourceActor();
$proxy->setValue(null, 0);
$vPusto = Engine::sourceActor();
unset($_SESSION['glpicronuserrunning']);
$_SESSION['glpiID'] = 5;
$proxy->setValue(null, 47806);
$vVoshel = Engine::sourceActor();
$proxy->setValue(null, 0);
unset($_SESSION['glpiID']);
ok($vCron === 47806 && $vPusto === 0 && $vVoshel === 5,
    'в автоматическом действии этап переводит автор применяемого действия; при вошедшем — он сам', json_encode([$vCron, $vPusto, $vVoshel]));

$GLOBALS['DB'] = $savedDB;
sbros1131();
itog();
