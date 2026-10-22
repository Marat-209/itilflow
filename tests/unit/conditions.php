<?php
/** Трёхзначная логика и ревизии: без БД и побочных эффектов. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\Condition;
use GlpiPlugin\Itilflow\Decision;
use GlpiPlugin\Itilflow\Step;

$clauses = [];
foreach ([1,2,3] as $id) { $clauses[] = ['source'=>'decision','stage_id'=>$id,'key'=>'answer','operator'=>'eq','value'=>true]; }
foreach (['all','any'] as $join) {
    foreach ([true,false,null] as $a) { foreach ([true,false,null] as $b) { foreach ([true,false,null] as $c) {
        $values=[$a,$b,$c]; $stages=[];
        foreach ($values as $i=>$value) { $stages[$i+1]=['state'=>Step::DONE,'attempt_id'=>8,'answers'=>['answer'=>$value]]; }
        $result=Condition::evaluate(['version'=>1,'join'=>$join,'clauses'=>$clauses],['attempt_id'=>8,'definition'=>'d','stages'=>$stages]);
        $expected=in_array(null,$values,true)?'unknown':(($join==='all'?!in_array(false,$values,true):in_array(true,$values,true))?'yes':'no');
        ok($result['truth']===$expected, "$join " . json_encode($values) . " = $expected");
    } } }
}
$rule=['version'=>1,'join'=>'all','clauses'=>[$clauses[0]]];
$context=['attempt_id'=>8,'definition'=>'d','stages'=>[1=>['state'=>Step::NOT_NEEDED,'attempt_id'=>8,'answers'=>['answer'=>false]]]];
ok(Condition::evaluate($rule,$context)['truth']==='unknown','Не потребовался не означает решение Нет');
$rule['clauses'][0]=['source'=>'stage_not_needed','stage_id'=>1,'operator'=>'eq','value'=>true];
ok(Condition::evaluate($rule,$context)['truth']==='yes','Отдельный предикат не потребовался');
$context['stages'][1]['attempt_id']=7;
ok(Condition::evaluate($rule,$context)['truth']==='unknown','Чужая попытка не источник');
$context['stages'][1]['retained']=true;
ok(Condition::evaluate($rule,$context)['truth']==='yes','Явно сохранённый prefix допустим');
$before=Condition::evaluate($rule,$context)['input_revision'];
$context['ticket']=['category_id'=>999];
ok(Condition::evaluate($rule,$context)['input_revision']===$before,'Неиспользованная категория не меняет ревизию');
$context['definition']='changed';
ok(Condition::evaluate($rule,$context)['input_revision']!==$before,'Смена определения меняет ревизию');
foreach ([['9007199254740993','9007199254740992',1],['-0.00','0',0],['-100.0001','-100',-1],['1.20','1.2',0],['0.000000000000000001','0',1]] as [$a,$b,$expected]) {
    ok(Condition::decimalCompare($a,$b)===$expected,"Точное число $a / $b");
}
$formRule=['version'=>1,'join'=>'all','clauses'=>[['source'=>'form_answer','question_uuid'=>'q','question'=>['id'=>1,'uuid'=>'q','type'=>'choice','options'=>[['key'=>'a','label'=>'A'],['key'=>'b','label'=>'B']]], 'operator'=>'in','value'=>['b']]]];
$form=['attempt_id'=>8,'definition'=>'d','form_facts'=>['status'=>'final','facts'=>['q'=>['status'=>'known','type'=>'choice','value'=>['a','b']]]]];
ok(Condition::evaluate($formRule,$form)['truth']==='yes','Мультивыбор: пересечение всего списка');
$form['form_facts']['status']='pending';
ok(Condition::evaluate($formRule,$form)['truth']==='unknown','Pending не ELSE');
$form['form_facts']['status']='final'; $form['form_facts']['facts']['q']['value']=['a',12];
ok(Condition::evaluate($formRule,$form)['truth']==='unknown','Частично неверный мультивыбор целиком UNKNOWN');
$items=[['key'=>'answer','label'=>'Продолжить?']]; $old=['version'=>1,'revision'=>0,'answers'=>[],'history'=>[]];
$next=Decision::next($old,$items,['answer'=>false],0,5,'2026-10-21 10:00:00');
ok($next!==null && $next['revision']===1 && $next['answers']['answer']===false && $next['history'][0]['actor']===5,'Нет сохраняется как ответ с автором');
ok(Decision::next($next,$items,['answer'=>true],0,5,'now')===null,'Устаревшая ревизия отклонена');
ok(Decision::next($old,$items,[],0,5,'now')===null,'Отсутствие ответа не Нет');
ok(Decision::next($old,$items,['answer'=>'1'],0,5,'now')===null,'API не приводит строку к bool');
ok(Decision::fromPost(['answer'=>'0'])===['answer'=>false] && Decision::fromPost(['answer'=>''])===null,'POST строго 0/1');
ok(Decision::revisionFromPost('0')===0 && Decision::revisionFromPost('01')===null && Decision::revisionFromPost('1e2')===null,'Ревизия строго целая');
ok(!Decision::validateItems([['key'=>'1answer','label'=>'x']]),'Ключ начинается с буквы');
ok(Condition::validate(['version'=>1,'join'=>'all','clauses'=>[['source'=>'ticket','field'=>'type','operator'=>'eq','value'=>[1,2]]]])!==[],'Скалярный оператор не принимает список');
$bad=$formRule; $bad['clauses'][0]['question']['type']=['choice'];
ok(Condition::evaluate($bad,$form)['truth']==='unknown','Повреждённый тип вопроса даёт UNKNOWN без исключения');
$bad=$rule; $bad['clauses'][0]['stage_id']=[1];
ok(Condition::evaluate($bad,$context)['truth']==='unknown','Повреждённый ID этапа проверяется до индексации');
$readable=Condition::describeWithStages(['version'=>1,'join'=>'all','clauses'=>[$clauses[0]]],
    [['id'=>1,'name'=>'Проверка','decision_items'=>[['key'=>'answer','label'=>'Нужно продолжить?']]]]);
ok(str_contains($readable,'Нужно продолжить?') && !str_contains($readable,'answer'),'Пересказ использует сохранённый текст вопроса решения');
ok(str_contains(Condition::describe($formRule),'— B') && !str_contains(Condition::describe($formRule),'— b'),'Пересказ вариантов использует подписи снимка');
// 1.13: объект — названием GLPI, а не номером.
$reference=$formRule; $reference['clauses'][0]['question']['type']='ref'; $reference['clauses'][0]['question']['itemtype']='Group';
$reference['clauses'][0]['operator']='eq'; $reference['clauses'][0]['value']=['itemtype'=>'Group','id'=>7];
$named=\GlpiPlugin\Itilflow\ConditionText::short($reference, [], static fn(string $t, int $id): string => $t === 'Group' && $id === 7 ? 'Служба выдачи' : '?');
ok(str_contains($named,'Служба выдачи') && !str_contains($named,'7') && !str_contains($named,'itemtype'),'Ссылка читается названием, без номера и без JSON', $named);
ok(!preg_match('/(Сотрудник|Пользователь|Группа|Категория) №/u', Condition::describe($reference)),'Без базы пересказ не пишет «Группа №»', Condition::describe($reference));
$nextStages = [
    ['id'=>1,'ranking'=>10,'name'=>'Проверка','execution_mode'=>'approval','negative_policy'=>'abort','return_targets'=>'[1]',
        'decision_items'=>[['key'=>'answer','label'=>'Нужно продолжить?']]],
    ['id'=>2,'ranking'=>20,'name'=>'Дополнительная проверка','run_if'=>['version'=>1,'join'=>'all','clauses'=>[$clauses[0]]],'unknown_policy'=>'ask'],
    ['id'=>3,'ranking'=>30,'name'=>'Завершение работ','run_if'=>null],
    ['id'=>4,'ranking'=>40,'name'=>'Поздний этап','run_if'=>null],
];
$summary = \GlpiPlugin\Itilflow\StageForm::whatNext($nextStages[0], $nextStages);
// 1.13: как «Что дальше» — «если …» у условных этапов, без служебного «если неизвестно».
ok(str_starts_with($summary, 'Выполнено → этап 20 «Дополнительная проверка» (если 10: «Нужно продолжить?» — да), затем этап 30 «Завершение работ»')
    && !str_contains($summary, 'Поздний этап') && !str_contains($summary, 'неизвестно'), 'Что дальше: условия общим пересказом до ближайшего безусловного этапа', $summary);
ok(str_contains($summary, 'Отклонено → отказ инициатору, маршрут закончен') && str_contains($summary, 'Уточнение → этот этап'),
    'Что дальше: отказ не выдаётся за переход, уточнение берётся из разрешённых целей', $summary);
ok(\GlpiPlugin\Itilflow\StageForm::whatNext($nextStages[3], $nextStages) === 'Выполнено → маршрут закончен',
    'Что дальше: последний обычный этап завершает маршрут без выдуманного отказа');
$invalidNext = $nextStages; $invalidNext[1]['run_if'] = '{broken';
ok(str_contains(\GlpiPlugin\Itilflow\StageForm::whatNext($invalidNext[0], $invalidNext), 'условие повреждено — откройте этот этап'),
    'Что дальше: повреждённое условие не объявляется безусловным');
itog();
