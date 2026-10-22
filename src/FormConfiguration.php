<?php
namespace GlpiPlugin\Itilflow;

use Glpi\Form\Form;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Destination\CommonITILField\ValidationField;
use Glpi\Form\Destination\CommonITILField\ValidationFieldStrategy;
use Glpi\Form\Condition\CreationStrategy;
use Glpi\Form\Condition\EditorManager;
use Glpi\Form\Condition\FormData;

/** Только настройка и диагностика. Доказательства происхождения создаёт серверный callback. */
final class FormConfiguration
{
    public static function pair(array $process): ?array
    {
        $form = new Form(); $destination = new FormDestination();
        if (!$form->getFromDB((int) ($process['forms_forms_id'] ?? 0))
            || !$destination->getFromDB((int) ($process['forms_destinations_id'] ?? 0))
            || (int) $destination->fields['forms_forms_id'] !== (int) $form->getID()
            || ($destination->fields['itemtype'] ?? '') !== FormDestinationTicket::class) { return null; }
        return [$form, $destination];
    }

    /** Ноль оставляет незавершённый черновик; чужая пара не сохраняется. */
    public static function inputError(array $p, bool $checkAccess = true): ?string
    {
        foreach (['forms_forms_id', 'forms_destinations_id'] as $key) {
            if (filter_var($p[$key] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                return 'Выберите форму и назначение заново.';
            }
        }
        if ((int) ($p['forms_forms_id'] ?? 0) === 0 && (int) ($p['forms_destinations_id'] ?? 0) === 0) { return null; }
        if (($p['itemtype'] ?? '') !== 'Ticket' || ($p['outcome_contract'] ?? '') !== DefinitionSnapshot::MODE) {
            return 'Форму можно связать только с маршрутом заявок нового порядка.';
        }
        $form = new Form();
        if (!$form->getFromDB((int) ($p['forms_forms_id'] ?? 0))
            || ($checkAccess && !$form->can((int) $form->getID(), READ))) { return 'Выбранная форма недоступна.'; }
        if ((int) ($p['forms_destinations_id'] ?? 0) > 0 && self::pair($p) === null) {
            return 'Назначение должно создавать заявку и принадлежать выбранной форме.';
        }
        return null;
    }

    public static function formOptions(): array
    {
        global $DB;
        $out = [0 => 'Не выбрана'];
        foreach ($DB->request(['FROM' => Form::getTable(), 'ORDER' => 'name']) as $row) {
            $form = new Form();
            if ($form->can((int) $row['id'], READ)) { $out[(int) $row['id']] = (string) $row['name']; }
        }
        return $out;
    }

    /** Нулевой пункт «Назначение формы»: при запуске по форме — любое назначение, иначе — не выбрано. */
    public static function destinationZeroLabel(array $p): string
    {
        return StartRule::isOn($p) ? 'Любое назначение формы, создающее заявку' : 'Не выбрано';
    }

    public static function destinationOptions(array $p): array
    {
        global $DB;
        $out = [0 => self::destinationZeroLabel($p)]; $form = new Form();
        if (!$form->can((int) ($p['forms_forms_id'] ?? 0), READ)) { return $out; }
        foreach ($DB->request(['FROM' => FormDestination::getTable(), 'WHERE' => [
            'forms_forms_id' => (int) $form->getID(), 'itemtype' => FormDestinationTicket::class], 'ORDER' => 'id']) as $row) {
            $destination = new FormDestination(); $destination->fields = $row;
            $out[(int) $row['id']] = self::destinationLabel($form, $destination);
        }
        return $out;
    }

    /** Только описание сохранённой настройки. Выполнение условий определяет исключительно движок GLPI. */
    public static function destinationLabel(Form $form, FormDestination $destination): string
    {
        $name = trim((string) ($destination->fields['name'] ?? ''));
        $label = $name !== '' ? '«' . $name . '»' : 'Назначение без названия';
        try {
            $editor = new EditorManager(); $editor->setFormData(FormData::createFromForm($form));
            return $label . ' — ' . self::creationText($destination->getConfiguredCreationStrategy(),
                $destination->getConfiguredConditionsData(), $editor);
        } catch (\Throwable) {
            TransactionScope::assertHealthy();
            return $label . ' — откройте форму и проверьте условия создания';
        }
    }

    /** Подписи, операторы и варианты берём у редактора ядра; здесь нет оценки истинности. */
    public static function creationText(CreationStrategy $strategy, array $conditions, EditorManager $editor, ?\Closure $name = null): string
    {
        if ($strategy === CreationStrategy::ALWAYS_CREATED) { return 'создаётся всегда'; }
        $labels = [];
        foreach ($editor->getItemsDropdownValues() as $group) { $labels += $group; }
        $parts = [];
        foreach ($conditions as $condition) {
            $label = $labels[$condition->getItemDropdownKey()] ?? null;
            $operator = $condition->getValueOperator();
            if ($label === null || $operator === null) { return 'откройте форму и проверьте условия создания'; }
            $parameters = $editor->getHandlerForCondition($condition)->getTemplateParameters($condition);
            $value = self::conditionValue($condition->getValue(), $parameters, $name);
            $parts[] = ($parts ? $condition->getLogicOperator()->getLabel() . ' ' : '')
                . '«' . $label . '» ' . $operator->getLabel() . ($value !== '' ? ' «' . $value . '»' : '');
        }
        // Подпись стратегии ядра («Created if...») в русском GLPI 11.0.8 не переведена.
        $lead = $strategy === CreationStrategy::CREATED_UNLESS ? 'не создаётся, если ' : 'создаётся, если ';
        return $parts ? $lead . implode(' ', $parts)
            : 'откройте форму и проверьте условия создания';
    }

    private static function conditionValue(mixed $value, array $parameters, ?\Closure $name): string
    {
        if ($value === null) { return ''; }
        if (isset($parameters['values']) && is_array($parameters['values'])) {
            $out = [];
            foreach (is_array($value) ? $value : [$value] as $key) {
                if (!is_scalar($key) || !isset($parameters['values'][$key])) { return 'значение недоступно'; }
                $out[] = (string) $parameters['values'][$key];
            }
            return implode(', ', $out);
        }
        if (is_array($value) && isset($value['items_id'])) {
            $type = $value['itemtype'] ?? $parameters['itemtype'] ?? '';
            return self::objectName((string) $type, (int) $value['items_id'], $name);
        }
        if (isset($parameters['itemtype']) && is_scalar($value)) {
            return self::objectName((string) $parameters['itemtype'], (int) $value, $name);
        }
        if (is_array($value)) {
            return implode(', ', array_map(static fn($v): string => self::conditionValue($v, [], $name), $value));
        }
        return is_bool($value) ? ($value ? 'Да' : 'Нет') : (is_scalar($value) ? (string) $value : 'значение недоступно');
    }

    private static function objectName(string $type, int $id, ?\Closure $name = null): string
    {
        if ($name !== null) { return $name($type, $id); }
        $item = $type !== '' ? getItemForItemtype($type) : null;
        if (!$item instanceof \CommonDBTM || $id <= 0 || !$item->getFromDB($id)) { return 'удалено или недоступно'; }
        return (string) $item->getName();
    }

    /** Ноль раскрывает все назначения только при включённом запуске по форме. */
    private static function pairs(array $p, bool $checkAccess = false): array
    {
        if ((int) ($p['forms_destinations_id'] ?? 0) > 0 || !StartRule::isOn($p)) {
            $pair = self::pair($p);
            return $pair !== null && (!$checkAccess || $pair[0]->can((int) $pair[0]->getID(), READ)) ? [$pair] : [];
        }
        $form = new Form();
        if (!$form->getFromDB((int) ($p['forms_forms_id'] ?? 0))
            || ($checkAccess && !$form->can((int) $form->getID(), READ))) { return []; }
        $pairs = [];
        foreach ($form->getDestinations() as $destination) {
            if (($destination->fields['itemtype'] ?? '') === FormDestinationTicket::class) { $pairs[] = [$form, $destination]; }
        }
        return $pairs;
    }

    /** Только явно заданные шаги назначения: общий шаг по умолчанию не угадывается. */
    public static function stepOptions(array $p, bool $checkAccess = false): array
    {
        return self::approvalOptions(self::destinations($p, $checkAccess));
    }

    public static function approvalOptions(array $destinations): array
    {
        $out = [0 => 'Не выбран'];
        foreach ($destinations as $destination) {
            foreach ($destination['approvals'] as $approval) { $out[$approval['id']] = $approval['name']; }
        }
        return $out;
    }

    /** Сведения редактора без применения ValidationField к фиктивным ответам. */
    public static function destinations(array $p, bool $checkAccess = false): array
    {
        $out = [];
        foreach (self::pairs($p, $checkAccess) as [$form, $destination]) {
            $approvals = []; $known = true;
            try {
                $config = (new ValidationField())->getConfig($form, $destination->getConfig());
                foreach ($config->getStrategyConfigs() as $strategy) {
                    if ($strategy->getStrategy() === ValidationFieldStrategy::NO_VALIDATION) { continue; }
                    $who = [];
                    foreach ($strategy->getSpecificActors() as $type => $ids) {
                        foreach ($ids as $id) { $who[] = self::objectName($type, (int) $id); }
                    }
                    foreach ($strategy->getSpecificQuestionIds() as $id) {
                        $who[] = 'ответ на вопрос «' . self::objectName(\Glpi\Form\Question::class, (int) $id) . '»';
                    }
                    $native = (int) $strategy->getSpecificValidationStepId();
                    $steps = $native > 0 ? [['id' => $native, 'who' => implode(', ', $who)]] : [];
                    foreach ($strategy->getSpecificValidationTemplateIds() as $id) {
                        $template = new \ITILValidationTemplate();
                        if (!$template->getFromDB((int) $id)) { $known = false; continue; }
                        $steps[] = ['id' => (int) $template->fields['validationsteps_id'],
                            'who' => 'шаблон согласования «' . $template->getName() . '»'];
                    }
                    foreach ($steps as $entry) {
                        $step = new \ValidationStep();
                        if ($entry['id'] <= 0 || !$step->getFromDB($entry['id'])) { $known = false; continue; }
                        $approvals[] = ['id' => $entry['id'], 'name' => (string) $step->fields['name'],
                            'percent' => (int) $step->fields['minimal_required_validation_percent'],
                            'who' => $entry['who'] !== '' ? $entry['who'] : $strategy->getStrategy()->getLabel()];
                    }
                }
            } catch (\Throwable $e) { TransactionScope::keepGoing($e); $known = false; $approvals = []; }
            $out[] = ['id' => (int) $destination->getID(), 'name' => (string) $destination->fields['name'],
                'active' => !empty($form->fields['is_active']), 'known' => $known, 'approvals' => $approvals];
        }
        return $out;
    }

    public static function problems(array $p, array $stage): array
    {
        $destinations = self::destinations($p);
        if (!$destinations) { return ['Выберите форму и её назначение, создающее заявку.']; }
        if (empty($destinations[0]['active'])) { return ['Выбранная форма выключена.']; }
        $native = (int) ($stage['form_validationsteps_id'] ?? 0);
        if ($native <= 0 || !isset(self::approvalOptions($destinations)[$native])) {
            return ['Выберите этап согласования, заданный в назначении формы.'];
        }
        global $DB;
        $entity = (int) ($p['entities_id'] ?? 0);
        $scope = !empty($p['is_recursive']) ? array_map('intval', getSonsOf('glpi_entities', $entity)) : [$entity];
        $scope[] = $entity;
        $entities = [];
        foreach (array_unique($scope) as $id) { $entities[$id] = array_map('intval', getAncestorsOf('glpi_entities', $id)); }
        $rules = iterator_to_array($DB->request(['SELECT' => ['r.id', 'r.name', 'r.sub_type', 'r.is_active', 'r.entities_id', 'r.is_recursive', 'a.field', 'a.value'], 'FROM' => 'glpi_rules AS r',
            'INNER JOIN' => ['glpi_ruleactions AS a' => ['ON' => ['a' => 'rules_id', 'r' => 'id']]],
            'WHERE' => ['r.sub_type' => 'RuleTicket', 'r.is_active' => 1, 'a.field' => 'validationsteps_id', 'a.value' => (string) $native],
            'ORDER' => ['r.name', 'r.id']]));
        $responseRules = iterator_to_array($DB->request(['SELECT' => ['r.id', 'r.name', 'r.sub_type', 'r.is_active', 'r.entities_id', 'r.is_recursive', 'r.match', 'r.condition'],
            'FROM' => 'glpi_rules AS r', 'INNER JOIN' => ['glpi_rulecriterias AS c' => ['ON' => ['c' => 'rules_id', 'r' => 'id']]],
            'WHERE' => ['r.sub_type' => 'RuleTicket', 'r.is_active' => 1, 'c.criteria' => '_validationsteps_id'], 'ORDER' => ['r.name', 'r.id']]));
        foreach ($responseRules as $rule) {
            $rule['step_criteria'] = iterator_to_array($DB->request(['FROM' => 'glpi_rulecriterias', 'WHERE' => ['rules_id' => (int) $rule['id']]]));
            $rules[] = $rule;
        }
        return self::ruleConflicts($rules, $native, $entities);
    }

    /** Чистая проверка пересечения организаций: ключ — организация маршрута, значение — её предки. */
    public static function ruleConflicts(array $rules, int $native, array $entities): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if ($native <= 0 || (int) ($rule['is_active'] ?? 0) !== 1 || ($rule['sub_type'] ?? '') !== 'RuleTicket') { continue; }
            $action = ($rule['field'] ?? '') === 'validationsteps_id' && (string) ($rule['value'] ?? '') === (string) $native;
            if (!$action && !self::canReactToStep($rule, $native)) { continue; }
            $re = (int) $rule['entities_id'];
            foreach ($entities as $target => $ancestors) {
                if ($re === (int) $target || (!empty($rule['is_recursive']) && in_array($re, $ancestors, true))) {
                    $name = trim((string) ($rule['name'] ?? ''));
                    $label = $name !== '' ? '«' . $name . '»' : 'без названия';
                    $out[(int) $rule['id']] = 'Правило ' . $label . ' тоже использует этот этап согласования — ответ согласующего запустит и его. '
                        . 'Отключите правило или выберите другой этап согласования.';
                    break;
                }
            }
        }
        return array_values($out);
    }

    /** Остальные условия зависят от заявки; только доказанное исключение S снимает конфликт. */
    public static function canReactToStep(array $rule, int $native): bool
    {
        if (((int) ($rule['condition'] ?? 0) & \RuleTicket::ONUPDATE) === 0) { return false; }
        $values = []; $hasStep = false;
        foreach ($rule['step_criteria'] ?? [] as $criterion) {
            if (($criterion['criteria'] ?? '') !== '_validationsteps_id') { $values[] = null; continue; }
            $hasStep = true;
            $pattern = (string) ($criterion['pattern'] ?? '');
            $numeric = preg_match('/^[0-9]+$/D', $pattern) === 1;
            $values[] = match ((int) ($criterion['condition'] ?? -1)) {
                \Rule::PATTERN_IS => $numeric ? (int) $pattern === $native : null,
                \Rule::PATTERN_IS_NOT => $numeric ? (int) $pattern !== $native : null,
                default => null,
            };
        }
        if (!$hasStep) { return false; }
        return ($rule['match'] ?? 'AND') === 'AND'
            ? !in_array(false, $values, true)
            : in_array(true, $values, true) || in_array(null, $values, true);
    }

    /**
     * Непредвиденный вклад остаётся предупреждением; известный конфликт правил — блокирующая проблема.
     * Только находки этого маршрута: постоянное правило работы этапа (засчитывает форма, иначе —
     * вручную) в готовность не пишется.
     */
    public static function warnings(array $p, array $stage): array
    {
        $native = (int) ($stage['form_validationsteps_id'] ?? 0);
        if ($native <= 0 || !StartRule::isOn($p) || (int) ($p['forms_destinations_id'] ?? 0) !== 0) { return []; }
        return self::missingStepNotes(self::destinations($p), $native);
    }

    public static function missingStepNotes(array $destinations, int $native): array
    {
        $out = [];
        foreach ($destinations as $destination) {
            if (!in_array($native, array_column($destination['approvals'], 'id'), true)) {
                $out[] = 'В назначении «' . $destination['name'] . '» '
                    . (!empty($destination['known']) ? 'нет выбранного этапа согласования.' : 'не удалось прочитать настройку согласования.')
                    . ' Заявка будет ждать: укажите согласующего или засчитайте запрос из заявки.';
            }
        }
        return $out;
    }

    /**
     * Строки блока «Запуск маршрута»: согласование назначения и вопросы формы
     * в условиях этапов. Вызывающий шаблон выводит их как текст с экранированием.
     *
     * @return array{approvals:list<string>,questions:?string,no_form:?string}
     */
    public static function informational(array $p, array $stages = []): array
    {
        if ((int) ($p['forms_forms_id'] ?? 0) <= 0) {
            return ['approvals' => [], 'questions' => null,
                'no_form' => 'Форма не выбрана. В условиях этапов не будет ответов формы, а «Согласование из формы» не сработает.'];
        }
        $destinations = self::destinations($p, true);
        $approvals = self::approvalDescriptions($destinations, $stages);
        if ($destinations && !array_filter($destinations, static fn(array $d): bool => $d['approvals'] !== [] || !$d['known'])) {
            $approvals[] = count($destinations) === 1 ? 'Это назначение запрос на согласование не отправляет.'
                : 'Назначения этой формы запрос на согласование не отправляют.';
        }
        return ['approvals' => $approvals, 'questions' => self::questionsLine(FormFacts::questionCatalog((int) $p['forms_forms_id'])),
            'no_form' => null];
    }

    /** «Ответы формы в условиях этапов: N вопросов — «…», «…».» — только вопросы, которые видят условия. */
    public static function questionsLine(array $catalog): string
    {
        $labels = [];
        foreach ($catalog as $question) {
            if (is_array($question) && !empty($question['supported'])) { $labels[] = '«' . (string) ($question['label'] ?? '') . '»'; }
        }
        $n = count($labels);
        return 'Ответы формы в условиях этапов: ' . $n . ' ' . ConditionText::plural($n, 'вопрос', 'вопроса', 'вопросов')
            . ($labels ? ' — ' . implode(', ', $labels) : '') . '.';
    }

    public static function approvalDescriptions(array $destinations, array $stages): array
    {
        $out = [];
        $one = count($destinations) === 1;
        foreach ($destinations as $destination) {
            $where = $one ? 'в этом назначении' : 'в назначении «' . $destination['name'] . '»';
            foreach ($destination['approvals'] as $approval) {
                $consumers = [];
                foreach ($stages as $stage) {
                    if (($stage['approver_source'] ?? '') === 'form'
                        && (int) ($stage['form_validationsteps_id'] ?? 0) === $approval['id']) {
                        $rank = (int) ($stage['ranking'] ?? 0);
                        $consumers[] = ($rank > 0 ? $rank . ' ' : '') . '«' . ($stage['name'] ?: 'Без названия') . '»';
                    }
                }
                $out[] = 'Согласование ' . $where . ': ' . $approval['who']
                    . ' → этап согласования «' . $approval['name'] . '», нужно одобрений ' . $approval['percent'] . ' %.'
                    . ($consumers ? ' Его принимает этап ' . implode(', ', $consumers) . '.' : ' Выберите этап маршрута, который примет эти запросы.');
            }
            if (!$destination['known']) { $out[] = 'Проверьте настройку согласования назначения «' . $destination['name'] . '» в форме.'; }
        }
        return $out;
    }
}
