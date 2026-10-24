<?php
/**
 * 1.13.2: исправления к 1.13.1 — без базы. Номер раздела — номер исправления. Где нужна база, — подменённое
 * соединение GLPI: записывает SQL и отдаёт заданные строки (как в blokirovki-1131).
 */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\{Engine, Step, Stage, TransactionScope, CurrentRead, Process, Instance, DefinitionSnapshot, ValidationAuthority,
    StartReadiness, Ui, Hook, TransactionLost};

final class FakeResult1132
{
    public int $num_rows;
    public function __construct(public array $rows) { $this->num_rows = count($rows); }
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
}

final class FakeDbh1132
{
    public array $log = [];
    public int $insert_id = 1;
    public function begin_transaction(): bool { $this->log[] = 'BEGIN'; return true; }
    public function commit(): bool { $this->log[] = 'COMMIT'; return true; }
    public function rollback(): bool { $this->log[] = 'ROLLBACK'; return true; }
    public function savepoint($name): bool { $this->log[] = 'SAVEPOINT ' . $name; return true; }
    public function release_savepoint($name): bool { $this->log[] = 'RELEASE ' . $name; return true; }
}

/** Соединение GLPI без сервера: обычное чтение — из $tables (с простыми условиями), текущее — из $selects. */
class FakeDB1132 extends DBmysql
{
    public array $sql = [];
    public array $requests = [];
    public array $tables = [];
    public array $selects = [];
    /** @var list<callable(string): void> */
    public array $traps = [];
    public function doQuery($query)
    {
        $this->sql[] = $query;
        foreach ($this->traps as $trap) { $trap($query); }
        if (str_starts_with($query, 'SELECT @@session.autocommit')) { return new FakeResult1132([['autocommit' => '1']]); }
        foreach ($this->selects as $needle => $rows) {
            if (str_contains($query, $needle)) { return new FakeResult1132(is_callable($rows) ? $rows($query) : $rows); }
        }
        return new FakeResult1132([]);
    }
    public function request($criteria)
    {
        $this->requests[] = $criteria;
        $rows = $this->tables[is_array($criteria) ? ($criteria['FROM'] ?? '') : ''] ?? [];
        $where = is_array($criteria) ? ($criteria['WHERE'] ?? []) : [];
        $rows = array_values(array_filter($rows, static function (array $row) use ($where): bool {
            foreach ($where as $field => $value) {
                if (!is_string($field)) {
                    // [['id' => ['>', 5]]]
                    foreach ((array) $value as $f => $cond) {
                        if (is_array($cond) && ($cond[0] ?? '') === '>' && !((int) ($row[$f] ?? 0) > (int) $cond[1])) { return false; }
                    }
                    continue;
                }
                if (!array_key_exists($field, $row)) { continue; }
                $values = is_array($value) ? array_map('strval', $value) : [(string) $value];
                if (!in_array((string) $row[$field], $values, true)) { return false; }
            }
            return true;
        }));
        $limit = is_array($criteria) ? (int) ($criteria['LIMIT'] ?? 0) : 0;
        if ($limit > 0) { $rows = array_slice($rows, 0, $limit); }
        $out = [];
        foreach ($rows as $row) { $out[(int) ($row['id'] ?? count($out))] = $row; }
        return new ArrayIterator($out);
    }
    public function numrows($result) { return $result->num_rows; }
    public function fetchAssoc($result) { return $result->fetch_assoc(); }
}

function sbros1132(): void
{
    (new ReflectionProperty(TransactionScope::class, 'lost'))->setValue(null, null);
    (new ReflectionProperty(TransactionScope::class, 'owned'))->setValue(null, 0);
}

