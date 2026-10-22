<?php
namespace GlpiPlugin\Itilflow;

/** Свидетель живой внешней транзакции MySQL 8/MariaDB; не полагается на native счётчик. */
final class FormTransactionGuard
{
    public function __construct(private readonly ?\Closure $query = null, private readonly ?\Closure $depth = null,
        private readonly ?\Closure $scopeFactory = null) {}

    /** Локальная ошибка восстанавливается только до исходного свидетеля. */
    public function run(callable $operation, ?callable $recover = null): void
    {
        TransactionScope::assertHealthy();
        $level = $this->level();
        $row = $this->sql('SELECT @@session.autocommit AS autocommit');
        if ($level < 1 || !is_array($row) || (string) ($row['autocommit'] ?? '') !== '1') { $this->fatal(); }
        $name = 'itilflow_form_' . bin2hex(random_bytes(16));
        // При autocommit=1 вне транзакции SAVEPOINT не сохраняется. RELEASE обязан подтвердить его.
        $this->sql('SAVEPOINT ' . $name);
        $this->sql('RELEASE SAVEPOINT ' . $name);
        $this->sql('SAVEPOINT ' . $name);
        $scope = null;
        try {
            $scope = $this->scopeFactory ? ($this->scopeFactory)() : new TransactionScope();
            if ($this->level() !== $level + 1) { $this->fatal(); }
            $operation();
            if ($this->level() !== $level + 1) { $this->fatal(); }
            $scope->commit(); $scope = null;
            if ($this->level() !== $level) { $this->fatal(); }
            // Не создаём новый свидетель: COMMIT/ROLLBACK + BEGIN внутри operation не пройдут.
            $this->sql('RELEASE SAVEPOINT ' . $name);
        } catch (\Throwable $error) {
            TransactionScope::assertHealthy();
            if ($scope === null) { $this->fatal(); }
            try {
                $scope->rollback();
                if ($this->level() !== $level) { $this->fatal(); }
                $this->sql('RELEASE SAVEPOINT ' . $name);
            } catch (\Throwable) { TransactionScope::assertHealthy(); $this->fatal(); }
            if ($recover === null) { $this->fatal(); }
            // Ошибка записи квитанции — уже fatal; рекурсивного «успешного восстановления» нет.
            $this->run(static fn() => $recover($error));
        }
    }

    private function level(): int
    {
        if ($this->depth) { return ($this->depth)(); }
        global $DB;
        if (!$DB instanceof \DBmysql) { $this->fatal(); }
        return (int) (new \ReflectionProperty(\DBmysql::class, 'transaction_level'))->getValue($DB);
    }
    private function sql(string $sql): mixed
    {
        try {
            if ($this->query) { $result = ($this->query)($sql); }
            else {
                global $DB;
                $result = $DB->doQuery($sql);
                if (str_starts_with($sql, 'SELECT')) { $result = $result->fetch_assoc(); }
            }
            if ($result === false || $result === null) { $this->fatal(); }
            return $result;
        } catch (\Throwable) { $this->fatal(); }
    }
    private function fatal(): never { TransactionScope::markLost('form_outer_unconfirmed'); }
}
