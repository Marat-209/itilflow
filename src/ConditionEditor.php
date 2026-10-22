<?php
namespace GlpiPlugin\Itilflow;

/**
 * Один редактор условий для «Выполнять, если» этапа
 * и «Запускать, если» маршрута. Шаблон — templates/condition_editor.html.twig.
 *
 * Режимы: SCOPE_STAGE — «Всегда / Только если выполнены условия»; SCOPE_START — ещё и
 * «Не запускать по форме» (маршрут запускает бизнес-правило, как раньше).
 * Что проверяем — группами:
 * - SCOPE_STAGE: «Вопросы этапов выше», «Ответы формы · ‹раздел›», «Этап не нужен по условию»,
 *   «Поле заявки» — только «Категория заявки» (тип заявки убран);
 * - SCOPE_START: только «Ответы формы · ‹раздел›» формы маршрута.
 * Сравнения — по виду вопроса, одинаковые для обоих мест.
 * До трёх условий, «+ Добавить условие», пустых строк заранее нет, под каждым условием —
 * фраза (ConditionText::phrase). Номеров человек не вводит и не видит.
 *
 * Результат parse — правило в формате Condition (version/join/clauses) — то, что пишет
 * stages.run_if и что StartRule кладёт в start_if.rule. Другие части передают сюда
 * $_POST[$prefix] целиком.
 *
 * Поля ввода (имена без двоеточий и других знаков, которые меняет браузер или GLPI):
 *   prefix[mode]                    off | always | if
 *   prefix[join]                    all | any
 *   prefix[clauses][N][what]        что проверяем: decision:ЭТАП:КЛЮЧ, skip:ЭТАП, form:UUID, ticket:category_id
 *   prefix[clauses][N][op]          сравнение — код из operators()
 *   prefix[clauses][N][values][]    ключи вариантов (список)
 *   prefix[clauses][N][items][]     номера пользователей, групп, категорий (выбор из списка GLPI)
 *   prefix[clauses][N][days]        N дней для даты
 *   prefix[clauses][N][number]      число
 *   prefix[clauses][N][keep]        прежнее условие по типу заявки — номер в сохранённом правиле
 * Каталог вопросов формы можно передать готовым: $process['form_catalog'] (uuid => вопрос
 * с section и type_label, как FormFacts::questionCatalog(…, forEditor: true)).
 */
final class ConditionEditor
{
    public const SCOPE_STAGE = 'stage';
    public const SCOPE_START = 'start';
    public const MODE_OFF = 'off';
    public const MODE_ALWAYS = 'always';
    public const MODE_IF = 'if';
    public const MAX_CLAUSES = 3;

    /** Подписи сравнений модели, которых нет в списке вида (условия из файла переноса). */
    private const OP_LABELS = ['eq' => 'равно', 'ne' => 'не равно', 'in' => 'любой из', 'not_in' => 'ни один из',
        'lt' => 'меньше', 'lte' => 'не больше', 'gt' => 'больше', 'gte' => 'не меньше', 'unknown' => 'неизвестно',
        'filled' => 'заполнено', 'empty' => 'не заполнено'];
    /** Прежние сравнения, которые редактор сохраняет, если их не меняли. */
    private const LEGACY_OPS = ['user' => ['ne'], 'group' => ['ne'], 'object' => ['ne'], 'number' => ['unknown']];