function fakeDb1132(int $level = 0): FakeDB1132
{
    sbros1132();
    $db = (new ReflectionClass(FakeDB1132::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(DBmysql::class, 'dbh'))->setValue($db, new FakeDbh1132());
    (new ReflectionProperty(DBmysql::class, 'transaction_level'))->setValue($db, $level);
    $GLOBALS['DB'] = $db;
    return $db;
}

function dedlok1132(string $sql = 'SELECT 1'): RuntimeException
{
    return new RuntimeException('MySQL query error: Deadlock found when trying to get lock; try restarting transaction (1213) in SQL query "'
        . $sql . '".');
}

$savedDB = $GLOBALS['DB'] ?? null;
$_SESSION = [];
$koren = dirname(__DIR__, 2);

// ---------------------------------------------------------------- 4. Правило другого маршрута и «Запускать, если»
ok(StartReadiness::rulesSkipFormsLine(['Заказ'], 1) === 'Для заявок с формы «Заказ» правило не сработает: там маршрут выбирает «Запускать, если».'
    && StartReadiness::rulesSkipFormsLine(['А', 'Б', 'В', 'Г'], 2)
        === 'Для заявок с форм «А», «Б» и ещё 2 правила не сработают: там маршрут выбирает «Запускать, если».'
    && StartReadiness::rulesSkipFormsLine([], 1) === null && StartReadiness::rulesSkipFormsLine(['А'], 0) === null,
    '4: строка готовности — какие формы правило маршрута не запустит; без форм или без правил — строки нет');

// ---------------------------------------------------------------- 6. «Засчитать согласование»: засчитать нечего
ok(Ui::nothingToAdopt(true, true) === 'В заявке нет подходящего согласования. Уже отвеченное согласование засчитывает администратор маршрутов.'
    && Ui::nothingToAdopt(false, true) === 'В заявке нет подходящего согласования. Укажите согласующего — ему уйдёт запрос.'
    && Ui::nothingToAdopt(false, false) === 'В заявке нет подходящего согласования. Обратитесь к администратору маршрутов.',
    '6: засчитать нечего — сказано, кто поможет');
$shablon = (string) file_get_contents($koren . '/templates/flow_action.html.twig');
ok(str_contains($shablon, "{{ a.empty_text|default('В заявке нет подходящего согласования.') }}"), '6: текст — в форме действия');

// ---------------------------------------------------------------- 7. Повтор после уточнения — все адресаты прохода
$db = fakeDb1132();
$db->tables['glpi_plugin_itilflow_outcomevalidations'] = [
    ['id' => 1, 'plugin_itilflow_steps_id' => 70, 'validations_id' => 501, 'validation_itemtype' => 'TicketValidation',
        'itemtype_target' => 'User', 'items_id_target' => 11, 'binding_kind' => 'created'],
    ['id' => 2, 'plugin_itilflow_steps_id' => 70, 'validations_id' => 502, 'validation_itemtype' => 'TicketValidation',
        'itemtype_target' => 'User', 'items_id_target' => 12, 'binding_kind' => 'created'],
    ['id' => 3, 'plugin_itilflow_steps_id' => 70, 'validations_id' => 503, 'validation_itemtype' => 'TicketValidation',
        'itemtype_target' => 'Group', 'items_id_target' => 7, 'binding_kind' => 'created'],
];
$prohod = new Step();
$prohod->fields = ['id' => 70, 'execution_mode' => Stage::MODE_APPROVAL, 'artifact_itemtype' => 'TicketValidation',
    'artifact_items_id' => 501, 'approver_items_ids' => '', 'approval_dropped' => json_encode([
        ['id' => 502, 'itemtype_target' => 'User', 'items_id_target' => 12, 'reason' => 'снят'],
        ['id' => 503, 'itemtype_target' => 'Group', 'items_id_target' => 7, 'reason' => 'снят']])];
$adresaty = Engine::passTargets($prohod);
sort($adresaty);
ok($adresaty === [['Group', 7], ['User', 11], ['User', 12]],
    '7: повтор спрашивает всех адресатов прохода — и уже одобрившего (11), и тех, чьи запросы сняты при уточнении',
    json_encode($adresaty));
// Прежняя строка номеров (запуски до 1.11): адресаты отвеченных запросов — из самих запросов.
$db->tables['glpi_ticketvalidations'] = [['id' => 601, 'itemtype_target' => 'User', 'items_id_target' => 21, 'status' => 3]];
$prezhnij = new Step();
$prezhnij->fields = ['id' => 71, 'execution_mode' => Stage::MODE_APPROVAL, 'artifact_itemtype' => 'TicketValidation',
    'artifact_items_id' => 601, 'approver_items_ids' => '601,602', 'approval_dropped' => json_encode([
        ['id' => 602, 'itemtype_target' => 'User', 'items_id_target' => 22, 'reason' => 'снят']])];
$adresaty = Engine::passTargets($prezhnij);
sort($adresaty);
ok($adresaty === [['User', 21], ['User', 22]], '7: прежняя строка номеров — тоже все адресаты прохода', json_encode($adresaty));

// ---------------------------------------------------------------- 8. Ответ письмом — только сеанс сборщика почты
$k8 = ['users_id' => 47, 'is_private' => 0];
ok(Engine::answerAuthor($k8, ['_mailgate' => 1], 0, 'cron_mailgate') === 47 && Engine::MAILGATE_CRON === 'cron_mailgate',
    '8: письмо в сеансе сборщика почты (cron_mailgate) — ответ инициатора');
ok(Engine::answerAuthor($k8, ['_mailgate' => 1], 0, 'cron_itilflow_deadlines') === 0
    && Engine::answerAuthor($k8, ['_mailgate' => 1], 0, 'cron_queuednotification') === 0
    && Engine::answerAuthor($k8, ['_mailgate' => 1], 0, '') === 0,
    '8: любое другое автоматическое действие с подставленным «_mailgate» — не ответ');

// ---------------------------------------------------------------- 10. Общие строки — общей блокировкой
ok(CurrentRead::lockClause(Process::class) === ' LOCK IN SHARE MODE' && CurrentRead::lockClause(new Stage()) === ' LOCK IN SHARE MODE'
    && CurrentRead::lockClause(\User::class) === ' LOCK IN SHARE MODE',
    '10: маршрут и его этапы команда читает общей блокировкой — команды по разным заявкам одного маршрута не ждут друг друга');
ok(CurrentRead::lockClause(Step::class) === ' FOR UPDATE' && CurrentRead::lockClause(Instance::class) === ' FOR UPDATE'
    && CurrentRead::lockClause(\Ticket::class) === ' FOR UPDATE' && CurrentRead::lockClause(\TicketValidation::class) === ' FOR UPDATE',
    '10: этапы запусков, запуски, заявки и запросы согласования — по-прежнему исключительной');

$db = fakeDb1132();
$scope = new TransactionScope();
$db->selects['`glpi_plugin_itilflow_processes` WHERE id=5'] = [['id' => 5, 'name' => 'М', 'version' => 1, 'outcome_contract' => 'structured_v1']];
$db->selects['`glpi_plugin_itilflow_stages` WHERE plugin_itilflow_processes_id=5'] = [];
$p5 = new Process(); $p5->fields = ['id' => 5];
try { DefinitionSnapshot::capture($p5); } catch (Throwable) {}
$zapros = array_values(array_filter($db->sql, static fn(string $q): bool => str_contains($q, 'glpi_plugin_itilflow_processes')
    || str_contains($q, 'glpi_plugin_itilflow_stages')));
ok(count($zapros) === 2 && str_ends_with($zapros[0], ' LOCK IN SHARE MODE') && str_ends_with($zapros[1], ' LOCK IN SHARE MODE'),
    '10: запуск читает маршрут и его этапы общей блокировкой — запуски одного маршрута не выстраиваются в очередь',
    json_encode($zapros, JSON_UNESCAPED_UNICODE));
$scope->rollback();

// Заместитель отвечает за отсутствующего: строка отсутствующего — общей блокировкой, не FOR UPDATE.
$db = fakeDb1132();
$_SESSION['glpiID'] = 31;
$db->selects["`glpi_ticketvalidations` WHERE `id`='900'"] = [['id' => 900, 'tickets_id' => 55, 'entities_id' => 0,
    'itemtype_target' => 'User', 'items_id_target' => 30, 'status' => 2]];
$db->selects['`glpi_validatorsubstitutes` WHERE'] = [['id' => 1, 'users_id' => 30, 'users_id_substitute' => 31]];
$db->selects['`glpi_users` WHERE id=30'] = [['id' => 30]];
$v = new \TicketValidation();
$v->fields = ['id' => 900];
try { ValidationAuthority::canAnswer($v); } catch (Throwable) {}
$stroka = array_values(array_filter($db->sql, static fn(string $q): bool => str_contains($q, '`glpi_users` WHERE id=30')));
ok(count($stroka) === 1 && str_ends_with($stroka[0], ' LOCK IN SHARE MODE') && !str_contains($stroka[0], 'FOR UPDATE'),
    '10: два заместителя одного согласующего не берут его строку исключительной блокировкой', json_encode($stroka, JSON_UNESCAPED_UNICODE));
unset($_SESSION['glpiID']);
sbros1132();

$sync = (string) file_get_contents($koren . '/src/StatusSync.php');
ok(str_contains($sync, "lockedRow(\\User::getTable(),(int)\$step->fields['status_sync_actor'], CurrentRead::lockClause(\\User::class))"),
    '10: синхронизация статуса читает строку человека общей блокировкой');
ok(substr_count($sync, 'TransactionScope::isConflict($e)') >= 3 && substr_count($sync, 'rollbackAfterConflict()') >= 3
    && str_contains($sync, "'conflict_retry'") && str_contains($sync, 'requeueAfterConflict'),
    '10: отказ сервера из-за блокировок не теряет проход синхронизации — запрос остаётся или возвращается в очередь');

// Этапы своего запуска — по индексу запуска, без условия по state в запросе с блокировкой (MySQL 8 мог вести
// такой запрос по индексу state и блокировать идущие этапы других заявок).
$db = fakeDb1132();
$scope = new TransactionScope();
$db->selects['`glpi_plugin_itilflow_steps` WHERE `plugin_itilflow_instances_id`'] = [
    ['id' => 81, 'plugin_itilflow_instances_id' => 8, 'state' => 'done', 'ranking' => 10],
    ['id' => 82, 'plugin_itilflow_instances_id' => 8, 'state' => 'running', 'ranking' => 20]];
$db->selects["`glpi_plugin_itilflow_steps` WHERE `id`='82'"] = [['id' => 82, 'plugin_itilflow_instances_id' => 8, 'state' => 'running', 'ranking' => 20]];
$zapusk8 = new Instance(); $zapusk8->fields = ['id' => 8];
$db->sql = [];
$tekushchij = $zapusk8->getCurrentStep();
$poEtapam = array_values(array_filter($db->sql, static fn(string $q): bool => str_contains($q, '`glpi_plugin_itilflow_steps`')
    && str_contains($q, 'FOR UPDATE')));
$scope->rollback();
ok((int) $tekushchij?->getID() === 82 && $poEtapam !== [] && !preg_grep('/`state`/', $poEtapam),
    '10: текущий этап в команде ищется среди этапов своего запуска, без условия по state в запросе с блокировкой',
    json_encode($poEtapam, JSON_UNESCAPED_UNICODE));
$avt = (string) file_get_contents($koren . '/src/Authorizer.php');
ok(!str_contains($sync, "AND state='running'") && !preg_match("/'state' => Step::RUNNING,\\s*\\]\\);/", $avt),
    '10: проверка этапа (Authorizer) и синхронизация статуса — тоже по индексу запуска');
sbros1132();

// ---------------------------------------------------------------- 11. Повтор запуска помнит, какой заявку увидели
(new ReflectionProperty(Engine::class, 'conflictPause'))->setValue(null, static function (int $n): void {});
$db = fakeDb1132();
$chteniy = 0; $zaprosovZapuska = 0;
$db->selects["`glpi_tickets` WHERE `id`='55'"] = static function (string $q) use (&$chteniy): array {
    // Первая попытка видит заявку как её увидело правило; к повтору её уже изменили.
    return [['id' => 55, 'entities_id' => 0, 'status' => 1, 'is_deleted' => 0, 'name' => ++$chteniy === 1 ? 'Монитор' : 'Изменено', 'content' => 'x']];
};
$db->tables['glpi_tickets'] = [['id' => 55, 'entities_id' => 0, 'status' => 1, 'is_deleted' => 0, 'name' => 'Изменено', 'content' => 'x']];
$db->traps[] = static function (string $q) use (&$zaprosovZapuska): void {
    if (str_contains($q, '`glpi_plugin_itilflow_instances` WHERE') && ++$zaprosovZapuska === 1) { throw dedlok1132($q); }
};
$zayavka = new \Ticket();
$zayavka->fields = ['id' => 55, 'entities_id' => 0, 'status' => 1, 'is_deleted' => 0, 'name' => 'Монитор', 'content' => 'x'];
$rez11 = (new ReflectionMethod(Engine::class, 'startTransaction'))->invoke(null, $zayavka, 5, true);
ok($rez11 === null && $chteniy === 2 && $zaprosovZapuska === 1,
    '11: после 1213 заявку изменили — повтор сравнивает с тем, какой её увидело правило, и маршрут не запускает',
    json_encode(['chteniy' => $chteniy, 'zapuskov' => $zaprosovZapuska]));
(new ReflectionProperty(Engine::class, 'conflictPause'))->setValue(null, null);
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
sbros1132();

// ---------------------------------------------------------------- 12. Повтор не повторяет хуки других плагинов
$pauzy = [];
(new ReflectionProperty(Engine::class, 'conflictPause'))->setValue(null, static function (int $n) use (&$pauzy): void { $pauzy[] = $n; });
$locked = new ReflectionMethod(Engine::class, 'locked');
$zapusk = new Instance();
$zapusk->fields = ['id' => 7];
$podgotovit = static function (): FakeDB1132 {
    $db = fakeDb1132();
    $db->selects['`glpi_plugin_itilflow_instances` WHERE id = 7 FOR UPDATE'] = [['id' => 7, 'itemtype' => 'Ticket', 'items_id' => 55,
        'outcome_contract' => 'legacy', 'current_attempt_id' => 0]];
    return $db;
};
(new ReflectionProperty(\Plugin::class, 'plugins_initialized'))->setValue(null, true);
(new ReflectionProperty(\Plugin::class, 'activated_plugins'))->setValue(null, ['chuzhoj', 'ai_da']);
$hooksBylo = $GLOBALS['PLUGIN_HOOKS'] ?? [];
$GLOBALS['PLUGIN_HOOKS'] = ['item_add' => ['chuzhoj' => ['Ticket' => 'chuzhoj_add'], 'ai_da' => ['Item_Ticket' => 'f'],
    'itilflow' => ['Ticket' => 'f']], 'item_update' => ['vyklyuchen' => ['Ticket' => 'f']]];
ok(Engine::foreignHooks() === ['chuzhoj'],
    '12: подписан другой включённый плагин на заявку — найден; подписка на другой объект, выключенный плагин и свои хуки — нет',
    json_encode(Engine::foreignHooks()));

$db = $podgotovit(); $pauzy = []; $vyzovov = 0;
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
$rez = $locked->invoke(null, $zapusk, static function () use (&$vyzovov): bool {
    $vyzovov++;
    throw dedlok1132('INSERT INTO glpi_tickets');
});
$soob = array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]]));
ok($rez === false && $vyzovov === 1 && $pauzy === [] && !TransactionScope::hasActive() && in_array(Engine::CONFLICT_MESSAGE, $soob, true),
    '12: 1213 после того, как команда начала менять объекты, на которые подписан другой плагин, — без повтора, понятный отказ',
    json_encode(['vyzovov' => $vyzovov, 'pauzy' => $pauzy, 'soob' => $soob], JSON_UNESCAPED_UNICODE));

