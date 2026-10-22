<?php

/**
 * Строка этапа на заявке:
 * - «Не нужен по условию» с причиной: факты условия или текст человека и кто выбрал;
 * - инициатор заявки этих строк не видит;
 * - у будущего условного этапа — «Впереди» и «если …»; у инициатора условия нет.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\StepLine;

if (!estKlass(StepLine::class)) {
    itog();
}

$s = StepLine::notNeeded(['truth' => 'no'], '20: «Нужно дополнительное согласование» — нет', null, null, false);
ok($s['label'] === 'Не нужен по условию', 'метка — «Не нужен по условию»', $s['label']);
ok(str_contains($s['summary'], '20: «Нужно дополнительное согласование» — нет'), 'причина — факты условия', $s['summary']);
ok($s['visible'] === true, 'исполнитель строку видит');

$s = StepLine::notNeeded(['truth' => 'no'], '20: «Нужно дополнительное согласование» — нет', null, null, true);
ok($s['visible'] === false, 'инициатор заявки строку «Не нужен по условию» не видит');

$s = StepLine::notNeeded(['truth' => 'unknown', 'manual' => ['choice' => 'skip', 'reason' => 'Бюджет не нужен',
    'actor' => 5, 'at' => '2026-10-21 10:00:00']], '', 'Исполнитель 5', '21.10.2026 10:00', false);
ok(str_contains($s['summary'], 'Бюджет не нужен') && $s['author'] === 'Исполнитель 5',
    'выбор человека — его причина и кто выбрал', json_encode($s, JSON_UNESCAPED_UNICODE));

$v = StepLine::ahead('если 20: «Нужно дополнительное согласование» — да', false);
ok($v['label'] === 'Впереди' && $v['summary'] === 'если 20: «Нужно дополнительное согласование» — да',
    'будущий условный этап — «Впереди» и «если …»', json_encode($v, JSON_UNESCAPED_UNICODE));
ok(StepLine::ahead('если 20: «…» — да', true)['summary'] === '', 'у инициатора условия нет');

// ---- Строка этапа: состояния, ответы, видимость, лист согласования.

use GlpiPlugin\Itilflow\Sheet;
use GlpiPlugin\Itilflow\Step;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\Outcome;
use GlpiPlugin\Itilflow\Ui;

$ruchnoe = ['truth' => 'unknown', 'manual' => ['choice' => 'skip', 'reason' => 'Бюджет не нужен', 'actor' => 5, 'at' => '2026-10-21 10:00:00']];
$s = StepLine::notNeeded($ruchnoe, '', 'Исполнитель 5', '21.10.2026 10:00', false);
ok($s['date'] === '21.10.2026 10:00' && $s['visible'] && str_starts_with($s['summary'], 'Отмечен вручную.'),
    'выбор человека — когда выбрал и что этап отмечен вручную', json_encode($s, JSON_UNESCAPED_UNICODE));
ok(str_ends_with($s['summary'], 'Причина: Бюджет не нужен.')
    && str_ends_with(StepLine::notNeeded(['manual' => ['choice' => 'skip', 'reason' => 'Не нужен!']], '', null, null, false)['summary'], 'Не нужен!'),
    'причина человека — законченной фразой: в листе за ней «Выбрал: …»', $s['summary']);
$s = StepLine::notNeeded($ruchnoe, 'не должно попасть', 'Исполнитель 5', '21.10.2026 10:00', true);
ok($s['summary'] === '' && $s['author'] === null && $s['date'] === null && !$s['visible'],
    'инициатору не уходят ни причина, ни автор', json_encode($s, JSON_UNESCAPED_UNICODE));
$s = StepLine::notNeeded(['truth' => 'no'], '', null, null, false);
ok($s['label'] === 'Не нужен по условию' && $s['author'] === null, 'по условию автора нет — решило условие');
ok(StepLine::notNeeded(['truth' => 'no', 'manual' => ['choice' => 'run', 'reason' => 'x']], '20: «…» — нет', 'Кто-то', 'когда', false)['author'] === null,
    'ручной выбор «выполнить» не выдаётся за выбор «не нужен»');

$voprosy = [['key' => 'extra', 'label' => 'Нужно дополнительное согласование'], ['key' => 'budget', 'label' => 'За счёт бюджета']];
ok(StepLine::answers($voprosy, ['extra' => false, 'budget' => true], false) === 'Ответы этапа: Нужно дополнительное согласование — нет; За счёт бюджета — да.',
    'ответы этапа одной строкой', StepLine::answers($voprosy, ['extra' => false, 'budget' => true], false));
ok(StepLine::answers($voprosy, ['extra' => true], false) === 'Ответы этапа: Нужно дополнительное согласование — да; За счёт бюджета — нет ответа.',
    'нет ответа — не «нет»');
ok(StepLine::answers($voprosy, ['extra' => true], true) === '' && StepLine::answers([], [], false) === '',
    'инициатор вопросов этапа не видит; без вопросов строки нет');

// Кто в самообслуживании видит условия: согласующий этой заявки — да,
// наблюдатель и все остальные — как инициатор заявки. Вопрос «согласующий ли он» задаётся ядру GLPI
// по этой заявке (на сервере — CommonITILValidation::canValidate), здесь — замыканием без базы.
$zayavka = new \Ticket(); $zayavka->fields['id'] = 4321;
$sprosili = [];
$soglasuyushchij = static function (\CommonITILObject $i) use (&$sprosili): bool { $sprosili[] = (int) $i->getID(); return true; };
$nablyudatel = static fn(\CommonITILObject $i): bool => false;
$nelzya = static function (): bool { throw new \LogicException('в основном интерфейсе ядро не спрашивают'); };
$vidSogl = Ui::seesAsInitiator($zayavka, false, $soglasuyushchij);
$vidNabl = Ui::seesAsInitiator($zayavka, false, $nablyudatel);
ok($vidSogl === false && $sprosili === [4321], 'самообслуживание, согласующий этой заявки — не как инициатор; спросили ядро про эту заявку',
    json_encode(['vid' => $vidSogl, 'sprosili' => $sprosili]));
ok($vidNabl === true, 'самообслуживание, наблюдатель без запроса на согласование — как инициатор заявки');
ok(Ui::seesAsInitiator($zayavka, true, $nelzya) === false, 'основной интерфейс — всё видно, без вопроса к ядру');
// Что из этого следует в строке этапа: согласующий видит причину, «если …» и ответы этапа, наблюдатель — нет.
$prichina = '20: «Нужно дополнительное согласование» — нет';
$kak = static fn(bool $init): array => [StepLine::notNeeded(['truth' => 'no'], $prichina, null, null, $init),
    StepLine::ahead('если 20: «Нужно дополнительное согласование» — да', $init), StepLine::answers($voprosy, ['extra' => false], $init)];
[$nn, $vp, $ot] = $kak($vidSogl);
ok($nn['visible'] && $nn['summary'] === $prichina && $vp['summary'] !== '' && str_starts_with($ot, 'Ответы этапа:'),
    'согласующий из самообслуживания видит причину «Не нужен по условию», «если …» и ответы этапа');
[$nn, $vp, $ot] = $kak($vidNabl);
ok(!$nn['visible'] && $nn['summary'] === '' && $vp['summary'] === '' && $ot === '',
    'наблюдатель из самообслуживания — как инициатор: ни строки, ни условия, ни ответов');

ok(StepLine::homeUrl('helpdesk', '/glpi') === '/glpi/Helpdesk' && StepLine::homeUrl('central', '/glpi/') === '/glpi/front/central.php'
    && StepLine::homeUrl('helpdesk', '') === '/Helpdesk', 'после действия без доступа — на главную страницу человека');

$d = StepLine::notStarted('Срок слишком большой: этап должен закончиться до 19.01.2038. Укажите меньше.');
ok($d === 'Этап не запущен — срок слишком большой: этап должен закончиться до 19.01.2038. Укажите меньше.', 'причина, почему этап не открылся', $d);
ok(StepLine::notStarted('  ') === '', 'без причины строки нет');

ok(StepLine::rosterNotesVisible(Step::RUNNING) && StepLine::rosterNotesVisible(Step::PENDING)
    && !StepLine::rosterNotesVisible(Step::DONE) && !StepLine::rosterNotesVisible(Step::NOT_NEEDED) && !StepLine::rosterNotesVisible(Step::REJECTED),
    'предупреждения о согласующих — только у этапов впереди и идущих');

// Подписи состояний этапа — словами словаря.
ok(Step::stateLabel(['state' => Step::SUPERSEDED, 'date_start' => '2026-01-01 10:00:00']) === 'Не закончен'
    && Step::stateLabel(['state' => Step::SUPERSEDED, 'date_start' => '2026-01-01 10:00:00', 'superseded_by_attempt_id' => 3]) === 'Прежняя попытка'
    && Step::stateLabel(['state' => Step::SUPERSEDED]) === 'Не выполнялся'
    && Step::stateLabel(['state' => Step::PENDING]) === 'Впереди' && Step::stateLabel(['state' => Step::REJECTED]) === 'Отклонено'
    && Step::stateLabel(['state' => Step::NOT_NEEDED]) === 'Не нужен по условию', 'состояния этапа: «Впереди», «Не закончен», «Прежняя попытка», «Отклонено»');
$zapret = '/' . implode('|', ['Ожидает очереди', 'Не потребовал', 'Прекращ', 'Отклонён(?![а-яё])', 'Шаг маршрута']) . '/u';
ok(!preg_match($zapret, implode(' ', Step::getStateLabels()) . ' ' . Step::getTypeName(1) . ' ' . Step::getTypeName(2)), 'в подписях этапа нет прежних слов');

$sogl = ['execution_mode' => Stage::MODE_APPROVAL];
ok(Sheet::resultLabel($sogl + ['state' => Step::REJECTED], null, false) === 'Отклонено'
    && Sheet::resultLabel(['execution_mode' => Stage::MODE_DELEGATED, 'state' => Step::REJECTED], null, false) === 'Отказ',
    'лист: отказ у согласования — «Отклонено», у отдела — «Отказ»');
ok(Sheet::resultLabel($sogl + ['state' => Step::RUNNING], Outcome::CLARIFICATION, false) === 'На уточнении'
    && Sheet::resultLabel(['state' => Step::NOT_NEEDED], null, false) === 'Не нужен по условию'
    && Sheet::resultLabel(['state' => Step::PENDING], null, false) === 'Впереди'
    && Sheet::resultLabel(['state' => Step::PENDING], null, true) === 'Не выполнялся',
    'лист: те же слова, что в строке этапа');
// 1.13.1: одобренный этап согласования — «Согласовано» (пара к «Отклонено»), остальные — «Выполнено».
ok(Sheet::resultLabel($sogl + ['state' => Step::DONE], null, false) === 'Согласовано'
    && Sheet::resultLabel($sogl + ['state' => Step::DONE], Outcome::COMPLETED, false, 'native_validation') === 'Согласовано'
    && Sheet::resultLabel($sogl + ['state' => Step::DONE], Outcome::REFUSED, false, 'native_validation') === 'Отклонено',
    'этап согласования: одобрен — «Согласовано», отказ — «Отклонено»');
ok(Sheet::resultLabel(['execution_mode' => Stage::MODE_DELEGATED, 'state' => Step::DONE], null, false) === 'Выполнено'
    && Sheet::resultLabel(['execution_mode' => Stage::MODE_INLINE, 'state' => Step::DONE], Outcome::COMPLETED, false) === 'Выполнено',
    'задача и дочерняя заявка — «Выполнено»');
ok(Sheet::resultLabel($sogl + ['state' => Step::DONE], Outcome::COMPLETED, false, 'privileged_override') === 'Выполнено'
    && Sheet::resultLabel($sogl + ['state' => Step::DONE, 'comment' => 'Принудительное завершение: согласовано устно.'], null, false) === 'Выполнено',
    'согласование, закрытое принудительно без ответа, — не «Согласовано»');
$istochnikUiTerm = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Ui.php');
ok(str_contains($istochnikUiTerm, "\$label = Sheet::resultLabel(\$row, \$kind === 'done' ? Outcome::COMPLETED : Outcome::REFUSED"),
    'строка этапа на заявке берёт ту же подпись, что лист согласования');

// Настоящие шаблоны.
$korenT = dirname(__DIR__, 2) . '/templates';
$zagr = new \Twig\Loader\FilesystemLoader($korenT);
$zagr->addPath($korenT, 'itilflow');
$tw = new \Twig\Environment($zagr, ['autoescape' => 'html', 'strict_variables' => true]);
foreach (['csrf_token', 'call', 'path'] as $fn) { $tw->addFunction(new \Twig\TwigFunction($fn, static fn() => '')); }
$stroka = static fn(array $result, array $more = []): array => $more + ['rank' => 25, 'step_id' => 7, 'name' => 'Дополнительное согласование',
    'mode_label' => 'Согласование', 'mode_class' => 'bg-purple-lt', 'approval' => [], 'decisions' => [], 'owner' => 'Группа',
    'artifact' => '', 'current' => false, 'try' => 0, 'history' => [], 'due' => '', 'overdue' => false, 'took' => '',
    'actions' => [], 'menu' => [], 'result' => $result + ['internal' => '', 'author' => '', 'date' => '', 'class' => 'bg-secondary-lt']];
$vkladka = ['process_name' => 'Маршрут', 'only' => false, 'parent_link' => '', 'state' => ['В работе', 'bg-blue-lt', ''],
    'enforcement' => '', 'sheet_url' => '', 'header_menu' => [], 'can_resume' => false, 'withdraw' => false, 'violations' => [],
    'is_staff' => true, 'is_admin' => false, 'required_progress' => null, 'command' => null, 'instance' => ['fields' => ['id' => 1]],
    'rows' => [
        $stroka(['kind' => 'not_needed', 'label' => 'Не нужен по условию', 'summary' => '20: «Нужно дополнительное согласование» — нет']),
        $stroka(['kind' => 'pending', 'label' => 'Впереди', 'summary' => 'если 20: «Нужно дополнительное согласование» — да'], ['rank' => 30, 'step_id' => 8]),
    ]];
$html = $tw->render('flow_tab.html.twig', $vkladka);
ok(str_contains($html, 'Не нужен по условию') && str_contains($html, '20: «Нужно дополнительное согласование» — нет')
    && str_contains($html, 'если 20: «Нужно дополнительное согласование» — да'), 'вкладка: причина у «Не нужен по условию» и «если …» у будущего этапа');
ok(str_contains($html, '<th>Результат</th>') && !str_contains($html, '<th>Итог</th>') && str_contains($html, '<th>Исполнитель</th>'),
    'колонки «Исполнитель» и «Результат»');
// 1.13.1: строка про схему — только сотруднику с правом на маршруты, которому схема не показана.
$proSkhemu = 'Схема — у администратора маршрутов.';
ok(!str_contains($html, $proSkhemu) && !str_contains($html, 'видит только администратор'),
    'без права на маршруты (инициатор, согласующий, исполнитель) — строки про схему нет');
ok(str_contains($tw->render('flow_tab.html.twig', array_replace($vkladka, ['scheme_hint' => true])), $proSkhemu),
    'с правом чтения маршрутов, но без схемы — строка про схему');
ok(!str_contains($tw->render('flow_tab.html.twig', array_replace($vkladka, ['only' => true, 'scheme_hint' => true])), $proSkhemu),
    'на дочерней заявке строки про схему нет');
$sSkhemoj = $tw->render('flow_tab.html.twig', array_replace($vkladka, ['scheme_hint' => false,
    'scheme' => \GlpiPlugin\Itilflow\Scheme::build(['name' => 'Маршрут'], [])]));
ok(str_contains($sSkhemoj, '<summary><strong>Схема</strong></summary>') && !str_contains($sSkhemoj, $proSkhemu)
    && !str_contains($sSkhemoj, 'видит только'), 'администратору — сама схема, без пояснения «видит только…»');
// Кто получает строку: Ui::schemeHint($whole, $staff, $admin, $hasScheme, $canReadRoutes).
ok(!Ui::schemeHint(true, false, false, false, false) && !Ui::schemeHint(true, false, false, false, true),
    'самообслуживание (инициатор, согласующий) — строки нет, даже с правом на маршруты');
ok(!Ui::schemeHint(true, true, false, false, false), 'исполнитель без права на маршруты — строки нет');
ok(Ui::schemeHint(true, true, false, false, true), 'сотрудник с правом чтения маршрутов без схемы — строка есть');
ok(!Ui::schemeHint(true, true, true, false, true) && !Ui::schemeHint(true, true, false, true, true) && !Ui::schemeHint(false, true, false, false, true),
    'администратору маршрутов, при показанной схеме и на дочерней заявке — строки нет');
$html = $tw->render('flow_tab.html.twig', array_replace($vkladka, ['rows' => [$stroka(['kind' => 'pending', 'label' => 'Впереди',
    'summary' => 'Этап не запущен — срок слишком большой', 'problem' => true])]]));
ok(str_contains($html, 'text-danger">Этап не запущен'), 'причина в строке этапа заметна');
$html = $tw->render('flow_tab.html.twig', array_replace($vkladka, ['violations' => [
    ['date' => '2026-10-21', 'user_name' => 'А', 'message' => 'Срок', 'was_blocked' => 0, 'out_of_order' => false],
    ['date' => '2026-10-21', 'user_name' => 'Б', 'message' => 'Решение', 'was_blocked' => 1, 'out_of_order' => true]]]));
ok(str_contains($html, 'Журнал маршрута (2)') && str_contains($html, 'Решение — запрещено') && !str_contains($html, 'Срок — разрешено'),
    'журнал маршрута: отметка — только у действий не по порядку этапов');
// Номер изменения ответов этапа уходит в форму скрытым полем (защита от одновременной правки),
// но на экране его нет: ни в строке этапа, ни в раскрытой форме «Вопросы этапа».
$nomer = 7349;
$html = $tw->render('flow_tab.html.twig', array_replace($vkladka, ['me' => 1, 'step_url' => '/s', 'outcome_url' => '/o', 'decision_url' => '/d',
    'rows' => [$stroka(['kind' => 'running', 'label' => 'Сейчас', 'summary' => 'Ждём ответы на вопросы этапа.'], [
        'decisions' => [StepLine::answers($voprosy, ['extra' => true], false)],
        'actions' => [['action' => 'decision', 'label' => 'Вопросы этапа', 'decision_items' => $voprosy, 'decision_answers' => ['extra' => true],
            'decision_revision' => $nomer, 'can_approve' => false, 'validation_id' => 0]]])]]));
$vidno = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/su', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
ok(str_contains($html, 'name="expected_decision_revision" value="' . $nomer . '"') && !str_contains($vidno, (string) $nomer)
    && str_contains($vidno, 'Ответы этапа: Нужно дополнительное согласование — да;') && !preg_match('/евизи/u', $vidno),
    'номер изменения ответов — только в скрытом поле формы, на экране его нет');

$baza = ['command' => null, 'instance' => ['fields' => ['id' => 1]], 'r' => ['step_id' => 2],
    'decision_url' => '/d', 'step_url' => '/s', 'outcome_url' => '/o', 'me' => 1];
$html = $tw->render('flow_action.html.twig', $baza + ['a' => ['action' => 'condition', 'input_revision' => 'x', 'can_skip' => true]]);
ok(str_contains($html, '>Этап не нужен</button>') && str_contains($html, 'Причина') && !str_contains($html, 'Основание'),
    'кнопка «Этап не нужен», поле «Причина»');
$html = $tw->render('flow_action.html.twig', $baza + ['a' => ['action' => 'decision', 'decision_items' => $voprosy,
    'decision_answers' => [], 'decision_revision' => 4, 'can_approve' => true, 'validation_id' => 9]]);
ok(str_contains($html, 'Вопросы этапа') && str_contains($html, '>Сохранить ответы</button>') && str_contains($html, '>Сохранить и одобрить</button>')
    && !str_contains($html, 'Решение отдела'), '«Вопросы этапа», «Сохранить ответы»');

// 1.13.1: кнопки этапа не обрезаются — столбиком, таблица в обёртке с прокруткой.
$knopki = [['action' => 'decision', 'label' => 'Вопросы этапа', 'decision_items' => [], 'decision_answers' => [], 'decision_revision' => 0,
        'can_approve' => false, 'validation_id' => 0],
    ['action' => 'visa', 'label' => 'Одобрить или отказать', 'validation_id' => 3, 'validation_url' => '/v', 'can_return' => false, 'targets' => []],
    ['action' => 'adopt_existing', 'label' => 'Засчитать согласование', 'groups' => [], 'reason' => null]];
$html = $tw->render('flow_tab.html.twig', array_replace($vkladka, ['me' => 1, 'step_url' => '/s', 'outcome_url' => '/o', 'decision_url' => '/d',
    'rows' => [$stroka(['kind' => 'running', 'label' => 'Сейчас', 'summary' => 'Ждём вашего действия.'],
        ['actions' => $knopki, 'menu' => [['action' => 'reassign', 'label' => 'Передать этап…']]])]]));
$yachejka = preg_match('/<td data-label="" class="([^"]*itilflow-actions[^"]*)">\s*<div class="([^"]*)">/u', $html, $m) ? $m : [];
ok($yachejka !== [] && !str_contains($yachejka[1], 'text-nowrap') && str_contains($yachejka[2], 'flex-column'),
    'кнопки этапа — столбиком, без запрета переноса строки ячейки', $yachejka[1] ?? 'ячейки кнопок нет');
ok(substr_count($html, 'class="btn btn-sm btn-primary itilflow-act"') === 3 && str_contains($html, 'data-bs-popper-config=\'{"strategy":"fixed"}\''),
    'все три кнопки в ячейке; меню «Ещё» не обрезается прокруткой таблицы');
ok(preg_match('/<div class="table-responsive">\s*<table class="table[^"]*itilflow-route-table"/u', $html) === 1,
    'таблица маршрута — в обёртке с прокруткой: шире вкладки она не режется, а прокручивается');
ok(str_contains($html, '.itilflow-summary { max-width: 24rem; }') && str_contains($html, 'td.itilflow-actions { width: 100%; }'),
    'столбец «Результат» не шире 24rem; на узком экране кнопки во всю карточку');
// Узкая вкладка (1024 со столбиком вкладок GLPI — около 525 px, 1280 с открытым меню — около 780 px):
// карточки по ширине самой вкладки, а не только окна телефона. Шесть столбцов занимают от 715 px.
$stil = preg_match('/<style>(.*?)<\/style>/su', $html, $m) ? $m[1] : '';
$kartochki = static fn(string $blok): bool => str_contains($blok, '.itilflow-route-table thead { display: none; }')
    && str_contains($blok, 'td.itilflow-actions { width: 100%; }');
ok(str_contains($stil, 'container-type: inline-size; container-name: itilflow-route;')
    && preg_match('/@container itilflow-route \(max-width: 760px\) \{(.*?)\n   \}/su', $stil, $kont) === 1 && $kartochki($kont[1])
    && preg_match('/@media \(max-width: 575\.98px\) \{(.*?)\n   \}/su', $stil, $med) === 1 && $kartochki($med[1]),
    'карточки — и по ширине вкладки (@container до 760 px), и по ширине телефона (@media)');
$istochnikUi = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Ui.php');
ok(str_contains($istochnikUi, "'label' => 'Засчитать согласование'") && str_contains($istochnikUi, "'label' => 'Продолжить'")
    && str_contains($istochnikUi, "'label' => 'Одобрить или отказать'")
    && !str_contains($istochnikUi, 'Принять согласование из заявки') && !str_contains($istochnikUi, 'Продолжить после уточнения'),
    'подписи кнопок короче: «Засчитать согласование», «Продолжить»; у согласования — слова GLPI «Одобрить» / «Отказать»');
$html = $tw->render('flow_action.html.twig', $baza + ['a' => ['action' => 'activate']]);
ok(str_contains($html, 'Продолжить</button>') && !str_contains($html, 'Продолжить маршрут'), 'форма «Продолжить» — та же подпись');

// 1.13.1: «Дочерняя заявка дочерняя заявка №…» — без повтора: у значка — вид этапа, у ссылки — номер.
$ssylka = Ui::childLink('/front/ticket.form.php?id=12346', 'Выдача <оборудования>', 12346);
ok($ssylka === '<a href="/front/ticket.form.php?id=12346" title="Выдача &lt;оборудования&gt;">№12346</a>',
    'ссылка на дочернюю заявку — только номер, название — в подсказке (экранировано)', $ssylka);
$html = $tw->render('flow_tab.html.twig', array_replace($vkladka, ['rows' => [$stroka(['kind' => 'done', 'label' => 'Выполнено', 'summary' => ''],
    ['name' => 'Выдача', 'mode_label' => 'Дочерняя заявка', 'mode_class' => 'bg-azure-lt', 'artifact' => $ssylka])]]));
$tekstStroki = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
ok(preg_match_all('/дочерняя заявка/iu', $tekstStroki) === 1 && str_contains($tekstStroki, 'Дочерняя заявка №12346'),
    'в строке этапа «Дочерняя заявка №12346», слова не повторяются', $tekstStroki);

$list = ['header' => ['process' => 'Маршрут', 'version' => 3, 'item_type' => 'Заявка', 'item_id' => 5, 'item_name' => 'Тестовая заявка',
    'entity' => 'Орг', 'requester' => 'Иванов', 'state' => 'В работе', 'progress' => 'Закончено этапов: 1 из 3', 'date_start' => '', 'date_end' => '',
    'printed' => '', 'required_progress' => Sheet::requiredProgress([['state' => Step::DONE], ['state' => Step::NOT_NEEDED], ['state' => Step::SUPERSEDED]])],
    'rows' => [['no' => '25', 'name' => 'Согласование', 'mode' => 'Согласование', 'actual' => 'Группа', 'planned' => 'Группа', 'reassigned' => false,
        'reason' => '', 'approver_set_by' => '', 'approver_reason' => '', 'closed_by' => '', 'approval' => [], 'date_start' => '', 'date_due' => '',
        'date_end' => '', 'duration' => '—', 'overdue' => false, 'result' => 'Не нужен по условию',
        'why' => '20: «Нужно дополнительное согласование» — нет', 'decisions' => [], 'comment' => '']]];
$html = $tw->render('sheet.html.twig', $list);
ok(str_contains($html, '20: «Нужно дополнительное согласование» — нет') && str_contains($html, 'Инициатор заявки')
    && str_contains($html, '· не выполнялись: 1') && !preg_match('/прохожд|редакци|Заявител/u', $html),
    'лист согласования: причина «Не нужен по условию» и слова словаря');
// 1.13.1: заголовок без версии; состояние — отдельной строкой; счётчики — одной строкой без нулевых.
$tekstLista = preg_match('/Закончено этапов:[^<]*/u', $html, $stroka1) ? preg_replace('/\s+/u', ' ', $stroka1[0]) : '';
ok(str_contains($html, 'Маршрут «Маршрут»</div>') && !str_contains($html, 'версия 3') && str_contains($html, '<div>В работе</div>')
    && str_contains($tekstLista, 'Закончено этапов: 1 из 3 · нужно пройти: ') && !str_contains($tekstLista, ': 0')
    && substr_count($html, 'нужно пройти') === 1 && !str_contains($tekstLista, 'ещё не ясно'),
    'лист согласования: «Маршрут «…»» без версии, «В работе» не слито с «Закончено этапов», счётчики одной строкой без нулей',
    mb_substr($tekstLista, 0, 500));

