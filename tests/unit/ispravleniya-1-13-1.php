<?php

/**
 * 1.13.1: исправления по проверке в браузере — части, которые проверяются без базы.
 * Поведение с базой (завершение этапа исполнителем, «Прервать маршрут», черновик формы
 * маршрута) здесь не проверяется.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\DefinitionSnapshot;
use GlpiPlugin\Itilflow\FormAdoption;
use GlpiPlugin\Itilflow\FormConfiguration;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\StageForm;
use GlpiPlugin\Itilflow\Step;
use GlpiPlugin\Itilflow\StepLine;

$koren = dirname(__DIR__, 2);

// ---- Новый этап до сохранения: «этот этап» среди этапов для возврата — не «список повреждён».
$etapy = [['id' => 8672, 'ranking' => 10, 'name' => 'Подготовка', 'run_if' => null],
    ['id' => 8676, 'ranking' => 40, 'name' => 'Итог', 'run_if' => null]];
$self = Stage::draftSelfId(0, $etapy);
ok($self === 8677, 'новый этап в черновике получает номер после наибольшего номера этапов маршрута', (string) $self);
ok(Stage::draftSelfId(8672, $etapy) === 8672, 'сохранённый этап в черновике — со своим номером');
ok(Stage::draftSelfId(0, []) === 1, 'первый этап маршрута — номер 1, не 0');
$celi = Stage::draftTargets(['self', '8672', '0', 'x', 'self'], $self);
ok($celi === [$self, 8672], '«этот этап» — номер черновика; ноль, мусор и повтор отброшены', json_encode($celi));
ok(DefinitionSnapshot::targets(DefinitionSnapshot::encode($celi)) === [$self, 8672],
    'список этапов для возврата нового этапа читается — готовность не пишет «повреждены»');
$novyj = ['id' => $self, 'ranking' => 30, 'name' => 'Выдача', 'execution_mode' => Stage::MODE_DELEGATED,
    'negative_policy' => Stage::REJECT_ABORT, 'return_targets' => DefinitionSnapshot::encode($celi), 'run_if' => null];
$dalshe = StageForm::whatNext($novyj, array_merge($etapy, [$novyj]));
ok(str_contains($dalshe, 'Уточнение → этот этап или этап 10 «Подготовка»') && !str_contains($dalshe, 'повреждён'),
    '«Что дальше» нового этапа: «Уточнение → этот этап…», без «список повреждён»', $dalshe);
ok(DefinitionSnapshot::targets(DefinitionSnapshot::encode([0])) === null,
    'номер 0 по-прежнему не принимается — поэтому у нового этапа и нужен номер черновика');

// ---- После «Завершить этап» исполнитель без доступа к заявке — на главную, а не на «Доступ запрещен».
$step = (string) file_get_contents($koren . '/front/step.form.php');
$outcome = (string) file_get_contents($koren . '/front/outcome.form.php');
// 1.13.2: перед переходом на главную — «Готово: этап завершён.» (если этап завершён).
ok(preg_match('/Authorizer::stepItemVisible\(\$instance, \$step\)\)\s*\{(?:(?!\n\}).)*?Html::redirect\(StepLine::homeUrl\(/us', $step) === 1
    && strrpos($step, 'Authorizer::stepItemVisible') < strrpos($step, 'Html::back();'),
    'step.form.php: после действия этапа проверяет, видна ли заявка, и ведёт на главную, если нет');
ok(str_contains($outcome, 'Authorizer::stepItemVisible($instance, $step)'),
    'outcome.form.php — та же проверка (одна на оба обработчика)');
ok(StepLine::homeUrl('central', '/glpi') === '/glpi/front/central.php', 'главная основного интерфейса');

// ---- Запрос согласования формы: этап другого маршрута той же формы — не потребитель этой заявки.
$moj = ['id' => 8672, 'plugin_itilflow_processes_id' => 2971, 'approver_source' => 'form', 'form_validationsteps_id' => 985];
$sosed = ['id' => 8677, 'plugin_itilflow_processes_id' => 2972, 'approver_source' => 'form', 'form_validationsteps_id' => 985];
$drugoj = ['id' => 8673, 'plugin_itilflow_processes_id' => 2971, 'approver_source' => 'fixed', 'form_validationsteps_id' => 0];
$potrebiteli = FormAdoption::routeConsumers([$moj, $sosed, $drugoj], 985, 2971);
ok($potrebiteli === [$moj], 'потребители — этапы своего маршрута с этим этапом согласования', json_encode($potrebiteli));
ok(FormAdoption::exclusiveStage($potrebiteli, [], 8672, 2971),
    'два маршрута одной формы с общим этапом согласования: запросы формы этой заявки снимаются');
$vtoroj = $moj; $vtoroj['id'] = 8674;
ok(!FormAdoption::exclusiveStage(FormAdoption::routeConsumers([$moj, $vtoroj, $sosed], 985, 2971), [], 8672, 2971),
    'второй этап того же маршрута с этим этапом согласования по-прежнему запрещает снятие');
ok(!FormAdoption::exclusiveStage($potrebiteli, [['id' => 9]], 8672, 2971), 'правило с этим этапом согласования по-прежнему запрещает снятие');

// ---- «Прервать маршрут» закрывает задачу идущего этапа.
$tok = (string) file_get_contents($koren . '/src/StructuredFlow.php');
ok(preg_match('/function abortStructured.*?Step::SUPERSEDED.*?self::releaseInlineWork\(\$instance, \$step\);/su', $tok) === 1,
    'прерывание нового порядка закрывает задачу этапа и снимает группу этапа, как завершение');

// ---- Кнопки формы маршрута и этапа — только разрешённые.
$probnyj = new class extends \CommonDBTM {
    public static bool $sozdat = false;
    public bool $novyj = false; public bool $izmenit = false; public bool $udalit = false;
    public function isNewItem() { return $this->novyj; }
    public static function canCreate(): bool { return self::$sozdat; }
    public function can($ID, int $right, ?array &$input = null): bool { return $right === UPDATE ? $this->izmenit : ($right === PURGE ? $this->udalit : false); }
};
$obekt = static function (bool $novyj, bool $sozdat, bool $izmenit, bool $udalit) use ($probnyj): \CommonDBTM {
    $o = clone $probnyj;
    $o::$sozdat = $sozdat; $o->novyj = $novyj; $o->izmenit = $izmenit; $o->udalit = $udalit;
    $o->fields = ['id' => $novyj ? 0 : 5];
    return $o;
};
ok(Process::formButtons($obekt(false, false, false, false)) === ['add' => false, 'update' => false, 'purge' => false],
    'только «Чтение»: ни «Сохранить», ни «Удалить навсегда»');
ok(Process::formButtons($obekt(false, true, true, true)) === ['add' => false, 'update' => true, 'purge' => true],
    'с правом изменения и удаления — обе кнопки');
ok(Process::formButtons($obekt(false, true, true, false)) === ['add' => false, 'update' => true, 'purge' => false],
    'без права удаления — только «Сохранить»');
ok(Process::formButtons($obekt(true, true, false, false))['add'] && !Process::formButtons($obekt(true, false, false, false))['add'],
    'новый объект: «Добавить» — только с правом создания');
foreach (['process.html.twig', 'stage.html.twig'] as $f) {
    $t = (string) file_get_contents($koren . '/templates/' . $f);
    ok(preg_match('/\{% if b\.update\|default\(false\) %\}\s*<button[^>]*name="update"/u', $t) === 1
        && preg_match('/\{% if b\.purge\|default\(false\) %\}\s*<button[^>]*name="purge"/u', $t) === 1,
        $f . ': «Сохранить» и «Удалить навсегда» — только по праву');
}

// ---- Подсказка «…согласование останется открытым» на задаче.
$zagr = new \Twig\Loader\FilesystemLoader($koren . '/templates');
$zagr->addPath($koren . '/templates', 'itilflow');
$tw = new \Twig\Environment($zagr, ['autoescape' => 'html', 'strict_variables' => true]);
foreach (['csrf_token', 'call', 'path'] as $fn) { $tw->addFunction(new \Twig\TwigFunction($fn, static fn() => '')); }
$baza = ['command' => null, 'instance' => ['fields' => ['id' => 1]], 'r' => ['step_id' => 2],
    'decision_url' => '/d', 'step_url' => '/s', 'outcome_url' => '/o', 'me' => 1];
$voprosy = [['key' => 'z', 'label' => 'Нужна закупка?']];
foreach ([false, true] as $mozhet) {
    $html = $tw->render('flow_action.html.twig', $baza + ['a' => ['action' => 'decision', 'decision_items' => $voprosy,
        'decision_answers' => [], 'decision_revision' => 1, 'can_approve' => $mozhet, 'validation_id' => $mozhet ? 9 : 0]]);
    ok(!str_contains($html, 'согласование останется открытым') && str_contains($html, '>Сохранить ответы</button>'),
        ($mozhet ? 'у согласования' : 'у задачи') . ' «Вопросы этапа» — без подсказки про открытое согласование');
}

// ---- «Согласующие ещё не выбраны» — не у будущего этапа (группа видна в столбце «Исполнитель»).
ok(StepLine::approversNotChosen(Step::PENDING) === [], 'будущий этап согласования — без «Согласующие ещё не выбраны»');
ok(StepLine::approversNotChosen(Step::RUNNING) === ['Согласующие ещё не выбраны.'], 'идущий этап без запросов — строка остаётся');
ok(StepLine::approversNotChosen(Step::DONE) === [] && StepLine::approversNotChosen(Step::NOT_NEEDED) === [], 'законченный этап — без строки');

// ---- Массовое «Обновление» → «Если действие не по порядку этапов» — выбор из значений.
$opcii = [];
foreach ((new Process())->rawSearchOptions() as $o) { if (($o['id'] ?? '') === '4') { $opcii = $o; } }
ok(($opcii['field'] ?? '') === 'enforcement' && ($opcii['datatype'] ?? '') === 'specific',
    'поиск и массовое изменение «Если действие не по порядку этапов» — выбором, не строкой', json_encode($opcii, JSON_UNESCAPED_UNICODE));
ok(Process::getSpecificValueToDisplay('enforcement', 'warn') === 'Предупредить'
    && Process::getSpecificValueToDisplay('enforcement', ['enforcement' => 'strict']) === 'Запретить',
    'в списке маршрутов значение — словами, не ключом');

// ---- «Любое назначение формы» — по режиму запуска, и без сохранения.
ok(FormConfiguration::destinationZeroLabel(['start_if' => null]) === 'Не выбрано'
    && FormConfiguration::destinationZeroLabel(['start_if' => '{"version":1,"mode":"always"}']) === 'Любое назначение формы, создающее заявку',
    'нулевой пункт «Назначение формы» — по режиму «Запускать, если»');
$polya = ['id' => 5, 'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 0, 'calendars_id' => 0, 'forms_forms_id' => 0,
    'forms_destinations_id' => 0, 'start_if' => null, 'itemtype' => 'Ticket', 'outcome_contract' => DefinitionSnapshot::MODE, 'name' => 'М'];
$chernovik = Process::draftFields($polya, ['is_active' => '0', 'is_recursive' => '1', 'name' => 'чужое', 'entities_id' => '-1',
    Process::START_EDITOR => ['mode' => 'always']]);
ok($chernovik['is_active'] === 0 && $chernovik['is_recursive'] === 1 && $chernovik['name'] === 'М' && $chernovik['entities_id'] === 0,
    'черновик маршрута: введённые «Маршрут включён» и «Подчинённые организации»; лишние и неверные поля — как сохранено',
    json_encode($chernovik, JSON_UNESCAPED_UNICODE));
ok(FormConfiguration::destinationZeroLabel($chernovik) === 'Любое назначение формы, создающее заявку',
    'выбрали «Всегда» без сохранения — нулевой пункт уже «Любое назначение формы…»');
$p = (string) file_get_contents($koren . '/templates/process.html.twig');
ok(str_contains($p, 'data-itilflow-readiness-box') && str_contains($p, "body.append('_itilflow_draft', 'process')")
    && str_contains($p, "addEventListener('itilflow:draft'"),
    'форма маршрута пересчитывает строку готовности без сохранения: своим запросом или ответом редактора «Запускать, если»');

// ---- Уточнение, которое вернул этап согласования, продолжается от ответа инициатора само.
ok(\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_APPROVAL)
    && !\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_INLINE)
    && !\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_DELEGATED) && !\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(null),
    'сам продолжается только возврат от этапа согласования: у задачи и отдела «Продолжить» нажимает исполнитель');
// «Задан в этапе» — сам всегда; «Согласование из формы» и «Указывает исполнитель» — только когда
// известны адресаты прошлого прохода (Engine::repeatPlan), иначе «Продолжить» у человека.
ok(\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_APPROVAL, Stage::APPROVER_FIXED)
    && \GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_APPROVAL, Stage::APPROVER_FORM, true)
    && \GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_APPROVAL, Stage::APPROVER_RUNTIME, true)
    && !\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_APPROVAL, Stage::APPROVER_FORM, false)
    && !\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_APPROVAL, Stage::APPROVER_RUNTIME, false)
    && !\GlpiPlugin\Itilflow\Engine::continuesOnAnswer(Stage::MODE_INLINE, Stage::APPROVER_FIXED, true),
    'из формы и «укажет исполнитель» — сам, только если адресаты прошлого прохода известны');
ok(StepLine::waitingInput(false, true) === 'Маршрут продолжится сам после вашего ответа.'
    && StepLine::waitingInput(true, true) === 'Маршрут продолжится сам, когда инициатор ответит комментарием.',
    'возврат от согласования: инициатору и сотруднику — что маршрут продолжится от ответа сам');
ok(StepLine::waitingInput(false, false) === 'Ждём вашего ответа.'
    && StepLine::waitingInput(true, false) === 'После ответа нажмите «Продолжить».'
    && !str_contains(StepLine::waitingInput(false, false), 'сам'),
    'адресаты неизвестны или вернул исполнитель: инициатору не обещано продолжение, сотруднику — «Продолжить»');

// Ответ годится только свой — вошедшего инициатора; письмо — только в сеансе сборщика почты.
$k = ['users_id' => 47, 'is_private' => 0];
ok(\GlpiPlugin\Itilflow\Engine::answerAuthor($k, [], 47, '') === 47, 'комментарий вошедшего автора — его ответ');
ok(\GlpiPlugin\Itilflow\Engine::answerAuthor($k, ['_mailgate' => true], 5, '') === 0,
    'поля «_mailgate» и автор из формы без сеанса сборщика почты не делают комментарий ответом инициатора');
ok(\GlpiPlugin\Itilflow\Engine::answerAuthor($k, ['_mailgate' => true], 0, 'cron_mailgate') === 47,
    'письмо в сеансе сборщика почты — ответ автора, которого ядро нашло по адресу');
ok(\GlpiPlugin\Itilflow\Engine::answerAuthor($k, [], 0, 'cron_mailgate') === 0, 'автоматическое действие без письма — не ответ');
ok(\GlpiPlugin\Itilflow\Engine::answerAuthor(['users_id' => 47, 'is_private' => 1], [], 47, '') === 0
    && \GlpiPlugin\Itilflow\Engine::answerAuthor(['users_id' => 0, 'is_private' => 0], ['_mailgate' => true], 0, 'cron_mailgate') === 0,
    'приватный комментарий и комментарий без автора — не ответ');

// Инициатору — просьба и вопрос согласующего в шапке; сотруднику — вопрос; без «Он».
ok(StepLine::waitingHeader(true, 'Уточните <b>модель</b>.') === 'Ответьте комментарием в заявке. Вопрос: «Уточните модель.»'
    && StepLine::waitingHeader(true, '') === 'Ответьте комментарием в заявке.'
    && StepLine::waitingHeader(false, 'Уточните модель.') === 'Вопрос инициатору: «Уточните модель.»'
    && StepLine::waitingHeader(false, '') === '',
    'шапка ожидания ответа — просьба и вопрос, без «Он ответит»');
ok(mb_strlen(StepLine::waitingHeader(false, str_repeat('я', 900))) < 540, 'длинный вопрос в шапке обрезан');

// Пояснение, повторяющее метку, не выводится; другое — остаётся.
ok(StepLine::withoutRepeat('Согласовано', 'Согласовано.') === '' && StepLine::withoutRepeat('Согласовано', 'согласовано') === ''
    && StepLine::withoutRepeat('Согласовано', 'Одобрили 1 из 1.') === 'Одобрили 1 из 1.' && StepLine::withoutRepeat('Выполнено', '') === '',
    '«Согласовано / Согласовано.» — без второй строки');
ok(\GlpiPlugin\Itilflow\Scheme::sentences(['Согласовано.', 'Согласовано.', '', 'Дата: 22.10.2026']) === 'Согласовано. Согласовано. Дата: 22.10.2026.'
    && \GlpiPlugin\Itilflow\Scheme::sentences(['не нужна.', 'Дата: 1']) === 'не нужна. Дата: 1.'
    && \GlpiPlugin\Itilflow\Scheme::sentences(['', ' ']) === '',
    'подпись карточки схемы — без «..» на стыке');

// «Ждём вашего действия» — только тому, кто должен ответить или выполнить этот этап.
ok(\GlpiPlugin\Itilflow\Ui::yourTurn(['visa'], false) && \GlpiPlugin\Itilflow\Ui::yourTurn(['complete'], true)
    && !\GlpiPlugin\Itilflow\Ui::yourTurn(['complete', 'override'], false) && !\GlpiPlugin\Itilflow\Ui::yourTurn([], true),
    '«Ждём вашего действия» администратору, который этап не выполняет, не показывается');

// Тексты.
ok(\GlpiPlugin\Itilflow\Ui::duration(120) === '2 мин' && \GlpiPlugin\Itilflow\Ui::duration(240) === '4 мин'
    && \GlpiPlugin\Itilflow\Ui::duration(3 * 3600 + 60) === '3 ч 1 мин',
    'длительность в листе и отчёте — «2 мин», а не склонение GLPI «2 минут»');
ok(StepLine::childName('Выдача', 12345) === 'Выдача — к заявке №12345' && !str_contains(StepLine::childName('Выдача', 1), '['),
    'название дочерней заявки — «Выдача — к заявке №…», без «[Этап 30, попытка 1]»');
ok(StepLine::restartFrom(20, 'Подготовить') === 'Начать заново с этапа 20 «Подготовить»',
    'один этап для начала заново — в пункте меню с номером и названием');
ok(Stage::neededLine(1, 1) === 'Нужно одобрений: 1 из 1.', 'столбец «Кто» — «Нужно одобрений: 1 из 1.», не «Сейчас нужно»');
ok(\GlpiPlugin\Itilflow\Transfer::choiceOnly(['missing' => 0, 'several' => 0, 'choose' => 2]) === 2
    && \GlpiPlugin\Itilflow\Transfer::choiceOnly(['missing' => 0, 'several' => 1, 'choose' => 1]) === 2
    && \GlpiPlugin\Itilflow\Transfer::choiceOnly(['missing' => 1, 'several' => 0, 'choose' => 2]) === 0,
    'импорт: только выбор — «Нужно выбрать: N»; если чего-то нет в базе — по-прежнему не импортируется');
$shablon2 = (string) file_get_contents($koren . '/templates/import_step2_preview.html.twig');
ok(str_contains($shablon2, "'Нужно выбрать: ' ~ r.choose") && str_contains($shablon2, "r.status == 'invalid' and r.choose|default(0) > 0"),
    'предпросмотр импорта: «Нужно выбрать: N» вместо «Не может быть импортирован»');
$shablon3 = (string) file_get_contents($koren . '/templates/import_step3_issues.html.twig');
ok(str_contains($shablon3, 'i.form_pending') && !str_contains($shablon3, 'Выберите форму и откройте предпросмотр повторно'),
    '«Устранять проблемы»: про выбор формы — только когда форма не выбрана');
ok(\GlpiPlugin\Itilflow\ValidationLadder::isService(sprintf(\GlpiPlugin\Itilflow\Engine::SHARED_LEGACY, 100))
    && \GlpiPlugin\Itilflow\ValidationLadder::isService(sprintf(\GlpiPlugin\Itilflow\ValidationLadder::SHARED, 50))
    && \GlpiPlugin\Itilflow\ValidationLadder::isService(\GlpiPlugin\Itilflow\ValidationLadder::name(10, 2, 1, 100))
    && !\GlpiPlugin\Itilflow\ValidationLadder::isService('Этап маршрута — согласовать 100%')
    && !\GlpiPlugin\Itilflow\ValidationLadder::isService('Согласование руководителя'),
    'служебная общая запись согласования до 1.11 (и под прежним именем) в выборе импорта не показывается');
$etap = (string) file_get_contents($koren . '/templates/stage.html.twig');
ok(str_contains($etap, ": 'Пусто — без срока.'") && !str_contains($etap, 'Пусто или 0'), 'подсказка срока — «Пусто — без срока.»');
$chernovikPhp = (string) file_get_contents($koren . '/front/draft.php');
ok(str_contains($chernovikPhp, 'if (!$process->can($pid, UPDATE)) {') // 1.13.2: и форма этапа
    && str_contains($chernovikPhp, '!$form->can($draftForm, READ)'),
    'пересчёт формы маршрута — только с правом изменять маршрут; чужая форма из ввода — только видимая');
$setup = (string) file_get_contents($koren . '/setup.php');
$hook = (string) file_get_contents($koren . '/src/Hook.php');
$flow = (string) file_get_contents($koren . '/src/StructuredFlow.php');
$nachalo = (int) strpos($setup, "\$PLUGIN_HOOKS[Hooks::ITEM_ADD]['itilflow'] = [");
$blok = substr($setup, $nachalo, (int) strpos($setup, '];', $nachalo) - $nachalo);
ok($nachalo > 0 && str_contains($blok, "'ITILFollowup' => [\$H, 'postFollowupAdd']")
    && str_contains($hook, 'Engine::initiatorAnswered($followup);'),
    'комментарий к заявке проходит через Engine::initiatorAnswered');
ok(str_contains($flow, "isUser(\\CommonITILActor::REQUESTER, \$author)") && str_contains($flow, "hash('sha256', 'initiator_answer:' . \$followup->getID())")
    && str_contains($flow, '\\Session::isCron()'),
    'продолжает только публичный комментарий самого инициатора; повтор того же комментария — та же команда');
// Продолжение от ответа — запись журнала маршрута, не комментарий от имени инициатора; сбой — запись администратору.
ok(str_contains($flow, "'initiator_answered', false, 'Инициатор ответил комментарием — маршрут продолжился сам.'")
    && str_contains($flow, "'continue_failed'") && str_contains($flow, 'self::$quiet = true;'),
    'ответ инициатора — в журнал маршрута; сбой продолжения — запись администратору, инициатору сообщений нет');

itog();