$db = $podgotovit(); $pauzy = []; $vyzovov = 0; $raz = 0;
$db->traps[] = static function (string $q) use (&$raz): void {
    if (str_contains($q, '`glpi_plugin_itilflow_instances` WHERE id = 7 FOR UPDATE') && $raz++ === 0) { throw dedlok1132($q); }
};
$rez = $locked->invoke(null, $zapusk, static function () use (&$vyzovov): bool { $vyzovov++; return true; });
ok($rez === true && $vyzovov === 1 && $pauzy === [1],
    '12: 1213 ещё на блокировке своих строк (до изменений) — повтор, как раньше', json_encode(['vyzovov' => $vyzovov, 'pauzy' => $pauzy]));

$GLOBALS['PLUGIN_HOOKS'] = ['item_add' => ['ai_da' => ['Item_Ticket' => 'f']]];
$db = $podgotovit(); $pauzy = []; $vyzovov = 0;
$rez = $locked->invoke(null, $zapusk, static function () use (&$vyzovov): bool {
    if (++$vyzovov === 1) { throw dedlok1132('INSERT INTO glpi_tickets'); }
    return true;
});
ok(Engine::foreignHooks() === [] && $rez === true && $vyzovov === 2 && $pauzy === [1],
    '12: других подписок на объекты маршрута нет — 1213 внутри команды повторяется, как раньше', json_encode(['vyzovov' => $vyzovov, 'pauzy' => $pauzy]));