// 1.13.1: комментарий согласующего в листе — коротко, рядом с «Согласовано» / «Отклонено».
ok(Sheet::approverComment(['source_kind' => 'native_validation', 'reason' => "<p>Бюджет\n есть.</p>"]) === 'Бюджет есть.'
    && Sheet::approverComment(['source_kind' => 'native_validation', 'reason' => '']) === ''
    && Sheet::approverComment(['source_kind' => 'assignee_action', 'reason' => 'Сделано.']) === ''
    && Sheet::approverComment(['source_kind' => 'privileged_override', 'reason' => 'Закрыл сам.']) === '',
    'комментарий согласующего — только из ответа на запрос согласования, одной строкой');
$dlinnyj = Sheet::approverComment(['source_kind' => 'native_validation', 'reason' => str_repeat('слово ', 100)]);
ok(mb_strlen($dlinnyj) === 200 && str_ends_with($dlinnyj, '…'), 'длинный комментарий согласующего — коротко, до 200 знаков', (string) mb_strlen($dlinnyj));
$soglasovano = array_replace($list['rows'][0], ['no' => '10', 'mode' => 'Согласование', 'result' => 'Согласовано', 'why' => '',
    'approver_comment' => 'Бюджет <есть>.', 'comment' => '']);
$otkaz = array_replace($soglasovano, ['no' => '20', 'result' => 'Отклонено', 'approver_comment' => 'Нет бюджета.']);
$htmlK = $tw->render('sheet.html.twig', array_replace($list, ['rows' => [$soglasovano, $otkaz]]));
$tekstK = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($htmlK), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
ok(str_contains($tekstK, 'Согласовано Комментарий согласующего: Бюджет <есть>. —')
    && str_contains($tekstK, 'Отклонено Комментарий согласующего: Нет бюджета. —')
    && str_contains($htmlK, 'Бюджет &lt;есть&gt;.') && !str_contains($tekstK, 'Согласовано.'),
    'лист: под «Согласовано» и «Отклонено» — комментарий согласующего (экранирован), в «Что сделано» без «Согласовано.»',
    mb_substr($tekstK, (int) mb_strpos($tekstK, 'Что сделано'), 600));

itog();