    /**
     * Сравнения по виду: вид => list<array{0:string,1:string}> (код сравнения, подпись).
     * Виды: choice, flag, user, group, object (категория — вопрос формы), text, file, date,
     * number, bool (вопрос этапа), skip, category (поле заявки).
     */
    public static function operators(): array
    {
        $objects = [['in', 'любой из'], ['filled', 'заполнено'], ['empty', 'не заполнено'], ['unknown', 'неизвестно']];
        return [
            'choice' => [['in', 'любой из'], ['not_in', 'ни один из'], ['unknown', 'неизвестно']],
            'flag' => [['checked', 'отмечен'], ['unchecked', 'не отмечен'], ['unknown', 'неизвестно']],
            'user' => $objects,
            'group' => $objects,
            'object' => [['in', 'любая из'], ['filled', 'заполнено'], ['empty', 'не заполнено'], ['unknown', 'неизвестно']],
            'text' => [['filled', 'заполнено'], ['empty', 'не заполнено'], ['unknown', 'неизвестно']],
            'file' => [['filled', 'приложен'], ['empty', 'не приложен'], ['unknown', 'неизвестно']],
            'date' => [['before_days', 'раньше, чем через'], ['after_days', 'позже, чем через'],
                ['filled', 'заполнено'], ['empty', 'не заполнено'], ['unknown', 'неизвестно']],
            'number' => [['eq', 'равно'], ['ne', 'не равно'], ['lt', 'меньше'], ['lte', 'не больше'], ['gt', 'больше'], ['gte', 'не меньше']],
            'bool' => [['yes', 'да'], ['no', 'нет'], ['unknown', 'неизвестно']],
            'skip' => [['skipped', 'не нужен по условию'], ['ran', 'выполнялся']],
            'category' => [['in', 'любая из'], ['not_in', 'ни одна из']],
        ];
    }

    /** Вид вопроса формы в редакторе по снимку вопроса; '' — в условиях не используется. */
    public static function kindOfQuestion(array $q): string
    {
        return match ($q['type'] ?? '') {
            'choice' => 'choice', 'bool' => 'flag', 'text' => 'text', 'file' => 'file', 'date' => 'date', 'number' => 'number',
            'ref' => match ($q['itemtype'] ?? '') { 'User' => 'user', 'Group' => 'group', 'ITILCategory' => 'object', default => '' },
            default => '',
        };
    }

    /** Справочник GLPI для значения вида: пользователь, группа, категория. */
    public static function itemtypeOfKind(string $kind): ?string
    {
        return ['user' => 'User', 'group' => 'Group', 'object' => 'ITILCategory', 'category' => 'ITILCategory'][$kind] ?? null;
    }

    /** Вопросы формы маршрута: готовый каталог из $process или чтение формы. */
    public static function catalog(array $process): array
    {
        if (is_array($process['form_catalog'] ?? null)) { return $process['form_catalog']; }
        $form = (int) ($process['forms_forms_id'] ?? 0);
        return $form > 0 ? FormFacts::questionCatalog($form, true, true) : [];
    }

    /** Форма у маршрута выбрана. */
    public static function hasForm(array $process): bool
    {
        return is_array($process['form_catalog'] ?? null) || (int) ($process['forms_forms_id'] ?? 0) > 0;
    }

    /**
     * Группы «Что проверяем» для выпадающего списка.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string,kind:string,disabled?:bool}>}>
     */
    public static function sources(string $scope, array $process, ?array $stage = null, array $stages = []): array
    {
        return self::build($scope, $process, $stage, $stages)['groups'];
    }

    /**
     * Разбор отправленного редактора.
     *
     * @return array{mode:string,rule:array,errors:list<string>}
     *   mode — MODE_OFF (только SCOPE_START), MODE_ALWAYS или MODE_IF; rule — [] при MODE_OFF и
     *   MODE_ALWAYS; errors — тексты для администратора маршрутов, [] — можно сохранить.
     */
    public static function parse(array $input, string $scope, array $process, ?array $stage = null, array $stages = []): array
    {
        $result = self::parseRows($input, $scope, $process, $stage, $stages);
        return ['mode' => $result['mode'], 'rule' => $result['rule'], 'errors' => $result['errors']];
    }