$GLOBALS['PLUGIN_HOOKS'] = $hooksBylo;
(new ReflectionProperty(Engine::class, 'conflictPause'))->setValue(null, null);
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
$sync = (string) file_get_contents($koren . '/src/StatusSync.php');
ok(str_contains($sync, 'Engine::foreignHooks() === [] ? self::QUEUED : self::FAILED'),
    '12: синхронизация статуса после отказа из-за блокировок сама не повторяет, если на заявку подписаны другие плагины');
sbros1132();

// ---------------------------------------------------------------- 13. Потеря транзакции внутри транзакции GLPI — ядру
$isxod = static function (int $uroven, callable $obrabotchik): string {
    fakeDb1132($uroven);
    try { Hook::safely('proverka', $obrabotchik); return 'проглочено'; }
    catch (TransactionLost) { return 'потеря — ядру'; }
    catch (Throwable $e) { return 'ядру: ' . get_class($e); }
};
$poterya = static function (): void { TransactionScope::markLost('synthetic'); };
$proglotil = static function (): void { try { TransactionScope::markLost('synthetic'); } catch (TransactionLost) {} };
$dedlok = static function (): void { throw dedlok1132('INSERT INTO glpi_plugin_itilflow_outcomes'); };
$obychnaya = static function (): void { throw new RuntimeException('обычный сбой'); };
$v13 = [$isxod(1, $poterya), $isxod(1, $proglotil), $isxod(1, $dedlok), $isxod(1, $obychnaya),
    $isxod(0, $poterya), $isxod(0, $proglotil), $isxod(0, $dedlok), $isxod(0, $obychnaya)];
