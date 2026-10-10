<?php

namespace GlpiPlugin\Itilflow;

/** Собственный native уровень/ savepoint; чужая транзакция не завершается. */
final class TransactionScope
{
    private static int $owned = 0;
    private bool $open = false;

    public function __construct()
    {
        global $DB;
        // GLPI 11 DBmysql: begin/commit/rollback ведут счётчик уровней и savepoint.
        // Неизвестный адаптер не получает оптимистического допуска к записи.
        if (!$DB instanceof \DBmysql) { throw new \RuntimeException('Транзакционный адаптер не подтверждён.'); }
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $ref = new \ReflectionMethod($DB, $method);
            if (!$ref->isPublic() || $ref->getDeclaringClass()->getName() !== \DBmysql::class) {
                throw new \RuntimeException('Транзакционный адаптер не подтверждён.');
            }
        }
        $DB->beginTransaction();
        $this->open = true;
        self::$owned++;
    }

    public function commit(): void
    {
        global $DB;
        if (!$this->open) { throw new \LogicException('Уровень транзакции уже закрыт.'); }
        $DB->commit();
        $this->open = false;
        self::$owned--;
        // При внешней native транзакции это release savepoint, не durable commit.
    }

    public function rollback(): void
    {
        global $DB;
        if ($this->open) {
            // Не повторяем rollback после исключения: состояние уже неизвестно.
            $this->open = false;
            self::$owned--;
            $DB->rollBack();
        }
    }

    public static function requireActive(): void
    {
        if (self::$owned <= 0) { throw new \LogicException('Нет собственного уровня транзакции.'); }
    }

    public static function hasActive(): bool { return self::$owned > 0; }
}
