<?php
/** Mapper contract with an in-memory DB double; not native SQL/ACL evidence. */
namespace {
class CommonDBTM {
    // Как в GLPI: назначение формы — дочернее к форме и наследует isEntityAssign(), но колонки entities_id у него нет.
    public function isEntityAssign(): bool { return in_array(static::getTable(), ['forms', 'destinations'], true); }
    public function maybeRecursive(): bool { return true; }
}
class MapperForm extends CommonDBTM { public static function getTable(): string { return 'forms'; } }
class MapperDestination extends CommonDBTM { public static function getTable(): string { return 'destinations'; } }
class MapperStep extends CommonDBTM { public static function getTable(): string { return 'steps'; } }
class Dropdown { public static function getDropdownName(...$a): string { return ''; } }
function getItemForItemtype($type) { return match ($type) {
    \GlpiPlugin\Itilflow\TransferMapper::FORM => new MapperForm(),
    \GlpiPlugin\Itilflow\TransferMapper::DESTINATION => new MapperDestination(),
    'ValidationStep' => new MapperStep(), default => null,
}; }
function getEntitiesRestrictCriteria($table, $unused, $entities, $recursive) { return [$table . '.entities_id' => $entities]; }
require dirname(__DIR__, 2) . '/src/TransferMapper.php';
require dirname(__DIR__, 2) . '/src/ValidationLadder.php';
use GlpiPlugin\Itilflow\TransferMapper as M;
$fail = 0;
function check(bool $ok, string $label): void { global $fail; echo ($ok ? 'ok ' : 'НЕ ok ') . $label . PHP_EOL; if (!$ok) { $fail++; } }
$DB = new class {
    public array $data = [
        'forms' => [
            ['id'=>91,'name'=>'same name','uuid'=>'different','entities_id'=>0,'is_deleted'=>0],
            ['id'=>901,'name'=>'renamed','uuid'=>'source-uuid','entities_id'=>0,'is_deleted'=>0],
            ['id'=>902,'name'=>'cloned','uuid'=>'new-uuid','entities_id'=>0,'is_deleted'=>0],
            ['id'=>903,'name'=>'invisible','uuid'=>'hidden-uuid','entities_id'=>9,'is_deleted'=>0],
            ['id'=>904,'name'=>'deleted','uuid'=>'deleted-uuid','entities_id'=>0,'is_deleted'=>1],
        ],
        'destinations' => [
            ['id'=>92,'name'=>'same name','forms_forms_id'=>91],
            ['id'=>801,'name'=>'right','forms_forms_id'=>901],
            ['id'=>802,'name'=>'clone destination','forms_forms_id'=>902],
        ],
        'steps' => [['id'=>8,'name'=>'First'],['id'=>9,'name'=>'Second'],['id'=>817,'name'=>'Этап маршрута 25, попытка 2 — порог 100 %'],
            ['id'=>818,'name'=>'Этап маршрута 30 — нужно 50 %']],
    ];
    private function matches(array $row, array $criteria): bool {
        foreach ($criteria as $key=>$value) {
            if (is_int($key)) { if (!$this->matches($row,$value)) { return false; } continue; }
            $field = substr($key,strrpos($key,'.')+1);
            if (is_array($value) ? !in_array($row[$field]??null,$value,true) : ($row[$field]??null)!==$value) { return false; }
        }
        return true;
    }
    public array $columns = ['forms' => ['id','name','uuid','entities_id','is_deleted'], 'destinations' => ['id','name','forms_forms_id'], 'steps' => ['id','name']];
    private function fieldsOf(array $criteria): array {
        $out = [];
        foreach ($criteria as $key=>$value) { if (is_int($key)) { $out = array_merge($out, $this->fieldsOf($value)); } else { $out[] = $key; } }
        return $out;
    }
    public function request(array $query): array {
        // Как MySQL: колонка, которой нет в таблице, — ошибка 1054, а не пустой ответ.
        foreach (array_merge((array) ($query['SELECT'] ?? []), $this->fieldsOf($query['WHERE'] ?? [])) as $f) {
            if (!in_array(substr($f,strrpos($f,'.')+1), $this->columns[$query['FROM']], true)) { throw new RuntimeException("Unknown column '$f' (1054)"); }
        }
        return array_values(array_filter($this->data[$query['FROM']], fn($row)=>$this->matches($row,$query['WHERE'])));
    }
};
$form = ['itemtype'=>M::FORM,'id'=>91,'name'=>'same name','uuid'=>'source-uuid'];
$dest = ['itemtype'=>M::DESTINATION,'id'=>92,'name'=>'same name','forms_forms_id'=>91,'form_uuid'=>'source-uuid'];
$m = new M([0]);
check($m->resolve($form)['id']===901, 'UUID resolves renamed form, not same numeric ID/name');
check($m->resolve(array_replace($form,['uuid'=>'absent']))['state']===M::MISSING, 'same source ID with changed UUID is not cached/reused');
check($m->resolve(array_replace($form,['uuid'=>'hidden-uuid']))['state']===M::MISSING, 'foreign entity form excluded');
check($m->resolve(array_replace($form,['uuid'=>'deleted-uuid']))['state']===M::MISSING, 'deleted form excluded');
try { $r=$m->resolve($dest); } catch (RuntimeException $e) { $r=['state'=>'error','candidates'=>[],'id'=>0]; echo '  ' . $e->getMessage() . PHP_EOL; }
check($r['state']!=='error', 'destination lookup does not query entities_id: destinations table has no such column');
check($r['state']===M::CHOOSE && $r['id']===0 && array_keys($r['candidates'])===[801], 'destination requires explicit choice within mapped form: «выберите из списка», не «не найдено»');
$m->addReplacement(M::DESTINATION,92,92);
check($m->resolve($dest)['state']===M::CHOOSE && $m->resolve($dest)['id']===0, 'raw original destination ID rejected');
$m->addReplacement(M::DESTINATION,92,801);
check($m->resolve($dest)['id']===801, 'explicit matching destination accepted');
$m->addReplacement(M::FORM,91,902);
check($m->resolve($dest)['state']===M::CHOOSE && $m->resolve($dest)['id']===0, 'changing mapped form invalidates old destination choice');
$m->addReplacement(M::DESTINATION,92,802);
check($m->resolve($form)['id']===902 && $m->resolve($dest)['id']===802, 'native clone/new UUID needs explicit form and destination mapping');
$m->addReplacement(M::DESTINATION,92,0);
check($m->resolve($dest)['state']===M::CHOOSE && $m->resolve($dest)['id']===0, 'zero destination never becomes successful runtime mapping');
$step=['itemtype'=>'ValidationStep','id'=>8,'name'=>'First'];
$rs = $m->resolve($step);
check($rs['state']===M::CHOOSE && $rs['id']===0, 'same name and ID never implicitly confirms validation step');
check(array_keys($rs['candidates'])===[8,9], 'служебные этапы согласования прежних версий плагина в выборе не показываются');
check(!in_array(M::CHOOSE, [M::FOUND, M::BY_ID, M::REPLACED], true), '«выберите из списка» — не успешное сопоставление: импорт его не примет');
$m->addReplacement('ValidationStep',8,0);
check($m->resolve($step)['state']===M::CHOOSE && $m->resolve($step)['id']===0, 'zero validation step rejected');
$m->addReplacement('ValidationStep',8,9);
check($m->resolve($step)['id']===9, 'explicit validation step accepted');
echo $fail ? "ПРОВАЛ: $fail\n" : "ВСЁ ok\n";
exit($fail?1:0);
}