ok(array_slice($v13, 0, 3) === ['потеря — ядру', 'потеря — ядру', 'ядру: RuntimeException'] && $v13[3] === 'проглочено',
    '13: внутри транзакции GLPI (форма, слияние) потеря транзакции и отказ из-за блокировок уходят в ядро — операция откатится целиком',
    json_encode($v13, JSON_UNESCAPED_UNICODE));
ok(array_slice($v13, 4) === ['проглочено', 'проглочено', 'проглочено', 'проглочено'],
    '13: вне транзакции GLPI (обычный комментарий, ответ согласующего) — как раньше, исключение в ядро не выходит',
    json_encode($v13, JSON_UNESCAPED_UNICODE));
sbros1132();

// ---------------------------------------------------------------- 14. Сверка проверяет, кто ответил
$db = fakeDb1132();
$db->tables['glpi_groups_users'] = [['id' => 1, 'groups_id' => 7, 'users_id' => 40]];
$db->tables['glpi_validatorsubstitutes'] = [['id' => 1, 'users_id' => 30, 'users_id_substitute' => 31]];
$db->selects['`glpi_users` WHERE id=30'] = [['id' => 30]];
$da = ['status' => \CommonITILValidation::ACCEPTED];
ok(Engine::answersByAddressees([1 => $da + ['itemtype_target' => 'User', 'items_id_target' => 30, 'users_id_validate' => 30]])
    && Engine::answersByAddressees([1 => $da + ['itemtype_target' => 'User', 'items_id_target' => 30, 'users_id_validate' => 31]])
    && Engine::answersByAddressees([1 => $da + ['itemtype_target' => 'Group', 'items_id_target' => 7, 'users_id_validate' => 40]])
    && Engine::answersByAddressees([1 => ['status' => \CommonITILValidation::WAITING, 'itemtype_target' => 'User',
        'items_id_target' => 30, 'users_id_validate' => 0]]),
    '14: ответ адресата, его действующего заместителя и участника группы сверка принимает; неотвеченный не мешает');
