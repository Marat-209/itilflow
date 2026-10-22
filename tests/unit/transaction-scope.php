<?php
/** MySQL-подобная модель: SAVEPOINT вне транзакции успешен, но не существует. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\{TransactionScope,TransactionLost};

final class ScopeServerFixture
{
    public int $depth = 1;
    public bool $active = true;
    public int $autocommit = 1;
    public array $points = [];
    public array $calls = [];
    public string $failOperation = '';
    public bool $noBegin = false;
    public function call(string $operation, ?string $sql): mixed
    {
        $this->calls[] = $operation;
        if ($operation === $this->failOperation) { throw new RuntimeException('synthetic transport failure'); }
        if ($operation === 'depth') { return $this->depth; }
        if ($operation === 'autocommit') { return $this->autocommit; }
        if ($operation === 'begin') {
            if ($this->depth === 0 && !$this->noBegin) { $this->active = true; }
            elseif ($this->active) { $this->points['native_'.$this->depth] = true; }
            $this->depth++; return null;
        }
        if ($operation === 'query') {
            if (str_starts_with($sql, 'SAVEPOINT ')) {
                if ($this->active) { $this->points[substr($sql,10)] = true; }
                return true;
            }
            $key = substr($sql,18);
            if (!isset($this->points[$key])) { throw new RuntimeException('missing savepoint'); }
            unset($this->points[$key]); return true;
        }
        if ($this->depth > 1) {
            $key = 'native_'.($this->depth-1);
            if (!isset($this->points[$key])) { throw new RuntimeException('missing native savepoint'); }
            if ($operation === 'rollback') {
                $keys = array_keys($this->points); $position = array_search($key,$keys,true);
                foreach (array_slice($keys,$position+1) as $later) { unset($this->points[$later]); }
            } else { unset($this->points[$key]); }
        } else { $this->active = false; $this->points = []; }
        $this->depth--; return null;
    }
}
function freshScopeServer(int $depth = 1): ScopeServerFixture
{
    // Новый сценарий = новый запрос. Production API не позволяет снять poison.
    (new ReflectionProperty(TransactionScope::class,'lost'))->setValue(null,null);
    (new ReflectionProperty(TransactionScope::class,'owned'))->setValue(null,0);
    $f = new ScopeServerFixture(); $f->depth=$depth; $f->active=$depth>0; return $f;
}
foreach ([0,1] as $depth) {
    foreach (['commit','rollback'] as $finish) {
        $f=freshScopeServer($depth); $scope=new TransactionScope($f->call(...)); $scope->$finish();
        ok($f->depth===$depth && $f->active===($depth>0) && !TransactionScope::hasActive(), "$finish сохраняет native уровень $depth");
    }
}
$f=freshScopeServer(); $outer=new TransactionScope($f->call(...)); $inner=new TransactionScope($f->call(...));
$inner->rollback(); $outer->commit();
ok($f->depth===1 && $f->active,'Локальный rollback вложенного scope не теряет outer witness');

foreach (['missing_before','lost_commit','lost_rollback','replaced_commit','depth_changed','commit_failure','rollback_failure','autocommit_zero','begin_noop'] as $scenario) {
    $f=freshScopeServer($scenario==='begin_noop'?0:1);
    if($scenario==='begin_noop'){$f->noBegin=true;}
    if($scenario==='missing_before'){$f->active=false;}
    if($scenario==='autocommit_zero'){$f->autocommit=0;}
    $caught=false;
    try {
        $scope=new TransactionScope($f->call(...));
        if(in_array($scenario,['lost_commit','lost_rollback','replaced_commit'],true)){$f->points=[];$f->active=$scenario==='replaced_commit';}
        if($scenario==='depth_changed'){$f->depth++;}
        if($scenario==='commit_failure'){$f->failOperation='commit';}
        if($scenario==='rollback_failure'){$f->failOperation='rollback';}
        if(in_array($scenario,['lost_rollback','rollback_failure'],true)){$scope->rollback();}else{$scope->commit();}
    } catch(TransactionLost $e){$caught=true;}
    ok($caught,$scenario.': fatal вместо false/null');
    $count=count($f->calls); $blocked=0;
    foreach ([fn()=>new TransactionScope($f->call(...)),fn()=>TransactionScope::requireActive(),fn()=>TransactionScope::hasActive()] as $action) {
        try{$action();}catch(TransactionLost){$blocked++;}
    }
    ok($blocked===3 && count($f->calls)===$count,$scenario.': sticky блокирует новый scope до любой SQL');
}
// Реальные catch Engine не превращают fatal конструктора в normal false/null.
$savedDB=$GLOBALS['DB']??null;
foreach (['locked','startTransaction'] as $method) {
    freshScopeServer(); $GLOBALS['DB']=new stdClass(); $fatal=false;
    $class=$method==='locked'?\GlpiPlugin\Itilflow\Instance::class:Ticket::class;
    $object=(new ReflectionClass($class))->newInstanceWithoutConstructor();
    try {
        $reflection=new ReflectionMethod(\GlpiPlugin\Itilflow\Engine::class,$method);
        $method==='locked' ? $reflection->invoke(null,$object,static fn()=>true) : $reflection->invoke(null,$object,1);
    } catch(TransactionLost){$fatal=true;}
    ok($fatal,'Engine::'.$method.' не глотает TransactionLost');
}
$GLOBALS['DB']=$savedDB;
itog();
