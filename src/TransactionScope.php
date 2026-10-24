<?php
namespace GlpiPlugin\Itilflow;

/** Свой native уровень и неизменный свидетель; потеря необратима в этом запросе. */
final class TransactionScope
{
    private static int $owned = 0;
    private static ?TransactionLost $lost = null;
    private bool $open = false;
    private int $before = 0;
    private string $outer = '';
    private string $inner = '';

    /** Closure — доверенный адаптер fault-тестов, не параметр пользовательской команды. */
    public function __construct(private readonly ?\Closure $adapter = null)
    {
        self::assertHealthy();
        try {
            $this->before = $this->call('depth');
            if ($this->before < 0 || (string) $this->call('autocommit') !== '1') { $this->fail('unsupported_state'); }
            if ($this->before > 0) {
                $this->outer = $this->witness();
                // SAVEPOINT вне транзакции при autocommit=1 может не дать ошибки; RELEASE обязан дать.
                $this->sql('RELEASE SAVEPOINT ' . $this->outer);
                $this->sql('SAVEPOINT ' . $this->outer);
            }
            $this->call('begin');
            $this->open = true;
            self::$owned++;
            $this->checkDepth($this->before + 1);
            $this->inner = $this->witness();
            $this->sql('RELEASE SAVEPOINT ' . $this->inner);
            $this->sql('SAVEPOINT ' . $this->inner);
        } catch (\Throwable $error) { $this->fail('begin_unconfirmed'); }
    }

    public function commit(): void
    {
        self::assertHealthy();
        if (!$this->open) { throw new \LogicException('Уровень транзакции уже закрыт.'); }
        try {
            $this->checkDepth($this->before + 1);
            // Именно старый свидетель: COMMIT/ROLLBACK + BEGIN не создаст его заново.
            $this->sql('RELEASE SAVEPOINT ' . $this->inner);
            $this->call('commit');
            $this->checkDepth($this->before);
            if ($this->outer !== '') { $this->sql('RELEASE SAVEPOINT ' . $this->outer); }
            $this->close();
        } catch (\Throwable $error) { $this->fail('commit_unconfirmed'); }
    }

    public function rollback(): void
    {
        self::assertHealthy();
        if (!$this->open) { return; }
        try {
            $this->checkDepth($this->before + 1);
            $this->sql('RELEASE SAVEPOINT ' . $this->inner);
            $this->call('rollback');
            $this->checkDepth($this->before);
            if ($this->outer !== '') { $this->sql('RELEASE SAVEPOINT ' . $this->outer); }
            $this->close();
        } catch (\Throwable $error) { $this->fail('rollback_unconfirmed'); }
    }

    /**
     * Сервер прервал транзакцию из-за блокировок: 1213 — взаимная блокировка (транзакция уже откачена
     * сервером целиком), 1205 — истекло ожидание блокировки (откачен оператор). GLPI 11 отдаёт ошибку
     * SQL исключением «MySQL query error: … (1213) in SQL query …»; mysqli в строгом режиме — кодом.
     */
    public static function isConflict(\Throwable $error): bool
    {
        return self::sqlError($error, [1205, 1213]);
    }

    /**
     * Только взаимная блокировка (1213): сервер выбрал жертву сразу, повтор дешёвый. 1205 — ожидание уже
     * длилось до innodb_lock_wait_timeout (50 с); повтор упёрся бы в таймаут nginx на проде (60 с).
     */
    public static function isDeadlock(\Throwable $error): bool
    {
        return self::sqlError($error, [1213]);
    }