ok(!Engine::answersByAddressees([1 => $da + ['itemtype_target' => 'Group', 'items_id_target' => 7, 'users_id_validate' => 41]])
    && !Engine::answersByAddressees([1 => $da + ['itemtype_target' => 'User', 'items_id_target' => 30, 'users_id_validate' => 32]])
    && !Engine::answersByAddressees([1 => $da + ['itemtype_target' => 'User', 'items_id_target' => 30, 'users_id_validate' => 30],
        2 => ['status' => \CommonITILValidation::REFUSED, 'itemtype_target' => 'Group', 'items_id_target' => 7, 'users_id_validate' => 41]]),
    '14: ответ того, кто не в группе и не заместитель, — сверка этап не трогает');
$db->selects['`glpi_users` WHERE id=30'] = []; // замещение закончилось
ok(!Engine::answersByAddressees([1 => $da + ['itemtype_target' => 'User', 'items_id_target' => 30, 'users_id_validate' => 31]]),
    '14: заместителя сняли (срок замещения кончился) — его ответ сверка не засчитывает');
$istochnik = (string) file_get_contents($koren . '/src/Engine.php');
ok(str_contains($istochnik, 'if (!self::answersByAddressees($rows)) { return null; }'), '14: проверка — в плане сверки (catchUpPlan)');
sbros1132();

