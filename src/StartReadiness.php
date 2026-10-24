<?php
namespace GlpiPlugin\Itilflow;

use Glpi\Form\Form;
use Glpi\Form\AnswersSet;
use Glpi\Form\Question;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Destination\AnswersSet_FormDestinationItem;
use Glpi\Form\Destination\CommonITILField\EntityField;
use Glpi\Form\Destination\CommonITILField\EntityFieldStrategy;
use Glpi\Form\Condition\CreationStrategy;
use Glpi\Form\Condition\Engine as FormEngine;
use Glpi\Form\Condition\EngineInput;
use Glpi\Form\Condition\Type;
use Glpi\Form\Condition\VisibilityStrategy;

/**
 * Готовность запуска по ответу формы: только чтение.
 *
 * Пересечения и пробелы считаются ПО НАЗНАЧЕНИЮ формы, а не по форме целиком: для назначения D
 * сравниваются маршруты с назначением D и маршруты с «любым» назначением той же формы. Иначе на
 * форме с 20 назначениями было бы ложное «подходят 20 маршрутов».
 *
 * Перебираются ответы вопросов из «Запускать, если» этих маршрутов и вопросы-списки, от которых
 * по настройке GLPI зависит создание назначения и видимость этих вопросов. Создаётся ли заявка
 * назначения и виден ли вопрос — решает только ядро (Glpi\Form\Condition\Engine). Если ядру
 * нужны другие ответы, однозначного ответа нет: пересечение называется «могут подойти».
 *
 * Стык — Readiness::check: problems/notes — тексты строк готовности (без ссылки на этап),
 * title — хвост заголовка «Готов к запуску · N этапов · …», null — заголовок как в 1.12.
 */
final class StartReadiness
{
    private const MAX_VARIANTS = 256;
    /** Сколько вариантов ответов называть в одной строке готовности. */
    private const MAX_CASES = 3;
    /** Виды вопросов, ответы которых перебираются полностью: условия запуска по ним точны. */
    private const ENUMERABLE = ['choice', 'bool', 'text', 'file', 'number', 'date'];
    /** Дата подачи в переборе: от неё считаются «через N дней». */
    private const PREVIEW_SUBMITTED = '2030-01-15 12:00:00';