    /** @param list<int> $codes */
    private static function sqlError(\Throwable $error, array $codes): bool
    {
        $pattern = '/^MySQL query error: .*\((?:' . implode('|', $codes) . ')\) in SQL query/s';
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof \mysqli_sql_exception && in_array((int) $e->getCode(), $codes, true)) { return true; }
            if (preg_match($pattern, $e->getMessage())) { return true; }
        }
        return false;
    }

    /**
     * Отказ в собственной транзакции верхнего уровня из-за блокировок (isConflict): состояние известно —
     * после ROLLBACK транзакции нет, — поэтому это не потеря, и команду можно повторить. Точек сохранения
     * уже нет: свидетеля не отпускаем, вложенные уровни ядра внутри команды сбрасываем. Во вложенной
     * транзакции сервер откатил и внешнюю — это потеря, как раньше.
     */
    public function rollbackAfterConflict(): void
    {
        self::assertHealthy();
        if (!$this->open) { throw new \LogicException('Уровень транзакции уже закрыт.'); }
        if ($this->before !== 0) { $this->fail('conflict_in_outer_transaction'); }
        try {
            if ($this->call('depth') !== 1) { $this->call('flatten'); }
            $this->call('rollback');
            $this->checkDepth(0);
            $this->close();
        } catch (\Throwable $error) { $this->fail('rollback_unconfirmed'); }
    }

    /**
     * Для catch, который продолжает работу после сбоя: отказ из-за блокировок не глотаем — сервер уже
     * откатил транзакцию, и дальнейшие записи пошли бы мимо неё. Его обработает исполнитель команды.
     */
    public static function keepGoing(\Throwable $error): void
    {
        self::assertHealthy();
        if (self::$owned > 0 && self::isConflict($error)) { throw $error; }
    }

    /** Catch обязан проверить sticky-состояние перед обычным отказом или продолжением. */
    public static function assertHealthy(): void
    {
        if (self::$lost !== null) { throw self::$lost; }
    }
    public static function markLost(string $reason): never
    {
        self::$lost ??= new TransactionLost($reason);
        throw self::$lost;
    }
    public static function requireActive(): void
    {
        self::assertHealthy();
        if (self::$owned <= 0) { throw new \LogicException('Нет собственного уровня транзакции.'); }
    }
    public static function hasActive(): bool { self::assertHealthy(); return self::$owned > 0; }

    /**
     * Уровень транзакций штатного соединения GLPI сейчас: больше нуля — идёт транзакция (ядра или своя).
     * Не DBmysql (проверки без базы) — 0.
     */
    public static function coreDepth(): int
    {
        global $DB;
        return $DB instanceof \DBmysql ? (int) (new \ReflectionProperty(\DBmysql::class, 'transaction_level'))->getValue($DB) : 0;
    }

    private function close(): void
    {
        if ($this->open) { $this->open = false; self::$owned--; }
    }
    private function fail(string $reason): never
    {
        $this->close();
        self::markLost($reason);
    }
    private function checkDepth(int $expected): void
    {
        if ($this->call('depth') !== $expected) { $this->fail('depth_changed'); }
    }
    private function witness(): string
    {
        $name = 'itilflow_scope_' . bin2hex(random_bytes(16));
        $this->sql('SAVEPOINT ' . $name);
        return $name;
    }
    private function sql(string $sql): void { $this->call('query', $sql); }
    private function call(string $operation, ?string $sql = null): mixed
    {
        self::assertHealthy();
        if ($this->adapter !== null) { $result = ($this->adapter)($operation, $sql); }
        else {
            global $DB;
            if (!$DB instanceof \DBmysql) { $this->fail('unknown_adapter'); }
            foreach (['beginTransaction','commit','rollBack'] as $method) {
                $ref = new \ReflectionMethod($DB, $method);
                if (!$ref->isPublic() || $ref->getDeclaringClass()->getName() !== \DBmysql::class) { $this->fail('unknown_adapter'); }
            }
            $result = match ($operation) {
                'depth' => (int) (new \ReflectionProperty(\DBmysql::class, 'transaction_level'))->getValue($DB),
                'autocommit' => $DB->doQuery('SELECT @@session.autocommit AS autocommit')->fetch_assoc()['autocommit'] ?? null,
                'query' => $DB->doQuery($sql),
                'begin' => $DB->beginTransaction(),
                'commit' => $DB->commit(),
                'rollback' => $DB->rollBack(),
                // После 1213 точек сохранения ядра нет: счётчик уровней ядра — к одному, затем ROLLBACK.
                'flatten' => (new \ReflectionProperty(\DBmysql::class, 'transaction_level'))->setValue($DB, 1) ?? true,
            };
        }
        // Native lifecycle methods возвращают void, а query обязан подтвердиться.
        if ($result === false || ($operation === 'query' && $result === null)) { $this->fail('adapter_failed'); }
        return $result;
    }
}