    /**
     * Разбор с итогом по каждому условию — для пересчёта без сохранения (front/draft.php):
     * rows — list<array{index:int, clause:?array, error:?string}> в порядке полей формы.
     */
    public static function parseRows(array $input, string $scope, array $process, ?array $stage = null, array $stages = []): array
    {
        $modes = self::modeCodes($scope);
        $mode = $input['mode'] ?? $modes[0];
        if (!is_string($mode) || !in_array($mode, $modes, true)) {
            return ['mode' => $modes[0], 'join' => 'all', 'rule' => [], 'rows' => [],
                'errors' => [$scope === self::SCOPE_START ? 'Выберите, когда запускать маршрут.' : 'Выберите, когда выполнять этап.']];
        }
        $join = in_array($input['join'] ?? 'all', ['all', 'any'], true) ? $input['join'] ?? 'all' : 'all';
        if ($mode !== self::MODE_IF) { return ['mode' => $mode, 'join' => $join, 'rule' => [], 'rows' => [], 'errors' => []]; }
        $posted = $input['clauses'] ?? [];
        if (!is_array($posted)) { $posted = []; }
        // Порядок — как отправил браузер, то есть как условия стоят на странице: новое условие
        // встаёт вниз, даже если его блок с меньшим номером (редактор переставляет блоки).
        $built = self::build($scope, $process, $stage, $stages);
        $old = $scope === self::SCOPE_STAGE ? (BranchConfiguration::decode($stage['run_if'] ?? null) ?? []) : [];
        $rows = []; $errors = []; $clauses = [];
        $n = 0;
        foreach ($posted as $index => $row) {
            if (!is_array($row)) { continue; }
            $n++;
            [$clause, $error] = self::row($row, $built['map'], $old);
            if (count($rows) >= self::MAX_CLAUSES) { $error = 'больше трёх условий нельзя.'; $clause = null; }
            $rows[] = ['index' => (int) $index, 'clause' => $clause, 'error' => $error];
            if ($error !== null) { $errors[] = 'Условие ' . $n . ': ' . $error; } else { $clauses[] = $clause; }
        }
        if ($rows === []) {
            $errors[] = 'Выбрано «Только если», но условий нет: добавьте условие или выберите «Всегда».';
        }
        if ($errors) { return ['mode' => $mode, 'join' => $join, 'rule' => [], 'rows' => $rows, 'errors' => $errors]; }
        $rule = ['version' => 1, 'join' => $join, 'clauses' => $clauses];
        $errors = Condition::validate($rule, $built['prior']);
        return ['mode' => $mode, 'join' => $join, 'rule' => $errors ? [] : $rule, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * Данные для include('@itilflow/condition_editor.html.twig', view(...)).
     * Ключи: scope, prefix, mode, modes, join, clauses, sources, operators, summary, hint, can_add;
     * ещё title, max, notes, choices (варианты вопросов-списков по «что проверяем»), has_form.
     */
    public static function view(string $scope, string $prefix, string $mode, array $rule, array $process,
        ?array $stage = null, array $stages = []): array
    {
        $built = self::build($scope, $process, $stage, $stages);
        if (!in_array($mode, self::modeCodes($scope), true)) { $mode = self::modeCodes($scope)[0]; }
        $operators = self::operators();
        $join = ($rule['join'] ?? 'all') === 'any' ? 'any' : 'all';
        $clauses = [];
        $list = is_array($rule['clauses'] ?? null) ? array_values($rule['clauses']) : [];
        foreach (array_slice($list, 0, self::MAX_CLAUSES) as $i => $c) {
            if (!is_array($c)) { continue; }
            $clauses[] = self::rowView($i, $c, $built['map'], $operators, $scope, $join, $stages);
        }
        $hasForm = self::hasForm($process);
        $notes = [];
        if ($scope === self::SCOPE_STAGE) {
            $notes[] = 'Условие проверяется, когда маршрут доходит до этапа.';
            if (!$hasForm) { $notes[] = 'Чтобы условия видели ответы формы, выберите форму маршрута.'; }
        } elseif (!$hasForm) {
            $notes[] = 'Чтобы запускать маршрут по ответу формы, выберите форму маршрута.';
        }
        return [
            'scope' => $scope, 'prefix' => $prefix, 'mode' => $mode, 'modes' => self::modes($scope),
            'join' => $join, 'clauses' => $clauses, 'sources' => $built['groups'], 'operators' => $operators,
            'summary' => self::summary($scope, $mode, $rule, $stages),
            'hint' => implode(' ', $notes), 'notes' => $notes,
            'can_add' => count($clauses) < self::MAX_CLAUSES, 'max' => self::MAX_CLAUSES,
            'title' => $scope === self::SCOPE_START ? 'Запускать, если' : 'Когда выполнять',
            'choices' => $built['choices'], 'has_form' => $hasForm,
        ];
    }

    /** Кратко в заголовке редактора: «всегда», «если …». */
    public static function summary(string $scope, string $mode, array $rule, array $stages = []): string
    {
        if ($mode === self::MODE_OFF) { return 'не запускается по форме'; }
        if ($mode !== self::MODE_IF || $rule === []) {
            return $scope === self::SCOPE_START ? 'для каждой заявки с формы' : 'всегда';
        }
        return ConditionText::short($rule, $stages);
    }

    /** Фразы под условиями по порядку: первая — «Этап выполняется, если …», дальше «…и» / «…или». */
    public static function phrases(array $clauses, string $scope, string $join, array $stages = []): array
    {
        $out = []; $first = true;
        foreach ($clauses as $c) {
            if (!is_array($c)) { $out[] = ''; continue; }
            if ($first) { $out[] = ConditionText::phrase($c, $scope, $stages); $first = false; continue; }
            $detail = ConditionText::detail($c, $stages);
            $out[] = $detail === null ? '' : ($join === 'any' ? '…или ' : '…и ') . $detail . '.';
        }
        return $out;
    }

    private static function modeCodes(string $scope): array
    {
        return $scope === self::SCOPE_START ? [self::MODE_OFF, self::MODE_ALWAYS, self::MODE_IF] : [self::MODE_ALWAYS, self::MODE_IF];
    }

    /** @return list<array{value:string,label:string,hint:string}> */
    private static function modes(string $scope): array
    {
        if ($scope === self::SCOPE_START) {
            return [
                ['value' => self::MODE_OFF, 'label' => 'Не запускать по форме', 'hint' => 'Запускает бизнес-правило.'],
                ['value' => self::MODE_ALWAYS, 'label' => 'Всегда', 'hint' => 'Для каждой заявки с этой формы.'],
                ['value' => self::MODE_IF, 'label' => 'Только если выполнены условия', 'hint' => 'По ответам формы.'],
            ];
        }
        return [
            ['value' => self::MODE_ALWAYS, 'label' => 'Всегда', 'hint' => 'Этап выполняется в каждой заявке маршрута.'],
            ['value' => self::MODE_IF, 'label' => 'Только если выполнены условия', 'hint' => 'Иначе — «Не нужен по условию».'],
        ];
    }

    /** Источники редактора: группы для списка, карта «что проверяем» => условие, этапы выше. */
    private static function build(string $scope, array $process, ?array $stage, array $stages): array
    {
        $map = []; $choices = []; $formGroups = [];
        if (!self::hasForm($process)) {
            $formGroups[] = ['label' => 'Ответы формы', 'options' => [self::placeholder('— сначала выберите форму маршрута —')]];
        } else {
            $bySection = [];
            foreach (self::catalog($process) as $uuid => $q) {
                if (!is_array($q) || empty($q['supported']) || !is_string($uuid) || $uuid === '') { continue; }
                $kind = self::kindOfQuestion($q);
                if ($kind === '') { continue; }
                $token = 'form:' . $uuid;
                $label = (string) ($q['label'] ?? '') . ' (' . (string) ($q['type_label'] ?? $kind) . ')';
                $bySection[(string) ($q['section'] ?? 'Раздел')][] = ['value' => $token, 'label' => $label, 'kind' => $kind];
                $question = $q; unset($question['section'], $question['type_label']);
                $map[$token] = ['kind' => $kind, 'label' => $label,
                    'clause' => ['source' => 'form_answer', 'question_uuid' => $uuid, 'question' => $question]];
                if ($kind === 'choice') {
                    $choices[$token] = array_values(array_filter(array_map(
                        static fn($o): ?array => is_array($o) && isset($o['key'], $o['label']) ? ['key' => (string) $o['key'], 'label' => (string) $o['label']] : null,
                        is_array($q['options'] ?? null) ? $q['options'] : [])));
                }
            }
            foreach ($bySection as $section => $options) { $formGroups[] = ['label' => 'Ответы формы · ' . $section, 'options' => $options]; }
            if ($bySection === []) {
                $formGroups[] = ['label' => 'Ответы формы', 'options' => [self::placeholder('— в форме нет вопросов, которые можно проверить —')]];
            }
        }
        if ($scope === self::SCOPE_START) { return ['groups' => $formGroups, 'map' => $map, 'choices' => $choices, 'prior' => []]; }

        $own = (int) ($stage['id'] ?? 0);
        $rank = (int) ($stage['ranking'] ?? 0) > 0 ? (int) $stage['ranking'] : PHP_INT_MAX;
        $above = array_values(array_filter($stages, static fn($s): bool => is_array($s)
            && (int) ($s['id'] ?? 0) !== $own && (int) ($s['ranking'] ?? 0) < $rank));
        usort($above, static fn(array $a, array $b): int => [(int) $a['ranking'], (int) $a['id']] <=> [(int) $b['ranking'], (int) $b['id']]);
        $questions = ['label' => 'Вопросы этапов выше', 'options' => []];
        $skips = ['label' => 'Этап не нужен по условию', 'options' => []];
        foreach ($above as $s) {
            $id = (int) $s['id'];
            $title = (int) $s['ranking'] . '. ' . (string) ($s['name'] ?? '');
            foreach (BranchConfiguration::decisionItems($s['decision_items'] ?? null) ?? [] as $item) {
                $token = 'decision:' . $id . ':' . $item['key'];
                $label = $title . ' — «' . (trim($item['label']) !== '' ? $item['label'] : 'вопрос без текста') . '»';
                $questions['options'][] = ['value' => $token, 'label' => $label, 'kind' => 'bool'];
                $map[$token] = ['kind' => 'bool', 'label' => $label, 'clause' => ['source' => 'decision', 'stage_id' => $id, 'key' => $item['key']]];
            }
            $token = 'skip:' . $id;
            $map[$token] = ['kind' => 'skip', 'label' => $title . ' — не нужен по условию', 'clause' => ['source' => 'stage_not_needed', 'stage_id' => $id]];
            if ((BranchConfiguration::decode($s['run_if'] ?? null) ?? [])['clauses'] ?? []) {
                $skips['options'][] = ['value' => $token, 'label' => $map[$token]['label'], 'kind' => 'skip'];
            }
        }
        if ($questions['options'] === []) { $questions['options'][] = self::placeholder('— у этапов выше нет вопросов —'); }
        if ($skips['options'] === []) { $skips['options'][] = self::placeholder('— выше нет этапов с условием —'); }
        $groups = array_merge([$questions], $formGroups, [$skips]);
        if (($process['itemtype'] ?? 'Ticket') === 'Ticket') {
            $groups[] = ['label' => 'Поле заявки', 'options' => [['value' => 'ticket:category_id', 'label' => 'Категория заявки', 'kind' => 'category']]];
            $map['ticket:category_id'] = ['kind' => 'category', 'label' => 'Категория заявки', 'clause' => ['source' => 'ticket', 'field' => 'category_id']];
        }
        return ['groups' => $groups, 'map' => $map, 'choices' => $choices, 'prior' => array_map(static fn(array $s): int => (int) $s['id'], $above)];
    }

    private static function placeholder(string $text): array
    {
        return ['value' => '', 'label' => $text, 'kind' => '', 'disabled' => true];
    }

    /** «Что проверяем» сохранённого условия. */
    public static function token(array $c): string
    {
        return match ($c['source'] ?? '') {
            'decision' => 'decision:' . (int) ($c['stage_id'] ?? 0) . ':' . (string) ($c['key'] ?? ''),
            'stage_not_needed' => 'skip:' . (int) ($c['stage_id'] ?? 0),
            'form_answer' => 'form:' . (string) ($c['question_uuid'] ?? ''),
            'ticket' => 'ticket:' . (string) ($c['field'] ?? ''),
            default => '',
        };
    }

    /** Одна строка ввода → [условие, null] или [null, причина словами]. */
    private static function row(array $row, array $map, array $old): array
    {
        $what = is_string($row['what'] ?? null) ? $row['what'] : '';
        if ($what === '') { return [null, 'выберите, что проверяем.']; }
        if ($what === 'ticket:type') {
            // Тип заявки в новых условиях не сохраняется; прежнее условие остаётся как было.
            $keep = filter_var($row['keep'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $prior = $keep === false ? null : ($old['clauses'][$keep] ?? null);
            if (is_array($prior) && self::token($prior) === 'ticket:type') { return [$prior, null]; }
            return [null, 'условия по типу заявки больше не задаются — выберите категорию заявки или уберите условие.'];
        }
        $source = $map[$what] ?? null;
        if ($source === null) { return [null, 'вопрос или этап удалён или стоит ниже — выберите заново.']; }
        $kind = $source['kind'];
        $op = is_string($row['op'] ?? null) ? $row['op'] : '';
        if (!in_array($op, array_merge(array_column(self::operators()[$kind] ?? [], 0), self::LEGACY_OPS[$kind] ?? []), true)) {
            return [null, 'выберите сравнение.'];
        }
        $clause = $source['clause'];
        $value = null; $operator = $op;
        switch ($kind) {
            case 'choice':
                if ($op !== 'unknown') {
                    $keys = array_column(is_array($clause['question']['options'] ?? null) ? $clause['question']['options'] : [], 'key');
                    $value = [];
                    foreach (is_array($row['values'] ?? null) ? $row['values'] : [] as $v) {
                        if (!is_scalar($v) || (string) $v === '') { continue; }
                        if (!in_array((string) $v, $keys, true)) { return [null, 'такого варианта ответа в вопросе больше нет — выберите заново.']; }
                        if (!in_array((string) $v, $value, true)) { $value[] = (string) $v; }
                    }
                    if ($value === []) { return [null, 'выберите хотя бы один вариант.']; }
                }
                break;
            case 'flag': case 'bool': case 'skip':
                if ($op !== 'unknown') {
                    $operator = 'eq';
                    $value = in_array($op, ['checked', 'yes', 'skipped'], true);
                }
                break;
            case 'user': case 'group': case 'object': case 'category':
                if (in_array($op, ['in', 'not_in', 'ne'], true)) {
                    $ids = self::ids($row['items'] ?? null);
                    if ($ids === null) { return [null, 'выберите значение из списка.']; }
                    if ($ids === []) { return [null, $kind === 'category' || $kind === 'object' ? 'выберите хотя бы одну категорию.' : 'выберите хотя бы одно значение.']; }
                    if (count($ids) > 50) { return [null, 'не больше 50 значений.']; }
                    if ($kind === 'category') { $value = $ids; break; }
                    $itemtype = self::itemtypeOfKind($kind);
                    if ($op === 'ne') {
                        if (count($ids) !== 1) { return [null, 'для «не равно» выберите одно значение.']; }
                        $value = ['itemtype' => $itemtype, 'id' => $ids[0]];
                    } else {
                        $value = array_map(static fn(int $id): array => ['itemtype' => $itemtype, 'id' => $id], $ids);
                    }
                }
                break;
            case 'date':
                if ($op === 'before_days' || $op === 'after_days') {
                    $days = filter_var(is_string($row['days'] ?? null) ? trim($row['days']) : ($row['days'] ?? null), FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => Condition::MAX_DAYS]]);
                    if ($days === false) { return [null, 'укажите число дней — целое, от 1 до ' . Condition::MAX_DAYS . '.']; }
                    $value = $days;
                }
                break;
            case 'number':
                if ($op === 'unknown') { break; }
                $value = FormFacts::decimal(str_replace(',', '.', trim(is_scalar($row['number'] ?? null) ? (string) $row['number'] : '')));
                if ($value === null) { return [null, 'укажите число, например 3 или 1,5.']; }
                break;
        }
        $clause['operator'] = $operator;
        $clause['value'] = $value;
        return [$clause, null];
    }

    /** Номера из списка GLPI; пустые значения пропускаются; null — неверный ввод. */
    private static function ids(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') { return []; }
        if (!is_array($raw)) { $raw = [$raw]; }
        $out = [];
        foreach ($raw as $v) {
            if ($v === '' || $v === '0' || $v === 0) { continue; }
            if (!is_scalar($v) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $v)) { return null; }
            $out[(int) $v] = (int) $v;
        }
        return array_values($out);
    }

    /** Строка редактора по сохранённому условию: что проверяем, сравнение, значения, фраза. */
    private static function rowView(int $i, array $c, array $map, array $operators, string $scope, string $join, array $stages): array
    {
        $token = self::token($c);
        $source = $map[$token] ?? null;
        $kind = $source['kind'] ?? self::kindOf($c);
        $op = (string) ($c['operator'] ?? '');
        $value = $c['value'] ?? null;
        $ops = $operators[$kind] ?? [];
        $editorOp = $op;
        $values = []; $items = []; $days = ''; $number = '';
        if (in_array($kind, ['flag', 'bool', 'skip'], true) && $op !== 'unknown') {
            $yes = $op === 'eq' ? $value === true : ($op === 'ne' ? $value === false : null);
            $codes = ['flag' => ['checked', 'unchecked'], 'bool' => ['yes', 'no'], 'skip' => ['skipped', 'ran']][$kind];
            $editorOp = $yes === null ? '' : ($yes ? $codes[0] : $codes[1]);
        } elseif ($kind === 'category' || $token === 'ticket:type') {
            $editorOp = ['eq' => 'in', 'ne' => 'not_in'][$op] ?? $op;
            $items = array_values(array_filter((array) $value, 'is_int'));
        } elseif (in_array($kind, ['user', 'group', 'object'], true)) {
            if ($op === 'eq') { $editorOp = 'in'; }
            if ($op === 'ne') { $ops[] = ['ne', 'не равно']; }
            $list = $op === 'in' ? (is_array($value) && array_is_list($value) ? $value : []) : (is_array($value) ? [$value] : []);
            foreach ($list as $ref) { if (is_array($ref) && is_int($ref['id'] ?? null)) { $items[] = $ref['id']; } }
        } elseif ($kind === 'choice') {
            $values = array_values(array_filter((array) $value, 'is_string'));
        } elseif ($kind === 'date') {
            $days = is_int($value) ? (string) $value : '';
        } elseif ($kind === 'number') {
            $number = is_string($value) ? str_replace('.', ',', $value) : '';
        }
        if ($editorOp !== '' && !in_array($editorOp, array_column($ops, 0), true)) {
            // Сравнение, которого в редакторе нет (условие из файла переноса), остаётся выбранным.
            $ops[] = [$editorOp, self::OP_LABELS[$editorOp] ?? 'как было'];
        }
        $phrase = $i === 0 ? ConditionText::phrase($c, $scope, $stages)
            : ((($d = ConditionText::detail($c, $stages)) !== null) ? ($join === 'any' ? '…или ' : '…и ') . $d . '.' : '');
        $unavailable = null; $keep = null;
        if ($source === null) {
            $brief = ConditionText::brief($c, $stages) ?? 'условие';
            if ($token === 'ticket:type') { $unavailable = $brief . ' (прежнее условие)'; $keep = $i; }
            else { $unavailable = $brief . ' — вопрос или этап удалён, выберите заново'; }
        }
        return ['index' => $i, 'what' => $token, 'kind' => $kind, 'op' => $editorOp, 'ops' => $ops,
            'values' => $values, 'items' => $items, 'itemtype' => self::itemtypeOfKind($kind),
            'days' => $days, 'number' => $number, 'phrase' => $phrase, 'unavailable' => $unavailable, 'keep' => $keep];
    }

    /** Вид по самому условию, когда источника уже нет в списке. */
    private static function kindOf(array $c): string
    {
        return match ($c['source'] ?? '') {
            'decision' => 'bool', 'stage_not_needed' => 'skip',
            'form_answer' => self::kindOfQuestion(is_array($c['question'] ?? null) ? $c['question'] : []),
            'ticket' => ($c['field'] ?? '') === 'category_id' ? 'category' : 'ticket_type',
            default => '',
        };
    }
}