    /**
     * Ошибки StartRule::validate; «Только если» без условий; по каждому назначению —
     * вариант ответов, при котором подходят два маршрута (вместе с этим), и вариант без маршрута;
     * включённые правила с действием «Запустить маршрут этапов» в организациях назначения;
     * недавние заявки формы без маршрута — из журнала.
     *
     * @return array{problems:list<string>,notes:list<string>,title:?string}
     */
    public static function check(array $process): array
    {
        $out = ['problems' => [], 'notes' => [], 'title' => null];
        $start = StartRule::decode($process['start_if'] ?? null);
        if ($start === null) { return $out; }
        try {
            $form = new Form(); $formId = (int) ($process['forms_forms_id'] ?? 0);
            if ($formId <= 0 || !$form->getFromDB($formId)) {
                $out['problems'][] = 'Выберите доступную форму для запуска маршрута.'; return $out;
            }
            $out['title'] = 'запускается по ответу формы «' . $form->fields['name'] . '»';
            if (empty($form->fields['is_active'])) { $out['problems'][] = 'Выбранная форма выключена.'; }
            if (($process['itemtype'] ?? '') !== 'Ticket' || ($process['outcome_contract'] ?? '') !== DefinitionSnapshot::MODE) {
                $out['problems'][] = 'Запуск по форме доступен только для маршрута заявок нового порядка.';
            }
            $catalog = FormFacts::questionCatalog($formId, false);
            $out['problems'] = array_merge($out['problems'], StartRule::validate($start, $catalog));
            $all = array_values(array_filter($form->getDestinations(), static fn($d): bool =>
                ($d->fields['itemtype'] ?? '') === FormDestinationTicket::class));
            $labels = self::destinationLabels($form, $all);
            $destinations = array_filter($all, static fn($d): bool =>
                (int) ($process['forms_destinations_id'] ?? 0) === 0 || (int) $d->getID() === (int) $process['forms_destinations_id']);
            if (!$destinations) { $out['problems'][] = 'У выбранной формы нет подходящего назначения, создающего заявку.'; }
            $routes = self::routeRows($process);
            $routes[(int) ($process['id'] ?? 0)] = $process;
            $entities = []; $evaluations = 0; $dynamicEntities = false;
            foreach ($destinations as $destination) {
                $label = 'Назначение ' . $labels[(int) $destination->getID()];
                $entity = self::destinationEntity($form, $destination);
                if ($entity === null) {
                    $dynamicEntities = true;
                    $out['notes'][] = $label . ': организация известна только при отправке — проверка неполная.';
                    continue;
                }
                $ancestors = array_map('intval', getAncestorsOf('glpi_entities', $entity));
                if (!self::fitsEntity($process, $entity, $ancestors)) {
                    $out['problems'][] = $label . ': организация создаваемой заявки не входит в область этого маршрута.';
                    continue;
                }
                // Правила подавляются только там, где этот маршрут подходит заявке (FormStart::enabledFor).
                $entities[] = $entity;
                $candidates = self::forDestination(array_values($routes), (int) $destination->getID(), $entity, $ancestors);
                $dependencies = self::coreDependencies($form, $destination, $catalog, self::usedQuestions($candidates));
                $local = $catalog;
                foreach ($dependencies['enumerate'] as $uuid) { $local[$uuid]['_coverage_required'] = true; }
                $variants = [];
                foreach (self::coverage($candidates, $local) as $variant) {
                    $variant['created'] = 'maybe';
                    if ($variant['complete'] && $dependencies['exact']) {
                        if ($evaluations >= self::MAX_VARIANTS) {
                            $variant['complete'] = false; $variant['reason'] = 'variant_limit';
                        } else {
                            $evaluations++;
                            $observed = self::coreVariant($form, $destination, $variant['facts'], $local);
                            if ($observed !== null && !$observed['possible']) { continue; }
                            if ($observed !== null) { $variant['created'] = $observed['created'] ? 'yes' : 'no'; }
                        }
                    }
                    $variants[] = $variant;
                }
                $found = self::findings((int) ($process['id'] ?? 0), $label, $candidates, $variants);
                $out['problems'] = array_merge($out['problems'], $found['problems']);
                $out['notes'] = array_merge($out['notes'], $found['notes']);
            }
            // При динамической организации правила проверяем только в области самого маршрута.
            if ($dynamicEntities || !$entities) { $entities = array_merge($entities, self::entityScope($process)); }
            $out['notes'] = array_merge($out['notes'], self::ruleNotes((int) ($process['id'] ?? 0), array_values(array_unique($entities))));
            $recent = self::recentUnrouted($formId, self::entityScope($process));
            if ($recent['total'] > 0) {
                $n = $recent['total'];
                $out['notes'][] = 'За последние 30 дней ' . $n . ' ' . ConditionText::plural($n, 'заявка', 'заявки', 'заявок')
                    . ' с этой формы ' . ConditionText::plural($n, 'осталась', 'остались', 'остались') . ' без маршрута.'
                    . ($recent['last'] !== null ? ' Последняя — заявка ' . $recent['last']['ticket'] . ': ' . $recent['last']['message'] : '');
            }
        } catch (\Throwable) {
            TransactionScope::assertHealthy();
            $out['notes'][] = 'Не удалось полностью проверить запуск по форме. Проверьте форму и журнал маршрута перед включением.';
        }
        $out['problems'] = array_values(array_unique($out['problems']));
        $out['notes'] = array_values(array_unique($out['notes']));
        return $out;
    }

    /**
     * Строки готовности одного назначения по перебранным вариантам ответов — чистая, без базы.
     * Пересечение — проблема, только если среди подошедших есть этот маршрут ($current): чужое
     * пересечение покажет готовность тех маршрутов. Вариант без маршрута — примечание.
     * $variants — строки coverage с ключом created: yes — назначение создаётся (ядро),
     * no — не создаётся, maybe — ядро не дало однозначного ответа.
     *
     * @param list<array{id:int,name:string,start:array}> $candidates
     * @return array{problems:list<string>,notes:list<string>}
     */
    public static function findings(int $current, string $label, array $candidates, array $variants): array
    {
        $names = [];
        foreach ($candidates as $candidate) { $names[(int) $candidate['id']] = '«' . $candidate['name'] . '»'; }
        $groups = []; $partial = false; $created = false; $mine = false;
        foreach ($variants as $variant) {
            $state = $variant['created'] ?? 'maybe';
            if ($state === 'no') { continue; }
            if (empty($variant['complete'])) { $partial = true; continue; }
            $created = true;
            $yes = array_values(array_unique(array_map('intval', $variant['yes'] ?? [])));
            $unknown = array_values(array_diff(array_map('intval', $variant['unknown'] ?? $variant['maybe'] ?? []), $yes));
            if (in_array($current, $yes, true) && $state === 'yes') { $mine = true; }
            // Как FormStart::select: два «да» — пересечение; ни одного «да» или есть «неизвестно» —
            // маршрут не запустится; ровно один «да» без «неизвестно» — запуск.
            if (count($yes) > 1) {
                if (!in_array($current, $yes, true)) { continue; }
                sort($yes);
                $key = 'many|' . $state . '|' . implode(',', $yes);
                $groups[$key] ??= ['kind' => 'many', 'sure' => $state === 'yes', 'ids' => $yes, 'cases' => []];
            } elseif ($yes === [] || $unknown !== []) {
                $key = 'none|' . $state;
                $groups[$key] ??= ['kind' => 'none', 'sure' => $state === 'yes', 'ids' => [], 'cases' => []];
            } else {
                continue;
            }
            $groups[$key]['cases'][] = (string) ($variant['case'] ?? '');
        }
        $problems = []; $notes = [];
        foreach ($groups as $group) {
            $cases = self::cases($group['cases']);
            if ($group['kind'] === 'many') {
                $n = count($group['ids']);
                $list = implode(', ', array_map(static fn(int $id): string => $names[$id] ?? '«без названия»', $group['ids']));
                $problems[] = $label . ': ' . ($cases !== '' ? 'если ' . $cases . ', ' : '')
                    . ($group['sure'] ? 'подходят ' : 'могут подойти ') . $n . ' ' . ConditionText::plural($n, 'маршрут', 'маршрута', 'маршрутов')
                    . ' — ' . $list . '. Должен запускаться один: измените «Запускать, если».';
            } else {
                $notes[] = $label . ': ' . ($cases !== '' ? 'если ' . $cases . ', ' : '')
                    . 'не запускается ни один маршрут — заявка останется без маршрута.';
            }
        }
        if ($partial) {
            $notes[] = $label . ': ответы со списком пользователей, групп и категорий не проверены — проверьте вручную.';
        } elseif (!$created) {
            $notes[] = $label . ': ни при одном варианте ответов форма не создаёт заявку этого назначения.';
        } elseif (!$mine && !array_filter($variants, static fn(array $v): bool => ($v['created'] ?? '') === 'maybe')) {
            $notes[] = $label . ': этот маршрут не подходит ни при одном варианте ответов.';
        }
        return ['problems' => $problems, 'notes' => $notes];
    }

