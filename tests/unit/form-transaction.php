<?php
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\FormTransactionGuard;
use GlpiPlugin\Itilflow\TransactionScope;
use GlpiPlugin\Itilflow\TransactionLost;
final class TransactionWitnessFixture {
    public int $depth = 1; public int $autocommit = 1; public bool $active = true; public array $savepoints = []; public array $writes = [];
    public function query(string $sql): mixed {
        if (str_starts_with($sql, 'SELECT')) { return ['autocommit' => $this->autocommit]; }
        if (str_starts_with($sql, 'SAVEPOINT ')) { if ($this->active) { $this->savepoints[substr($sql, 10)] = true; } return true; }
        $key = substr($sql, 18);
        if (!isset($this->savepoints[$key])) { throw new RuntimeException('missing witness'); }
        unset($this->savepoints[$key]); return true;
    }
    public function scope(): object {
        $this->depth++; $size = count($this->writes);
        return new class($this, $size) {
            public function __construct(private TransactionWitnessFixture $f, private int $size) {}
            public function commit(): void { $this->f->depth--; }
            public function rollback(): void { $this->f->depth--; $this->f->writes = array_slice($this->f->writes, 0, $this->size); }
        };
    }
    public function guard(): FormTransactionGuard { return new FormTransactionGuard($this->query(...), fn() => $this->depth, $this->scope(...)); }
}
$f = new TransactionWitnessFixture(); $f->guard()->run(function () use ($f) { $f->writes[] = 'receipt'; });
ok($f->depth === 1 && $f->writes === ['receipt'] && $f->savepoints === [], 'успех локального уровня сохраняет outer и удаляет свой свидетель');
$f = new TransactionWitnessFixture(); $f->guard()->run(function () use ($f) { $f->writes[] = 'partial'; throw new LogicException('local'); }, function () use ($f) { $f->writes[] = 'error_receipt'; });
ok($f->writes === ['error_receipt'] && $f->depth === 1, 'после local rollback error receipt в отдельном уровне');
foreach (['lost', 'restarted', 'depth', 'autocommit', 'missing_outer', 'error_receipt'] as $scenario) {
    // Каждый сценарий моделирует новый PHP-запрос; production reset намеренно отсутствует.
    (new ReflectionProperty(TransactionScope::class, 'lost'))->setValue(null, null);
    $f = new TransactionWitnessFixture(); $recovery = 0;
    if ($scenario === 'autocommit') { $f->autocommit = 0; }
    if ($scenario === 'missing_outer') { $f->active = false; }
    try {
        $f->guard()->run(function () use ($f, $scenario) {
            if (in_array($scenario, ['lost', 'restarted'], true)) { $f->savepoints = []; $f->active = $scenario === 'restarted'; }
            if ($scenario === 'depth') { $f->depth++; }
            throw new RuntimeException('synthetic local failure');
        }, function () use (&$recovery, $scenario) { $recovery++; if ($scenario === 'error_receipt') { throw new RuntimeException('persist failed'); } });
        ok(false, $scenario . ': callback обязан остановиться');
    } catch (RuntimeException $e) {
        ok($e instanceof TransactionLost && ($scenario === 'error_receipt' ? $recovery === 1 : $recovery === 0), $scenario . ': sticky fatal без ложного error receipt');
    }
}
itog();