// ---------------------------------------------------------------- 15. Сверка видит все идущие этапы
$db = fakeDb1132();
$shagi = [];
for ($k = 1; $k <= 450; $k++) {
    $shagi[] = ['id' => $k, 'state' => 'running', 'execution_mode' => 'approval', 'plugin_itilflow_instances_id' => 0,
        'artifact_itemtype' => 'TicketValidation', 'artifact_items_id' => 0, 'approver_items_ids' => '', 'approval_snapshot_source' => 'definition'];
}
$shagi[439]['approver_items_ids'] = '9003'; // этап 440 — прежняя строка номеров
$shagi[439]['approval_snapshot_source'] = '';
$db->tables['glpi_plugin_itilflow_steps'] = $shagi;
$db->tables['glpi_plugin_itilflow_outcomevalidations'] = [
    ['id' => 1, 'plugin_itilflow_steps_id' => 430, 'validations_id' => 9001, 'validation_itemtype' => 'TicketValidation'],
    ['id' => 2, 'plugin_itilflow_steps_id' => 20, 'validations_id' => 9002, 'validation_itemtype' => 'TicketValidation'],
];
$db->tables['glpi_ticketvalidations'] = [['id' => 9001, 'status' => 3], ['id' => 9002, 'status' => 2], ['id' => 9003, 'status' => 4]];
$stranica = [];
foreach ($shagi as $r) { $stranica[$r['id']] = $r; }
ok(Engine::answeredSteps($stranica) === [430, 440],
    '15: подробная сверка — только этапы, где на запрос уже ответили (привязки и прежняя строка номеров)', json_encode(Engine::answeredSteps($stranica)));
$db->requests = [];
Engine::catchUpApprovals();
$stranicy = array_values(array_filter($db->requests, static fn($c): bool => is_array($c) && ($c['FROM'] ?? '') === 'glpi_plugin_itilflow_steps'
    && isset($c['WHERE'][0]['id'])));
$zagruzheny = array_values(array_filter(array_map(static fn($c): int => is_array($c) && ($c['FROM'] ?? '') === 'glpi_plugin_itilflow_steps'
    ? (int) ($c['WHERE']['glpi_plugin_itilflow_steps.id'] ?? 0) : 0, $db->requests)));
ok(count($stranicy) === 3 && array_map(static fn(array $c): int => (int) ($c['WHERE'][0]['id'][1] ?? -1), $stranicy) === [0, 200, 400]
    && $zagruzheny === [430, 440],
    '15: 450 идущих этапов — три страницы по 200, этапы 430 и 440 (за первыми двумястами) сверены',
    json_encode(['stranicy' => count($stranicy), 'zagruzheny' => $zagruzheny]));
sbros1132();

// ---------------------------------------------------------------- 16. Ответ инициатора после сбоя догоняет сверка
$cron = (string) file_get_contents($koren . '/src/Cron.php');
$flow = (string) file_get_contents($koren . '/src/StructuredFlow.php');
$ui = (string) file_get_contents($koren . '/src/Ui.php');
ok(str_contains($cron, 'Engine::catchUpAnswers()') && str_contains($cron, 'учесть пропущенные ответы согласующих и инициаторов'),
    '16: «Сроки этапов» догоняют и ответы инициаторов');
ok(substr_count($flow, "'continue_failed', self::ANSWER_FAILED, 'ITILFollowup', (int) \$followup->getID()") === 2
    && str_contains($flow, "hash('sha256', 'initiator_answer:' . \$followup->getID())"),
    '16: оба пути сбоя пишут номер комментария; продолжение — тем же ключом команды, что живой ответ (без двойного продолжения)');
ok(str_contains(Engine::ANSWER_FAILED, '«Сроки этапов»') && !str_contains(Engine::ANSWER_FAILED, 'Проверьте этап')
    && str_contains($ui, "'answer_caught_up'"),
    '16: администратору — правда: маршрут продолжит сверка; запись сверки в журнале — без отметки «не по порядку»');
$db = fakeDb1132();
$db->tables['glpi_plugin_itilflow_attempts'] = array_map(static fn(int $k): array => ['id' => $k, 'state' => 'waiting_input',
    'plugin_itilflow_instances_id' => 0], range(1, 230));
$db->requests = [];
ok(Engine::catchUpAnswers() === 0 && count(array_filter($db->requests, static fn($c): bool => is_array($c)
    && ($c['FROM'] ?? '') === 'glpi_plugin_itilflow_attempts' && isset($c['WHERE'][0]['id']))) === 2,
    '16: запуски, ждущие ответа, — все, страницами по 200');