    /** ««Категория» — «Вариант А» или «Категория» — нет ответа» и «и ещё N». */
    private static function cases(array $cases): string
    {
        $cases = array_values(array_unique(array_filter($cases, static fn(string $c): bool => $c !== '')));
        if ($cases === []) { return ''; }
        $shown = array_slice($cases, 0, self::MAX_CASES);
        $rest = count($cases) - count($shown);
        return implode(' или ', $shown) . ($rest > 0 ? ' (и ещё ' . $rest . ' ' . ConditionText::plural($rest, 'вариант', 'варианта', 'вариантов') . ')' : '');
    }

    /**
     * «Другие маршруты этой формы» для блока «Запуск маршрута»: включённые маршруты той же формы
     * с запуском по форме и тем же назначением или «любым». Только те, что видит пользователь.
     *
     * @return list<array{id:int,name:string,summary:string,destination:string}>
     */
    public static function siblings(array $process): array
    {
        $out = []; $labels = null;
        foreach (self::siblingRows($process, self::routeRows($process)) as $row) {
            $object = new Process();
            if (!$object->can((int) $row['id'], READ)) { continue; }
            $destination = 'любое назначение';
            if ((int) $row['forms_destinations_id'] > 0) {
                if ($labels === null) {
                    $form = new Form();
                    $labels = $form->getFromDB((int) $process['forms_forms_id'])
                        ? self::destinationLabels($form, array_values(array_filter($form->getDestinations(),
                            static fn($d): bool => ($d->fields['itemtype'] ?? '') === FormDestinationTicket::class))) : [];
                }
                $destination = 'назначение ' . ($labels[(int) $row['forms_destinations_id']] ?? 'удалено');
            }
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'],
                'summary' => StartRule::describe(StartRule::decode($row['start_if'])), 'destination' => $destination];
        }
        return $out;
    }

    /**
     * «Другие маршруты этой формы» (блок «Запуск маршрута»):
     * все маршруты формы с запуском по ответу формы, кроме самого маршрута, — и на других
     * назначениях. Назначение каждого называется в строке. Пересечения по назначению считает
     * готовность (check), а не этот список.
     *
     * @param array<int,array> $rows маршруты формы (routeRows)
     * @return list<array>
     */
    public static function siblingRows(array $process, array $rows): array
    {
        $own = (int) ($process['id'] ?? 0);
        return array_values(array_filter($rows, static fn($row): bool => is_array($row) && (int) ($row['id'] ?? 0) !== $own));
    }

    /**
     * Чистое покрытие для одного назначения: перебор ответов вопросов из «Запускать, если»
     * маршрутов $routes и вопросов с отметкой _coverage_required. Списки и флажки — все
     * варианты; текст и файл — «заполнено / не заполнено»; число и дата — по одному значению
     * на каждый промежуток между числами и днями самих условий (результат условий внутри
     * промежутка не меняется). Пользователи, группы, категории не перебираются — вариант
     * неполный (complete = false). «нет ответа» — настоящее состояние отправки (вопрос
     * необязательный или скрыт), такой вариант полный.
     * yes — маршруты, которые точно подходят; maybe — могут подойти (yes ⊆ maybe);
     * unknown — «неизвестно»: при запуске такой маршрут не запускает никого.
     *
     * @param list<array{id:int,name:string,start:array}> $routes
     * @param array<string,array> $catalog FormFacts::questionCatalog
     * @return list<array{question:string,option:string,case:string,yes:list<int>,maybe:list<int>,unknown:list<int>,facts:array,complete:bool,reason:?string}>
     */
    public static function coverage(array $routes, array $catalog): array
    {
        $used = self::usedQuestions($routes);
        foreach ($catalog as $uuid => $q) { if (!empty($q['_coverage_required'])) { $used[$uuid] = $uuid; } }
        // Числа и дни из самих условий — границы промежутков для числа и даты.
        $bounds = [];
        foreach ($routes as $route) {
            foreach ($route['start']['rule']['clauses'] ?? [] as $clause) {
                if (is_array($clause) && is_string($clause['question_uuid'] ?? null)
                    && (is_string($clause['value'] ?? null) || is_int($clause['value'] ?? null))) {
                    $bounds[$clause['question_uuid']][] = $clause['value'];
                }
            }
        }
        $variants = [['facts' => [], 'questions' => [], 'options' => [], 'cases' => [], 'complete' => true]];
        foreach ($used as $uuid) {
            $q = $catalog[$uuid] ?? ['uuid' => $uuid, 'label' => 'Вопрос недоступен', 'type' => 'unsupported'];
            $values = self::values($q, $bounds[$uuid] ?? []);
            if ($values === null || count($variants) * count($values) > self::MAX_VARIANTS) {
                return [['question' => '', 'option' => 'Перебор ограничен', 'case' => '', 'yes' => [],
                    'maybe' => array_values(array_unique(array_column($routes, 'id'))), 'unknown' => [], 'facts' => [],
                    'complete' => false, 'reason' => 'variant_limit']];
            }
            $next = [];
            $title = (string) ($q['label'] ?? '') !== '' ? (string) $q['label'] : 'Вопрос без названия';
            foreach ($variants as $variant) {
                foreach ($values as $value) {
                    $v = $variant; $v['facts'][$uuid] = $value['fact'];
                    $v['questions'][] = $title;
                    $v['options'][] = $value['label'];
                    $v['cases'][] = '«' . $title . '» — ' . ($value['quote'] ? '«' . $value['label'] . '»' : $value['label']);
                    $v['complete'] = $v['complete'] && $value['complete'];
                    $next[] = $v;
                }
            }
            $variants = $next;
        }
        $out = [];
        foreach ($variants as $variant) {
            $facts = ['version' => 1, 'status' => 'final', 'facts' => $variant['facts'],
                'source' => ['mode' => 'preview', 'submitted_at' => self::PREVIEW_SUBMITTED]];
            $selected = self::selection($routes, $facts, $catalog);
            $out[] = ['question' => implode('; ', $variant['questions']), 'option' => implode('; ', $variant['options']),
                'case' => implode(', ', $variant['cases'])]
                + $selected + ['facts' => $facts, 'complete' => $variant['complete'],
                    'reason' => $variant['complete'] ? null : 'unknown_answer'];
        }
        return $out;
    }

    /** Вопросы из «Запускать, если» маршрутов, по порядку первого упоминания. @return array<string,string> */
    private static function usedQuestions(array $routes): array
    {
        $used = [];
        foreach ($routes as $route) {
            foreach ($route['start']['rule']['clauses'] ?? [] as $clause) {
                if (is_array($clause) && is_string($clause['question_uuid'] ?? null) && $clause['question_uuid'] !== '') {
                    $used[$clause['question_uuid']] = $clause['question_uuid'];
                }
            }
        }
        return $used;
    }

    private static function selection(array $routes, array $facts, array $catalog): array
    {
        $yes = []; $maybe = [];
        foreach ($routes as $route) {
            $truth = StartRule::validate($route['start'], $catalog) === []
                ? StartRule::evaluate($route['start'], $facts)['truth'] : 'unknown';
            if ($truth === 'yes') { $yes[] = (int) $route['id']; }
            if ($truth !== 'no') { $maybe[] = (int) $route['id']; }
        }
        $yes = array_values(array_unique($yes)); $maybe = array_values(array_unique($maybe));
        return ['yes' => $yes, 'maybe' => $maybe, 'unknown' => array_values(array_diff($maybe, $yes))];
    }

    /**
     * Ответы вопроса для перебора; null — слишком много. $bounds — значения условий по этому
     * вопросу: числа (строкой) для числа, N дней для даты.
     */
    private static function values(array $q, array $bounds = []): ?array
    {
        $type = $q['type'] ?? 'unsupported';
        $fact = static fn(string $status, mixed $value, ?string $reason): array =>
            ['status' => $status, 'type' => $type, 'value' => $value, 'reason' => $reason];
        if (empty($q['supported']) || !in_array($type, self::ENUMERABLE, true)) {
            return [['label' => 'ответ не перебирается', 'quote' => false, 'complete' => false,
                'fact' => $fact('unknown', null, 'preview_unknown')]];
        }
        $known = static fn(mixed $value, string $label, bool $quote): array =>
            ['label' => $label, 'quote' => $quote, 'complete' => true, 'fact' => $fact('known', $value, null)];
        $noAnswer = ['label' => 'нет ответа', 'quote' => false, 'complete' => true, 'fact' => $fact('unknown', null, 'preview_no_answer')];
        $values = [];
        if ($type === 'bool') {
            $values = [$known(false, 'не отмечен', false), $known(true, 'отмечен', false)];
        } elseif ($type === 'text' || $type === 'file') {
            $values[] = $known(true, $type === 'file' ? 'приложен' : 'заполнено', false);
            if (empty($q['required'])) { $values[] = $known(false, $type === 'file' ? 'не приложен' : 'не заполнено', false); }
            // Пустой видимый текст — известное «не заполнено»; «нет ответа» — только у скрытого.
            if (!empty($q['conditional'])) { $values[] = $noAnswer; }
            return $values;
        } elseif ($type === 'date') {
            // «Раньше / позже, чем через N дней»: дни N−1, N, N+1 и день подачи покрывают все исходы.
            $days = [0];
            foreach ($bounds as $n) { if (is_int($n)) { array_push($days, $n - 1, $n, $n + 1); } }
            $days = array_values(array_unique($days)); sort($days);
            if (count($days) > self::MAX_VARIANTS) { return null; }
            $from = new \DateTimeImmutable(self::PREVIEW_SUBMITTED, new \DateTimeZone('UTC'));
            foreach ($days as $d) {
                $values[] = $known($from->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d H:i:s'),
                    $d === 0 ? 'в день подачи' : ($d > 0 ? 'через ' . $d . ' дн. после подачи' : 'за ' . -$d . ' дн. до подачи'), false);
            }
            if (empty($q['required'])) { $values[] = $known(null, 'не заполнено', false); }
            if (!empty($q['conditional'])) { $values[] = $noAnswer; }
            return $values;
        } elseif ($type === 'number') {
            // Числа условий и по одному числу в каждом промежутке между ними — исход любого сравнения.
            $points = array_values(array_unique(array_filter($bounds, static fn($b): bool => is_string($b) && FormFacts::decimal($b) !== null)));
            usort($points, [Condition::class, 'decimalCompare']);
            $fmt = static function (float $x): string {
                $s = rtrim(rtrim(sprintf('%.6F', $x), '0'), '.');
                return $s === '' || $s === '-0' ? '0' : $s;
            };
            $numbers = $points ? [$fmt((float) $points[0] - 1)] : ['0'];
            foreach ($points as $i => $p) {
                $numbers[] = $p;
                if (isset($points[$i + 1])) { $numbers[] = $fmt(((float) $p + (float) $points[$i + 1]) / 2); }
            }
            if ($points) { $numbers[] = $fmt((float) end($points) + 1); }
            $numbers = array_values(array_unique(array_filter($numbers, static fn(string $n): bool => FormFacts::decimal($n) !== null)));
            if (count($numbers) > self::MAX_VARIANTS) { return null; }
            foreach ($numbers as $n) { $values[] = $known(FormFacts::decimal($n), $n, false); }
        } else {
            $options = $q['options'] ?? [];
            if (!$options) { return [['label' => 'ответ не перебирается', 'quote' => false, 'complete' => false,
                'fact' => $fact('unknown', null, 'preview_unknown')]]; }
            if (!empty($q['multiple'])) {
                if (count($options) > 8) { return null; }
                for ($mask = 0; $mask < (1 << count($options)); $mask++) {
                    $keys = []; $labels = [];
                    foreach ($options as $i => $option) {
                        if ($mask & (1 << $i)) { $keys[] = (string) $option['key']; $labels[] = (string) $option['label']; }
                    }
                    if (!$keys && !empty($q['required'])) { continue; }
                    sort($keys, SORT_STRING);
                    $values[] = $keys ? $known($keys, implode('», «', $labels), true) : $known([], 'ничего не выбрано', false);
                }
            } else {
                if (count($options) > self::MAX_VARIANTS) { return null; }
                foreach ($options as $option) { $values[] = $known((string) $option['key'], (string) $option['label'], true); }
            }
        }
        if (empty($q['required']) || !empty($q['conditional'])) { $values[] = $noAnswer; }
        return $values;
    }

    /** Область организации — то же отношение предков, что у Authorizer::processFitsItem. */
    public static function fitsEntity(array $route, int $entity, array $ancestors): bool
    {
        $owner = (int) ($route['entities_id'] ?? 0);
        return $owner === $entity || (!empty($route['is_recursive']) && in_array($owner, $ancestors, true));
    }

    /** Вход уже ограничен одной формой; текущий редактируемый маршрут проверяется и до включения. */
    public static function forDestination(array $routes, int $destination, int $entity, array $ancestors): array
    {
        $out = [];
        foreach ($routes as $route) {
            $dest = (int) ($route['forms_destinations_id'] ?? 0);
            if (($dest !== 0 && $dest !== $destination) || !self::fitsEntity($route, $entity, $ancestors)) { continue; }
            $start = StartRule::decode($route['start_if'] ?? null);
            if ($start !== null) { $out[] = ['id' => (int) ($route['id'] ?? 0), 'name' => (string) ($route['name'] ?? ''), 'start' => $start]; }
        }
        return $out;
    }

    private static function routeRows(array $process): array
    {
        global $DB;
        if ((int) ($process['forms_forms_id'] ?? 0) <= 0) { return []; }
        $out = [];
        foreach ($DB->request(['FROM' => Process::getTable(), 'WHERE' => ['forms_forms_id' => (int) $process['forms_forms_id'],
            'itemtype' => 'Ticket', 'outcome_contract' => DefinitionSnapshot::MODE, 'is_active' => 1], 'ORDER' => ['name', 'id']]) as $row) {
            if (StartRule::isOn($row)) { $out[(int) $row['id']] = $row; }
        }
        return $out;
    }

    /**
     * Назначения называются по имени; у одноимённых — ещё когда создаются (как в списке
     * «Назначение формы»): у формы часто несколько назначений «Заявка».
     *
     * @param list<FormDestination> $destinations
     * @return array<int,string>
     */
    private static function destinationLabels(Form $form, array $destinations): array
    {
        $count = [];
        foreach ($destinations as $d) { $n = trim((string) ($d->fields['name'] ?? '')); $count[$n] = ($count[$n] ?? 0) + 1; }
        $out = [];
        foreach ($destinations as $d) {
            $n = trim((string) ($d->fields['name'] ?? ''));
            $out[(int) $d->getID()] = $count[$n] > 1 || $n === '' ? FormConfiguration::destinationLabel($form, $d) : '«' . $n . '»';
        }
        return $out;
    }

    /** Динамическую организацию не подменяем организацией администратора, открывшего редактор. */
    private static function destinationEntity(Form $form, FormDestination $destination): ?int
    {
        $config = (new EntityField())->getConfig($form, $destination->getConfig());
        return match ($config->getStrategy()) {
            EntityFieldStrategy::FROM_FORM => (int) $form->fields['entities_id'],
            EntityFieldStrategy::SPECIFIC_VALUE => $config->getSpecificEntityId(),
            default => null,
        };
    }

    /**
     * От каких ответов по настройке GLPI зависят создание назначения и видимость вопросов
     * условий запуска — только чтение зависимостей, без оценки правил ядра. enumerate — вопросы-
     * списки и флажки, которые надо перебрать вместе с вопросами условий; exact = false — ядру
     * нужны и другие ответы (текст, даты, пользователи), его ответ по перебору не однозначен.
     *
     * @return array{enumerate:list<string>,exact:bool}
     */
    private static function coreDependencies(Form $form, FormDestination $destination, array $catalog, array $used): array
    {
        $items = [];
        foreach ($form->getQuestions() as $q) { $items[$q->getUUID()] = $q; }
        foreach ($form->getSections() as $s) { $items[$s->getUUID()] = $s; }
        foreach ($form->getFormComments() as $c) { $items[$c->getUUID()] = $c; }
        $queue = [$form];
        if ($destination->getConfiguredCreationStrategy() !== CreationStrategy::ALWAYS_CREATED) { $queue[] = $destination; }
        foreach ($used as $uuid) {
            if (isset($items[$uuid])) { $queue[] = $items[$uuid]; } else { return ['enumerate' => [], 'exact' => false]; }
        }
        $enumerate = []; $exact = true; $seen = [];
        while ($queue) {
            $item = array_shift($queue);
            $key = get_class($item) . ':' . $item->getID();
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            // Вопрос виден, только когда виден его раздел.
            if (method_exists($item, 'getSection')) { $queue[] = $item->getSection(); }
            if (method_exists($item, 'getConfiguredVisibilityStrategy')
                && $item->getConfiguredVisibilityStrategy() === VisibilityStrategy::ALWAYS_VISIBLE) { continue; }
            foreach ($item->getConfiguredConditionsData() as $condition) {
                $uuid = (string) $condition->getItemUuid();
                if ($uuid === '') { continue; }
                if (!isset($items[$uuid])) { $exact = false; continue; }
                if ($condition->getItemType() === Type::QUESTION) {
                    $q = $catalog[$uuid] ?? null;
                    if (!is_array($q) || empty($q['supported']) || !in_array($q['type'] ?? '', ['choice', 'bool'], true)) {
                        $exact = false; continue;
                    }
                    $enumerate[$uuid] = $uuid;
                }
                $queue[] = $items[$uuid];
            }
        }
        return ['enumerate' => array_values(array_diff($enumerate, $used)), 'exact' => $exact];
    }

    /**
     * Создание назначения и видимость — только ядро GLPI, по перебранным ответам-спискам.
     * possible = false — такой отправки не бывает: у скрытого вопроса есть ответ или у видимого
     * обязательного его нет. null — ядро ответа не дало.
     */
    private static function coreVariant(Form $form, FormDestination $destination, array $facts, array $catalog): ?array
    {
        $raw = [];
        foreach ($facts['facts'] as $uuid => $fact) {
            $q = $catalog[$uuid] ?? null;
            if ($q === null) { return null; }
            if ($fact['status'] !== 'known' || !in_array($fact['type'], ['choice', 'bool'], true)) { continue; }
            $raw[(int) $q['id']] = $fact['type'] === 'bool'
                ? ($fact['value'] ? [(string) ($q['options'][0]['key'] ?? '')] : []) : $fact['value'];
        }
        try {
            $engine = new FormEngine($form, new EngineInput($raw));
            $visibility = $engine->computeVisibility();
            if (!$visibility->isFormVisible()) { return ['possible' => false, 'created' => false]; }
            $created = $engine->computeItemsThatMustBeCreated()->itemMustBeCreated($destination);
            $sections = [];
            foreach ($form->getQuestions() as $question) { $sections[$question->getUUID()] = (int) ($question->fields['forms_sections_id'] ?? 0); }
            foreach ($facts['facts'] as $uuid => $fact) {
                $visible = $visibility->isQuestionVisible((int) $catalog[$uuid]['id'])
                    && (!isset($sections[$uuid]) || $visibility->isSectionVisible($sections[$uuid]));
                $noAnswer = ($fact['reason'] ?? '') === 'preview_no_answer';
                if (!$visible && !$noAnswer) { return ['possible' => false, 'created' => false]; }
                if ($visible && $noAnswer && !empty($catalog[$uuid]['required'])) { return ['possible' => false, 'created' => false]; }
            }
            return ['possible' => true, 'created' => $created];
        } catch (\Throwable $e) { TransactionScope::keepGoing($e); return null; }
    }

    /**
     * 1.13.2. Формы, заявки которых правило этого маршрута не запустит: у формы есть другой включённый
     * маршрут с «Запускать, если», видный в тех же организациях — тогда маршрут для её заявок выбирает форма
     * (FormStart::suppressesRuleStart). Своя форма маршрута с «Запускать, если» — в ruleNotes. Только формы
     * маршрутов, которые видит пользователь. Названия — по алфавиту.
     *
     * @return list<string>
     */
    public static function formsChoosingRoute(array $process): array
    {
        global $DB;
        if (($process['itemtype'] ?? 'Ticket') !== 'Ticket') { return []; }
        $own = (int) ($process['id'] ?? 0);
        $ownForm = StartRule::isOn($process) ? (int) ($process['forms_forms_id'] ?? 0) : 0;
        $scope = self::entityScope($process);
        $names = [];
        foreach ($DB->request(['FROM' => Process::getTable(), 'WHERE' => ['itemtype' => 'Ticket', 'outcome_contract' => DefinitionSnapshot::MODE,
            'is_active' => 1, 'forms_forms_id' => ['>', 0]], 'ORDER' => 'id']) as $row) {
            $formId = (int) $row['forms_forms_id'];
            if ((int) $row['id'] === $own || $formId === $ownForm || isset($names[$formId]) || !StartRule::isOn($row)
                || array_intersect($scope, self::entityScope($row)) === []) { continue; }
            $route = new Process();
            $form = new Form();
            if (!$route->can((int) $row['id'], READ) || !$form->getFromDB($formId)) { continue; }
            $names[$formId] = (string) $form->fields['name'];
        }
        $names = array_values($names);
        usort($names, static fn(string $a, string $b): int => strcmp(mb_strtolower($a), mb_strtolower($b)));
        return $names;
    }

    /** «Для заявок с формы «А» правило не сработает: там маршрут выбирает «Запускать, если».»; null — форм нет. */
    public static function rulesSkipFormsLine(array $forms, int $rules): ?string
    {
        $forms = array_values(array_unique(array_map('strval', $forms)));
        $n = count($forms);
        if ($n === 0 || $rules <= 0) { return null; }
        $shown = implode(', ', array_map(static fn(string $f): string => '«' . $f . '»', array_slice($forms, 0, 2)));
        return 'Для заявок ' . ($n === 1 ? 'с формы ' : 'с форм ') . $shown . ($n > 2 ? ' и ещё ' . ($n - 2) : '')
            . ($rules === 1 ? ' правило не сработает' : ' правила не сработают') . ': там маршрут выбирает «Запускать, если».';
    }

    private static function entityScope(array $process): array
    {
        $entity = (int) ($process['entities_id'] ?? 0);
        return array_values(array_unique(array_merge([$entity], !empty($process['is_recursive'])
            ? array_map('intval', getSonsOf('glpi_entities', $entity)) : [])));
    }

    /**
     * Правила этого маршрута (действие «Запустить маршрут этапов» с этим маршрутом), которые
     * для заявок формы не сработают, — одной строкой. Правила других маршрутов — в их готовности.
     */
    private static function ruleNotes(int $process, array $entities): array
    {
        global $DB;
        if ($process <= 0) { return []; }
        $names = [];
        $rows = $DB->request(['SELECT' => ['r.id', 'r.name', 'r.entities_id', 'r.is_recursive', 'r.condition'],
            'FROM' => 'glpi_rules AS r', 'INNER JOIN' => ['glpi_ruleactions AS a' => ['ON' => ['a' => 'rules_id', 'r' => 'id']]],
            'WHERE' => ['r.sub_type' => 'RuleTicket', 'r.is_active' => 1, 'a.field' => '_plugin_itilflow_processes_id',
                'a.action_type' => 'assign', 'a.value' => (string) $process], 'ORDER' => ['r.name', 'r.id']]);
        foreach ($rows as $rule) {
            if (((int) $rule['condition'] & \RuleTicket::ONADD) === 0) { continue; }
            foreach ($entities as $entity) {
                if (self::fitsEntity($rule, $entity, array_map('intval', getAncestorsOf('glpi_entities', $entity)))) {
                    $names[(int) $rule['id']] = (string) $rule['name'];
                    break;
                }
            }
        }
        $line = self::rulesLine(array_values($names));
        return $line === null ? [] : [$line];
    }

    /** «Правила «А», «Б» не запускают маршрут для заявок этой формы — его запускает форма.»; больше двух — «и ещё N». */
    public static function rulesLine(array $names): ?string
    {
        $names = array_values(array_unique(array_map('strval', $names)));
        $n = count($names);
        if ($n === 0) { return null; }
        $shown = array_map(static fn(string $name): string => '«' . $name . '»', array_slice($names, 0, 2));
        return ($n === 1 ? 'Правило ' : 'Правила ') . implode(', ', $shown)
            . ($n > 2 ? ' и ещё ' . ($n - 2) : '')
            . ($n === 1 ? ' не запускает' : ' не запускают') . ' маршрут для заявок этой формы — его запускает форма.';
    }

    /**
     * Число заявок этой формы без маршрута за 30 дней — по записям журнала FormStart.
     * Считаются заявки, а не записи и повторы; заявка, у которой маршрут потом всё же есть, — нет.
     * last — самая поздняя запись: номер заявки и текст (записи без маршрута на заявке не видны).
     *
     * @return array{total:int,last:?array{ticket:int,message:string}}
     */
    private static function recentUnrouted(int $form, array $entities): array
    {
        global $DB;
        $entities = array_values(array_unique(array_map('intval', $entities)));
        if ($form <= 0 || $entities === []) { return ['total' => 0, 'last' => null]; }
        $since = (new \DateTimeImmutable($_SESSION['glpi_currenttime'] ?? 'now'))->modify('-30 days')->format('Y-m-d H:i:s');
        // В GLPI 11 прямой SQL ($DB->query) запрещён: только построитель запросов.
        $criteria = [
            'FROM' => Violation::getTable() . ' AS v',
            'INNER JOIN' => [
                AnswersSet_FormDestinationItem::getTable() . ' AS l' => ['ON' => ['l' => 'items_id', 'v' => 'items_id',
                    ['AND' => ['l.itemtype' => 'Ticket']]]],
                AnswersSet::getTable() . ' AS a' => ['ON' => ['a' => 'id', 'l' => 'forms_answerssets_id']],
                // Заявки в корзине не считаются — как в статистике GLPI и в отчёте маршрутов.
                \Ticket::getTable() . ' AS t' => ['ON' => ['t' => 'id', 'v' => 'items_id']],
            ],
            'LEFT JOIN' => [
                Instance::getTable() . ' AS i' => ['ON' => ['i' => 'items_id', 'v' => 'items_id',
                    ['AND' => ['i.itemtype' => 'Ticket']]]],
            ],
            'WHERE' => ['v.action' => 'action_form_start', 'v.itemtype' => 'Ticket', 'i.id' => null, 't.is_deleted' => 0,
                'a.forms_forms_id' => $form, 'v.entities_id' => $entities, 'v.date' => ['>=', $since]],
        ];
        $row = $DB->request(['SELECT' => ['COUNT DISTINCT' => 'v.items_id AS total']] + $criteria)->current();
        $total = (int) ($row['total'] ?? 0);
        if ($total === 0) { return ['total' => 0, 'last' => null]; }
        $last = $DB->request(['SELECT' => ['v.items_id', 'v.message'], 'ORDER' => ['v.date DESC', 'v.id DESC'], 'LIMIT' => 1] + $criteria)->current();
        return ['total' => $total, 'last' => is_array($last) && (int) ($last['items_id'] ?? 0) > 0
            ? ['ticket' => (int) $last['items_id'], 'message' => (string) ($last['message'] ?? '')] : null];
    }
}
