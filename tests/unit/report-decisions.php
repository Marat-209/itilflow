<?php
/** Историческая сводка решений и настоящий Twig, без БД. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\{Report, DefinitionSnapshot, Step, Sheet};

function reportInstance(string $label, array $extraItems = []): array
{
    // Та же полная форма полей, что в snimok-versii.php, с добавлениями схемы 3.
    $process = ['id'=>5, 'name'=>'Учебный маршрут', 'version'=>2, 'itemtype'=>'Ticket',
        'entities_id'=>0, 'is_recursive'=>1, 'is_active'=>1, 'enforcement'=>'audit',
        'block_resolution'=>1, 'public_log'=>1, 'helpdesk_tab'=>1, 'allow_withdraw'=>1,
        'calendars_id'=>0, 'outcome_contract'=>DefinitionSnapshot::MODE,
        'forms_forms_id'=>0, 'forms_destinations_id'=>0];
    $stage = ['id'=>51, 'plugin_itilflow_processes_id'=>5, 'ranking'=>10, 'name'=>'Проверка',
        'execution_mode'=>'inline', 'entity_strategy'=>'inherit', 'entities_id_target'=>0,
        'groups_id'=>7, 'users_id'=>0, 'itilcategories_id'=>0, 'slas_id'=>0, 'duration'=>0,
        'is_optional'=>0, 'completion_comment'=>'optional', 'deadline_minutes'=>0,
        'approval_percent'=>100, 'approver_source'=>'fixed', 'ticket_status'=>0,
        'on_reject'=>'block', 'plugin_itilflow_stages_id_reject'=>0, 'content'=>'',
        'department_result_source'=>'', 'negative_policy'=>'block', 'return_targets'=>'[]',
        'form_validationsteps_id'=>0, 'approver_users'=>null, 'approver_groups'=>null,
        'approver_group_mode'=>'any', 'run_if'=>null, 'unknown_policy'=>'run',
        'decision_items'=>array_merge([['key'=>'check','label'=>$label]], $extraItems)];
    $snapshot = DefinitionSnapshot::draft($process, [$stage]);
    ok(DefinitionSnapshot::valid($snapshot), 'синтетический снимок проходит настоящий валидатор');
    $raw = DefinitionSnapshot::encode($snapshot);
    return ['outcome_contract'=>DefinitionSnapshot::MODE, 'definition_snapshot'=>$raw,
        'definition_sha256'=>hash('sha256',$raw), 'plugin_itilflow_processes_id'=>5,
        'process_version'=>2, 'itemtype'=>'Ticket'];
}
function reportStep(int $instance, mixed $answers, string $state = Step::DONE, int $replacement = 0): array
{
    return ['plugin_itilflow_instances_id'=>$instance, 'plugin_itilflow_stages_id'=>51,
        'state'=>$state, 'superseded_by_attempt_id'=>$replacement,
        'decision'=>DefinitionSnapshot::encode(['version'=>1,'revision'=>1,'answers'=>$answers,
            'history'=>[['actor'=>123,'at'=>'2026-01-01','answers'=>$answers]]])];
}
$instances = [1=>reportInstance('<b>Первый смысл</b>'), 2=>reportInstance('Другой смысл')];
$rows = [reportStep(1,['check'=>true]), reportStep(2,['check'=>false]),
    reportStep(1,[]), reportStep(1,['check'=>false],Step::NOT_NEEDED),
    reportStep(1,['check'=>false],Step::DONE,9), reportStep(1,['check'=>'0'])];
$result = Report::decisionSummary($instances,$rows)[51];
ok(count($result['groups'])===2, 'одинаковые редакция и ключ с разным смыслом не смешиваются');
$first=$result['groups'][0]; $second=$result['groups'][1];
ok($first['yes']===1 && $first['no']===0 && $first['unknown']===2,
    'пустое и несовместимое решения не превращаются в Нет');
ok($first['not_needed']===1 && $first['history']===1 && $second['no']===1,
    'не потребовался и заменённый проход отделены от отрицательных ответов');
ok($first['variant']!==$second['variant'] && $first['version']===$second['version'],
    'разные варианты одной сохранённой редакции различимы');
$partial=[3=>reportInstance('Два вопроса',[['key'=>'extra','label'=>'Ещё проверка']])];
$groups=Report::decisionSummary($partial,[reportStep(3,['check'=>true])])[51]['groups'];
ok(count($groups)===2 && $groups[0]['unknown']===1 && $groups[1]['unknown']===1,
    'частичное несовместимое решение целиком отмечено как неподтверждённое');
$broken=$instances; $broken[1]['definition_sha256']=str_repeat('0',64);
ok(Report::decisionSummary($broken,[reportStep(1,['check'=>false])])[51]['unavailable']===1,
    'повреждённый снимок не заменяется текущей настройкой или Нет');
$bad=reportStep(1,[]);$bad['decision']='{';
ok(Report::decisionSummary($instances,[$bad])[51]['groups'][0]['unknown']===1,
    'повреждённое решение показывается как неподтверждённое');
$legacy=Report::decisionSummary([4=>['outcome_contract'=>'legacy']],[reportStep(4,[])])[51];
ok($legacy['groups']===[] && $legacy['unavailable']===0,
    'прежний маршрут без снимка не объявляется повреждённым решением');
$bad['superseded_by_attempt_id']=9;
$history=Report::decisionSummary($instances,[$bad])[51]['groups'][0];
ok($history['history']===1 && $history['unknown']===0,
    'заменённое повреждённое решение считается только историей');

$loader=new \Twig\Loader\ChainLoader([
    new \Twig\Loader\ArrayLoader(['components/form/fields_macros.html.twig'=>'']),
    new \Twig\Loader\FilesystemLoader(dirname(__DIR__,2).'/templates')]);
$twig=new \Twig\Environment($loader,['autoescape'=>'html','strict_variables'=>true,'cache'=>false]);
$twig->addFunction(new \Twig\TwigFunction('path',static fn($path)=>$path));
$twig->addFunction(new \Twig\TwigFunction('call',static fn(...$args)=>'длительность'));
$stage=['ranking'=>10,'name'=>'Проверка','mode'=>'inline','passes'=>6,'duration_samples'=>0,
    'duration_unknown'=>0,'avg'=>0,'median'=>0,'max'=>0,'overdue'=>0,'rejected'=>0,
    'clarification'=>0,'refused'=>0,'superseded'=>0,'reassigned'=>0,
    'required_progress'=>Sheet::requiredProgress($rows),'decisions'=>$result];
$html=$twig->render('report.html.twig',['processes'=>[5=>'Учебный маршрут'],'pid'=>5,
    'summary'=>['total'=>2,'lead_avg'=>0,'lead_med'=>0,'lead_max'=>0,'by_state'=>[]],
    'stages'=>[$stage],'modes'=>['inline'=>'Задача']]);
ok(str_contains($html,'&lt;b&gt;Первый смысл&lt;/b&gt;') && !str_contains($html,'<b>Первый смысл</b>'),
    'настоящий шаблон экранирует историческую подпись');
ok(str_contains($html,'Нет: 0') && str_contains($html,'Нет ответа: 2'),
    'шаблон различает неизвестные ответы и Нет');
ok(!str_contains($html,$instances[1]['definition_sha256']) && !str_contains($html,'actor'),
    'шаблон не выводит технический отпечаток или авторов истории');
ok(str_contains($html,'Вопрос этапа: ') && !str_contains($html,'версия маршрута')
    && !preg_match('/редакци|вариант|Решение отдела/u',$html),
    '1.13.1: вопрос этапа — без версии маршрута, «редакции» и «варианта»');

// 1.13.1: один вопрос в двух версиях маршрута — одна строка отчёта, счётчики сложены.
$dvaVersii = Report::mergeByLabel(['unavailable'=>1, 'groups'=>[
    ['version'=>7,'label'=>'Нужна закупка?','yes'=>1,'no'=>2,'unknown'=>0,'not_needed'=>1,'history'=>0,'variant'=>1],
    ['version'=>9,'label'=>'Нужна закупка?','yes'=>3,'no'=>0,'unknown'=>1,'not_needed'=>0,'history'=>2,'variant'=>2],
    ['version'=>9,'label'=>'Нужен монтаж?','yes'=>0,'no'=>1,'unknown'=>0,'not_needed'=>0,'history'=>0,'variant'=>3]]]);
ok(count($dvaVersii['groups'])===2 && $dvaVersii['groups'][0]['yes']===4 && $dvaVersii['groups'][0]['no']===2
    && $dvaVersii['groups'][0]['unknown']===1 && $dvaVersii['groups'][0]['not_needed']===1
    && $dvaVersii['groups'][0]['history']===2 && $dvaVersii['groups'][1]['no']===1 && $dvaVersii['unavailable']===1,
    '1.13.1: вопрос с той же подписью в версиях 7 и 9 — одна строка, ответы сложены', json_encode($dvaVersii, JSON_UNESCAPED_UNICODE));
$htmlV=$twig->render('report.html.twig',['processes'=>[5=>'Учебный маршрут'],'pid'=>5,
    'summary'=>['total'=>2,'lead_avg'=>120,'lead_med'=>120,'lead_max'=>240,'by_state'=>[],
        'lead_avg_text'=>'2 мин','lead_med_text'=>'2 мин','lead_max_text'=>'4 мин'],
    'stages'=>[['decisions'=>$dvaVersii,'duration_samples'=>2,'avg'=>120,'median'=>120,'max'=>240,
        'avg_text'=>'2 мин','median_text'=>'2 мин','max_text'=>'4 мин']+$stage],'modes'=>['inline'=>'Задача']]);
ok(substr_count($htmlV,'Вопрос этапа: Нужна закупка?')===1 && !str_contains($htmlV,'версия маршрута'),
    '1.13.1: в отчёте вопрос двух версий — одной строкой, без версии');
ok(str_contains($htmlV,'4 мин') && !str_contains($htmlV,'минут'),
    '1.13.1: длительность в отчёте — «4 мин» словами плагина, не «4 минут»');

// Категории строки этапа сходятся с итогом — «Не выполнялся» (not_run) считается отдельно.
// В отчёте под этапом — одна строка «Нужен был: N из M»; остальные счётчики — в листе согласования.
$progress=Sheet::requiredProgress([
    ['state'=>Step::DONE,'date_start'=>'2026-01-01 10:00:00','superseded_by_attempt_id'=>0],
    ['state'=>Step::SUPERSEDED,'date_start'=>'2026-01-01 10:00:00','superseded_by_attempt_id'=>0],
    ['state'=>Step::SUPERSEDED,'date_start'=>null,'superseded_by_attempt_id'=>0],
    ['state'=>Step::SKIPPED,'date_start'=>null,'superseded_by_attempt_id'=>0],
    ['state'=>Step::NOT_NEEDED,'date_start'=>null,'superseded_by_attempt_id'=>0],
    ['state'=>Step::PENDING,'date_start'=>null,'superseded_by_attempt_id'=>0],
    ['state'=>Step::DONE,'date_start'=>'2026-01-01 10:00:00','superseded_by_attempt_id'=>7]]);
$html8=$twig->render('report.html.twig',['processes'=>[5=>'Учебный маршрут'],'pid'=>5,
    'summary'=>['total'=>1,'lead_avg'=>0,'lead_med'=>0,'lead_max'=>0,'by_state'=>[]],
    'stages'=>[['required_progress'=>$progress,'decisions'=>['groups'=>[],'unavailable'=>0]]+$stage],'modes'=>[]]);
$tekst8=preg_replace('/\s+/u',' ',strip_tags($html8));
$chasti=[$progress['required'],$progress['not_needed'],$progress['undetermined'],$progress['skipped_before_start'],$progress['not_run']];
ok($progress['not_run']===1, 'брошенный до начала этап отчёта — «не выполнялся»', json_encode($progress));
ok($progress['total']===6 && array_sum($chasti)===$progress['total'],
    'нужен, не нужен по условию, ещё не ясно, пропущен до начала и не выполнялся вместе дают итог',
    json_encode($progress));
ok($progress['interrupted']===1 && $progress['history']===1,
    'не законченный и прежняя попытка считаются отдельно от итога', json_encode($progress));
ok(str_contains($tekst8,'Нужен был: '.$progress['required'].' из 6')
    && !preg_match('/ещё не ясно|Не нужен по условию:|Пропущен до начала:|Не закончен:|Не выполнялся:|Прежних попыток:/u',$tekst8),
    'отчёт: под этапом одна строка «Нужен был: N из M», без счётчиков листа согласования', $tekst8);

// Состояния маршрута — словами, как на вкладке заявки, а не ключами.
$htmlS=$twig->render('report.html.twig',['processes'=>[5=>'Учебный маршрут'],'pid'=>5,
    'summary'=>['total'=>4,'lead_avg'=>0,'lead_med'=>0,'lead_max'=>0,
        'by_state'=>['done'=>1,'running'=>1,'aborted'=>1,'halted'=>1]],
    'stages'=>[],'modes'=>[],'states'=>\GlpiPlugin\Itilflow\Instance::getStateLabels()]);
ok(str_contains($htmlS,'Пройден: 1') && str_contains($htmlS,'В работе: 1') && str_contains($htmlS,'Прерван: 1')
    && str_contains($htmlS,'Остановлен: 1') && !preg_match('/(done|running|aborted|halted):/',$htmlS),
    'состояния в отчёте — «Пройден», «В работе», «Прерван», «Остановлен»');
itog();