sbros1132();
// Комментарий и письмо в один момент (MySQL 8): маршрут продолжил первый — второму записи «не продолжился» не нужно.
ok(Engine::answerStillAwaited('waiting_input') && !Engine::answerStillAwaited('running') && !Engine::answerStillAwaited(null)
    && str_contains($flow . (string) file_get_contents($koren . '/src/StructuredFlow.php'),
        "if (self::answerStillAwaited(Attempt::current(\$instance)?->fields['state'] ?? null)) {"),
    '16: запись «сразу не продолжился» — только если запуск всё ещё ждёт ответа (не когда маршрут продолжил другой ответ)');

// ---------------------------------------------------------------- 19. Схема: пройденный возврат — словами, без номера попытки
ok(\GlpiPlugin\Itilflow\Scheme::historyLabel('returned') === 'Возврат на уточнение'
    && \GlpiPlugin\Itilflow\Scheme::historyLabel('refused') === 'Начато заново'
    && \GlpiPlugin\Itilflow\Scheme::historyLabel('aborted') === 'Начато заново',
    '19: линия пройденного перехода — «Возврат на уточнение» или «Начато заново»');
ok(!str_contains((string) file_get_contents($koren . '/src/Scheme.php'), 'Выполненный возврат: попытка'),
    '19: служебной подписи «Выполненный возврат: попытка N» на схеме нет');

// ---------------------------------------------------------------- 21. Учебник в каталоге плагина — как на экране
$imyaDocherney = \GlpiPlugin\Itilflow\StepLine::childName('Выдача', 12345);
$tut = (string) @file_get_contents($koren . '/docs/TUTORIAL.md');
$tutEn = (string) @file_get_contents($koren . '/docs/TUTORIAL.en.md');
ok($imyaDocherney === 'Выдача — к заявке №12345' && str_contains($tut, '«Выдача — к заявке №…»') && str_contains($tutEn, '“Выдача — к заявке №…”')
    && !preg_match('/\[Этап \d+(, попытка \d+)?\]/u', $tut . $tutEn),
    '21: учебник называет дочернюю заявку так же, как плагин («Выдача — к заявке №…»), без «[Этап 20, попытка 1]»');
ok(str_contains($tut, 'Этап 10 — «Согласовано»') && str_contains($tutEn, '“Согласовано” (Approved)'),
    '21: одобренный этап согласования в учебнике — «Согласовано», как в строке этапа');

// ---------------------------------------------------------------- 22. Шаг 0 учебника называет все поля снимка формы
$shag0 = (string) preg_replace('/^.*?## Шаг 0\.(.*?)\n## Шаг 1\..*$/su', '$1', $tut);
$polyaSnimka = ['Что нужно', 'Срочно', 'Руководитель', 'Комментарий', 'Когда нужно', 'Что ещё сделать']; // docs/img/form-submit.png
ok($shag0 !== $tut && array_filter($polyaSnimka, static fn(string $p): bool => !str_contains($shag0, '«' . $p . '»')) === []
    && str_contains($shag0, 'необязательные, маршрут на них не опирается'),
    '22: шаг 0 называет все шесть полей снимка формы и говорит, что два из них маршруту не нужны');

// ---------------------------------------------------------------- 23. Шаг 7 учебника цитирует предупреждение готовности
$primechanie = StartReadiness::findings(1, 'Назначение «Заявка»', [['id' => 1, 'name' => 'Пример: выдача оборудования', 'start' => []]],
    [['created' => 'yes', 'complete' => true, 'yes' => [], 'unknown' => [], 'case' => '«Что нужно» — «Доступ к сетевой папке»']])['notes'][0] ?? '';
$shag7 = (string) preg_replace('/\s+/u', ' ', (string) preg_replace('/^.*?## Шаг 7\.(.*?)\n## Шаг 8\..*$/su', '$1', $tut));
ok($primechanie === 'Назначение «Заявка»: если «Что нужно» — «Доступ к сетевой папке», не запускается ни один маршрут — заявка останется без маршрута.'
    && str_contains($shag7, '«' . $primechanie . '»') && str_contains($shag7, 'Это не ошибка'),
    '23: шаг 7 приводит предупреждение готовности дословно — так, как его пишет плагин, — и говорит, что это не ошибка',
    mb_substr($shag7, 0, 400));

$GLOBALS['DB'] = $savedDB;
sbros1132();
itog();
