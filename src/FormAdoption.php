<?php
namespace GlpiPlugin\Itilflow;

use Glpi\Form\AnswersSet;
use Glpi\Form\Form;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\CommonITILField\ValidationField;

/** Серверный адаптер происхождения; не принимает доказательства из HTTP. */
final class FormAdoption
{
    private static array $captures = [];
    /** Сохраняем вход назначения до создания; состав сторонних плагинов здесь не является доказательством. */
    public static function capture(AnswersSet $answers, array $input): void
    {
        $id = (int) $answers->getID();
        if ($id > 0) { self::$captures[$id][] = ['targets' => $input['_validation_targets'] ?? null]; }
    }
    public static function afterCreated(FormDestination $destination, AnswersSet $answers, array $tickets): void
    {
        TransactionScope::requireActive();
        foreach ($tickets as $ticket) {
            if (!$ticket instanceof \Ticket) { continue; }
            foreach (CurrentRead::find(new Instance(), ['itemtype' => 'Ticket', 'items_id' => (int) $ticket->getID()]) as $row) {
                $instance = new Instance(); $instance->fields = $row;
                $snapshot = DefinitionSnapshot::load($instance);
                if ($snapshot === null || (int) ($snapshot['process']['forms_forms_id'] ?? 0) !== (int) $answers->fields['forms_forms_id']
                    || !self::matchesDestination($snapshot['process'], $instance->fields['start_source'] ?? null, (int) $destination->getID())) { continue; }
                $source = self::source($destination, $answers, $ticket);
                if (!empty($instance->fields['form_source'])) {
                    $stored = self::decode($instance->fields['form_source']);
                    if (($stored['source'] ?? null) != $source) { throw new \RuntimeException('Конфликт источника формы.'); }
                    continue; // Уже записанный исход callback не перезаписывается.
                }
                $store = ['version' => 1, 'source' => $source, 'steps' => []];
                foreach ($snapshot['stages'] as $stage) {
                    if (($stage['approver_source'] ?? '') !== 'form') { continue; }
                    $native = (int) ($stage['form_validationsteps_id'] ?? 0);
                    if (isset($store['steps'][$native])) { continue; }
                    $facts = self::callbackFacts($destination, $answers, $tickets, $ticket, $source, $native);
                    $decision = FormSource::assess($facts);
                    $store['steps'][$native] = ['status' => $decision['status'], 'reason' => $decision['reason'], 'receipt' => $decision['receipt'],
                        'form_exclusive' => (bool) ($decision['form_exclusive'] ?? false), 'configuration_digest' => self::configurationDigest($source, $native)];
                }
                self::save($instance, $store);
                $current = $instance->getCurrentStep();
                if ($current !== null) { self::tryForStep($current); }
            }
        }
    }

    /** После доказанного локального отката записывается отдельный отрицательный исход. */
    public static function failed(FormDestination $destination, AnswersSet $answers, array $tickets): void
    {
        TransactionScope::requireActive();
        foreach ($tickets as $ticket) {
            if (!$ticket instanceof \Ticket) { continue; }
            foreach (CurrentRead::find(new Instance(), ['itemtype' => 'Ticket', 'items_id' => (int) $ticket->getID()]) as $row) {
                $instance = new Instance(); $instance->fields = $row; $snapshot = DefinitionSnapshot::load($instance);
                if ($snapshot === null || !self::matchesDestination($snapshot['process'], $instance->fields['start_source'] ?? null, (int) $destination->getID())
                    || (int) ($snapshot['process']['forms_forms_id'] ?? 0) !== (int) $answers->fields['forms_forms_id']
                    || !empty($row['form_source'])) { continue; }
                $store = ['version' => 1, 'source' => self::source($destination, $answers, $ticket), 'steps' => []];
                foreach ($snapshot['stages'] as $stage) {
                    if (($stage['approver_source'] ?? '') === 'form') {
                        $store['steps'][(int) $stage['form_validationsteps_id']] = ['status' => 'unknown', 'reason' => 'callback_failed', 'receipt' => null];
                    }
                }
                self::save($instance, $store);
            }
        }
    }

    private static function source(FormDestination $d, AnswersSet $a, \Ticket $t): array
    {
        return ['form_id' => (int) $a->fields['forms_forms_id'], 'destination_id' => (int) $d->getID(),
            'answerset_id' => (int) $a->getID(), 'ticket_id' => (int) $t->getID(), 'entity_id' => (int) $t->fields['entities_id']];
    }
    private static function callbackFacts(FormDestination $destination, AnswersSet $answers, array $tickets, \Ticket $ticket, array $source, int $native): array
    {
        $form = new Form();
        if (!CurrentRead::load($form, $source['form_id']) || !CurrentRead::load($destination, $source['destination_id'])) { return []; }
        $field = new ValidationField();
        $expected = $field->applyConfiguratedValueToInputUsingAnswers($field->getConfig($form, $destination->getConfig(), $answers), [], $answers)['_validation_targets'] ?? [];
        $initial = null; $complete = false;
        foreach (self::$captures[(int) $answers->getID()] ?? [] as $capture) {
            if ($capture['targets'] === ($ticket->input['_validation_targets'] ?? null)) {
                $initial = $capture['targets']; $complete = true; break;
            }
        }
        $stepObject = \Ticket::getValidationStepInstance();
        if ($stepObject === null) { return []; }
        $steps = CurrentRead::find($stepObject, ['itemtype' => 'Ticket', 'items_id' => (int) $ticket->getID(), 'validationsteps_id' => $native]);
        $nativeStep = count($steps) === 1 ? reset($steps) : [];
        $rows = $nativeStep ? CurrentRead::find(new \TicketValidation(), ['tickets_id' => (int) $ticket->getID(), 'itils_validationsteps_id' => (int) $nativeStep['id']]) : [];
        $bindings = $rows ? CurrentRead::find(new OutcomeValidation(), ['validation_itemtype' => 'TicketValidation', 'validations_id' => array_map('intval', array_keys($rows))]) : [];
        if (array_filter(Step::ownersMap('TicketValidation', array_map('intval', array_keys($rows)))) !== []) { $bindings[] = ['owned' => true]; }
        $base = new \ValidationStep(); $percent = CurrentRead::load($base, $native) ? (int) $base->fields['minimal_required_validation_percent'] : -1;
        $default = \ValidationStep::getDefault();
        $defaultId = (int) $default->getID();
        if (isset($ticket->input['_validationsteps_threshold'])
            && (int) ($ticket->input['_validationsteps_id'] ?? $defaultId) === $native) {
            $percent = $ticket->input['_validationsteps_threshold'];
        }
        return ['evidence' => ['transaction_active' => true, 'input_complete' => $complete, 'current_read_complete' => true],
            'callback' => $source + ['answerset_form_id' => (int) $answers->fields['forms_forms_id'], 'destination_form_id' => (int) $destination->fields['forms_forms_id']],
            'source' => $source + ['validationsteps_id' => $native, 'approval_percent' => $percent],
            'ticket' => $ticket->fields, 'ticket_input' => $ticket->input,
            'created_items' => array_map(static fn($t) => ['itemtype' => 'Ticket', 'id' => (int) $t->getID(), 'entities_id' => (int) $t->fields['entities_id']], $tickets),
            'initial_targets' => $initial, 'expected_targets' => $expected,
            'templates' => self::templates($expected), 'default_validationsteps_id' => $defaultId,
            'step' => self::nativeStep($nativeStep), 'validations' => array_values($rows), 'bindings' => array_values($bindings)];
    }

    /** Только текущие заблокированные строки шаблона, без исполнения стороннего кода. */
    private static function templates(array $targets): array
    {
        $out = [];
        foreach ($targets as $target) {
            $id = filter_var($target['_template_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || isset($out[$id])) { continue; }
            $template = new \ITILValidationTemplate();
            if (!CurrentRead::load($template, $id)) { continue; }
            $rows = CurrentRead::find(new \ITILValidationTemplate_Target(), ['itilvalidationtemplates_id' => $id]);
            $out[$id] = ['id' => $id, 'validationsteps_id' => $template->fields['validationsteps_id'],
                'targets' => array_values(array_map(static fn(array $r): array => ['itemtype' => $r['itemtype'], 'items_id' => $r['items_id']], $rows))];
        }
        return $out;
    }

    private static function nativeStep(array $row): array
    {
        return ['id' => (int) ($row['id'] ?? 0), 'ticket_id' => (int) ($row['items_id'] ?? 0),
            'validationsteps_id' => (int) ($row['validationsteps_id'] ?? 0), 'approval_percent' => (int) ($row['minimal_required_validation_percent'] ?? -1)];
    }
    /** Изменение правил/назначения/потребителей закрывает автоматическую повторную привязку и очистку. */
    private static function configurationDigest(array $source, int $native): ?string
    {
        $form = new Form(); $destination = new FormDestination(); $base = new \ValidationStep();
        if (!CurrentRead::load($form, (int) $source['form_id']) || !CurrentRead::load($destination, (int) $source['destination_id'])
            || !CurrentRead::load($base, $native) || (int) $destination->fields['forms_forms_id'] !== (int) $form->getID()) { return null; }
        // Только явные потребители выбранной ступени: не блокируем сотни несвязанных правил.
        $actions = CurrentRead::find(new \RuleAction(), ['field' => ['_validationsteps_id', 'validationsteps_id'], 'value' => (string) $native]);
        $ids = array_values(array_unique(array_map(static fn($a) => (int) $a['rules_id'], $actions)));
        $rules = $ids ? CurrentRead::find(new \Rule(), ['id' => $ids]) : [];
        $templateInputs = [];
        $config = (new ValidationField())->getConfig($form, $destination->getConfig());
        foreach ($config->getStrategyConfigs() as $strategy) {
            foreach ($strategy->getSpecificValidationTemplateIds() as $id) { $templateInputs[] = ['_template_id' => $id]; }
        }
        $data = [$destination->fields, $base->fields,
            self::templates($templateInputs),
            CurrentRead::find(new Stage(), ['form_validationsteps_id' => $native]), $rules,
            $ids ? CurrentRead::find(new \RuleAction(), ['rules_id' => $ids]) : [],
            $ids ? CurrentRead::find(new \RuleCriteria(), ['rules_id' => $ids]) : []];
        return hash('sha256', DefinitionSnapshot::encode($data));
    }
    private static function save(Instance $instance, array $store): void
    {
        if (!$instance->update(['id' => $instance->getID(), 'form_source' => DefinitionSnapshot::encode($store)])) { throw new \RuntimeException('Источник формы не сохранён.'); }
    }
    private static function decode(mixed $json): ?array
    {
        $value = is_string($json) ? json_decode($json, true) : null;
        return is_array($value) && ($value['version'] ?? null) === 1 && is_array($value['steps'] ?? null) ? $value : null;
    }
    public static function storedReceipt(Step $step): ?array
    {
        $instance = $step->getInstance(); $stage = $step->getStage();
        if ($instance === null || $stage === null || ($stage->fields['approver_source'] ?? '') !== 'form' || (int) $step->fields['pass_number'] !== 1) { return null; }
        $store = self::decode($instance->fields['form_source'] ?? null);
        $entry = $store['steps'][(int) $stage->fields['form_validationsteps_id']] ?? null;
        return is_array($entry) && $entry['status'] === 'verified' && is_array($entry['receipt']) ? $entry['receipt'] : null;
    }
    /** Только объяснение сохранённого исхода для экрана; никаких блокировок или повторного принятия. */
    public static function reasonForStep(Step $step): ?string
    {
        if (!$step->awaitsFormRequests()) { return null; }
        $instance = $step->getInstance(); $stage = $step->getStage();
        if ($instance === null || $stage === null) { return self::reasonText('missing_context'); }
        if (($stage->fields['approver_source'] ?? '') !== 'form') { return null; }
        if ((int) ($step->fields['pass_number'] ?? 0) !== 1) { return 'Это повтор этапа — согласование из формы само не засчитывается. Засчитайте вручную.'; }
        $store = self::decode($instance->fields['form_source'] ?? null);
        $entry = $store['steps'][(int) ($stage->fields['form_validationsteps_id'] ?? 0)] ?? null;
        // Заявка не с формы — ждём согласующего; с формы, но без записи — связать не удалось.
        if ($store === null) { return self::reasonText('waiting_for_requests'); }
        if (!is_array($entry)) { return 'Согласование из формы не найдено — засчитайте вручную.'; }
        return self::reasonText($entry['reason'] ?? 'current_set_changed');
    }

    public static function reasonText(?string $reason): string
    {
        return match ($reason) {
            'waiting_for_requests' => 'Ждём: укажите согласующего или засчитайте запрос из заявки',
            'validation_count_mismatch', 'validation_targets_mismatch', 'validation_identity_mismatch' => 'Запросы этапа согласования не совпали с формой. Засчитайте вручную.',
            'ambiguous_contribution' => 'Этого согласующего назначили и форма, и правило. Засчитайте вручную.',
            'unknown_rule_contribution', 'external_or_unknown_contribution', 'external_validation_input' => 'Часть запросов добавило правило или шаблон. Засчитайте вручную.',
            'unknown_targets', 'targets_changed', 'selected_step_missing' => 'Назначение формы изменилось. Засчитайте вручную.',
            'already_bound', 'already_bound_or_unknown' => 'Эти запросы уже засчитаны другому этапу.',
            'callback_failed' => 'Не удалось связать согласование с формой. Засчитайте вручную.',
            'not_waiting' => 'На момент проверки запрос уже имел ответ. Его должен проверить администратор маршрутов.',
            default => 'Не удалось проверить согласование из формы. Засчитайте вручную.',
        };
    }
    public static function tryForStep(Step $step): bool
    {
        TransactionScope::requireActive();
        $receipt = self::storedReceipt($step);
        if ($receipt === null) { return false; }
        $fresh = self::fresh($step, $receipt, false);
        if (!empty($fresh['bindings'])) { $fresh = self::fresh($step, $receipt, true); }
        $decision = FormSource::recheck($receipt, $fresh);
        return $decision['can_adopt'] && Engine::adoptFormRequests($step, $decision['validation_ids'], $receipt);
    }
    public static function verifiedCancellationIds(Step $step): array
    {
        TransactionScope::requireActive();
        if (!in_array(OutcomeValidation::KIND_FORM, $step->bindingKinds(), true)) { return []; }
        $receipt = self::storedReceipt($step);
        if ($receipt === null) { return self::cancellationSkipped($step, 'Проверьте, какие запросы созданы формой.'); }
        if (!self::exclusiveForCancellation($step, (int) $receipt['validationsteps_id'])) {
            return self::cancellationSkipped($step, 'Проверьте, не используется ли этот этап согласования другим этапом или правилом.');
        }
        $fresh = self::fresh($step, $receipt, true);
        $decision = FormSource::recheck($receipt, $fresh);
        if (!$decision['can_adopt']) { return self::cancellationSkipped($step, 'Изменились настройки, состав запросов или принадлежность этапа.'); }
        return array_keys(array_filter($decision['validation_statuses'], static fn($status) => $status === 2));
    }
    /** Будущая работа отменяется без открытия этапа и без выдуманной привязки. Только terminal command. */
    public static function futureCancellationIds(Step $step): array
    {
        TransactionScope::requireActive();
        $instance = $step->getInstance(); $stage = $step->getStage();
        if ($instance === null || $stage === null || ($stage->fields['approver_source'] ?? '') !== 'form') { return []; }
        $attempt = Attempt::current($instance);
        if ($attempt === null || !self::futureEligible($step->fields, $attempt->fields, (int) $instance->getID())) { return []; }
        $receipt = self::storedReceipt($step);
        if ($receipt === null) { return self::cancellationSkipped($step, 'Проверьте запросы формы для будущего этапа.'); }
        if (!self::exclusiveForCancellation($step, (int) $receipt['validationsteps_id'])) {
            return self::cancellationSkipped($step, 'Проверьте, не используется ли будущий этап согласования другим этапом или правилом.');
        }
        $fresh = self::fresh($step, $receipt, false);
        $decision = FormSource::recheck($receipt, $fresh);
        if (!$decision['can_adopt']) { return self::cancellationSkipped($step, 'Происхождение или состав запросов будущего этапа изменились.'); }
        // Даже устаревшая legacy-ссылка запрещает снятие; native строки уже заблокированы fresh().
        if (array_filter(Step::ownersMap('TicketValidation', $decision['validation_ids'])) !== []) {
            return self::cancellationSkipped($step, 'Запрос будущего этапа принадлежит другому этапу.');
        }
        return array_keys(array_filter($decision['validation_statuses'], static fn($status) => $status === 2));
    }
    /** Только первый ещё не начатый шаг текущей попытки, без каких-либо созданных артефактов. */
    public static function futureEligible(array $step, array $attempt, int $instanceId): bool
    {
        return $instanceId > 0 && (int) ($step['id'] ?? 0) > 0 && ($step['state'] ?? '') === Step::PENDING
            && (int) ($step['pass_number'] ?? 0) === 1 && (int) ($attempt['id'] ?? 0) > 0
            && (int) ($step['attempt_id'] ?? 0) === (int) $attempt['id']
            && (int) ($step['plugin_itilflow_instances_id'] ?? 0) === $instanceId
            && (int) ($attempt['plugin_itilflow_instances_id'] ?? 0) === $instanceId
            && in_array($attempt['state'] ?? '', [Attempt::RUNNING, Attempt::WAITING_INPUT], true)
            && (int) ($step['artifact_items_id'] ?? 0) === 0 && empty($step['artifact_itemtype'])
            && empty($step['approver_items_ids']) && ($step['approval_expected_count'] ?? null) === null
            && empty($step['date_start']);
    }
    private static function cancellationSkipped(Step $step, string $reason): array
    {
        $instance = $step->getInstance();
        if ($instance === null) { return []; }
        $stage = $step->getStage();
        $name = trim((string) ($stage?->fields['name'] ?? ''));
        $message = 'Запросы согласования формы сохранены. Этап ' . ($name !== '' ? '«' . $name . '»' : 'без названия') . ': ' . $reason;
        if (CurrentRead::find(new Violation(), ['plugin_itilflow_instances_id' => (int) $instance->getID(),
            'action' => 'form_cleanup_skipped', 'message' => $message], [], 1) === []
            && !Violation::record($instance, 'Ticket', (int) $instance->fields['items_id'], 'form_cleanup_skipped', false, $message)) {
            throw new \RuntimeException('Причина сохранения запросов формы не записана.');
        }
        return [];
    }
    /** Неподтверждённая исключительность запрещает снятие, но не стирает происхождение. */
    private static function exclusiveForCancellation(Step $step, int $native): bool
    {
        $instance = $step->getInstance();
        if ($instance === null) { return false; }
        $store = self::decode($instance->fields['form_source'] ?? null);
        // Смешанный состав формы и правила пригоден для ответа, но не даёт права удаления.
        if (($store['steps'][$native]['form_exclusive'] ?? false) !== true) { return false; }
        // Потребители — этапы маршрута, с которым идёт эта заявка (его снимок). Этап другого маршрута
        // той же формы с тем же этапом согласования — штатная схема «несколько маршрутов на одно
        // назначение»: эту заявку он не ведёт, а другой запуск на этой заявке проверяется ниже.
        $own = DefinitionSnapshot::load($instance);
        if ($own === null) { return false; }
        $consumers = self::routeConsumers($own['stages'], $native, (int) $instance->fields['plugin_itilflow_processes_id']);
        $actions = CurrentRead::find(new \RuleAction(), ['field' => ['_validationsteps_id', 'validationsteps_id'], 'value' => (string) $native]);
        if (!self::exclusiveStage($consumers, $actions, (int) $step->fields['plugin_itilflow_stages_id'],
            (int) $instance->fields['plugin_itilflow_processes_id'])) { return false; }
        foreach (CurrentRead::find(new Instance(), ['itemtype' => 'Ticket', 'items_id' => (int) $instance->fields['items_id']]) as $row) {
            if ((int) $row['id'] === (int) $instance->getID()) { continue; }
            $other = new Instance(); $other->fields = $row; $snapshot = DefinitionSnapshot::load($other);
            if ($snapshot === null) { return false; }
            foreach ($snapshot['stages'] as $stage) {
                if (($stage['approver_source'] ?? '') === 'form' && (int) ($stage['form_validationsteps_id'] ?? 0) === $native) { return false; }
            }
        }
        return true;
    }
    /**
     * Этапы маршрута $processId, которые принимают запросы этапа согласования формы $native.
     * Этапы других маршрутов — не потребители этой заявки.
     */
    public static function routeConsumers(array $stages, int $native, int $processId): array
    {
        return array_values(array_filter($stages, static fn($s): bool => is_array($s)
            && (int) ($s['plugin_itilflow_processes_id'] ?? 0) === $processId
            && ($s['approver_source'] ?? '') === 'form' && (int) ($s['form_validationsteps_id'] ?? 0) === $native));
    }

    /** Чистая часть запрета общего потребителя, независимо от состояния его виз. */
    public static function exclusiveStage(array $consumers, array $actions, int $stageId, int $processId): bool
    {
        if ($stageId <= 0 || $processId <= 0 || count($consumers) !== 1 || $actions !== []) { return false; }
        $stage = reset($consumers);
        return (int) ($stage['id'] ?? 0) === $stageId && (int) ($stage['plugin_itilflow_processes_id'] ?? 0) === $processId
            && ($stage['approver_source'] ?? '') === 'form';
    }
    private static function fresh(Step $step, array $receipt, bool $owned): array
    {
        $instance = $step->getInstance(); $stage = $step->getStage();
        if ($instance === null || $stage === null) { return []; }
        $store = self::decode($instance->fields['form_source'] ?? null);
        $snapshot = DefinitionSnapshot::load($instance);
        if ($store === null || $snapshot === null || ($instance->fields['itemtype'] ?? '') !== 'Ticket'
            || (int) ($store['source']['ticket_id'] ?? 0) !== (int) $instance->fields['items_id']
            || (int) ($store['source']['form_id'] ?? 0) !== (int) $snapshot['process']['forms_forms_id']
            || !self::matchesDestination($snapshot['process'], $instance->fields['start_source'] ?? null,
                (int) ($store['source']['destination_id'] ?? 0))) { return []; }
        $entry = $store['steps'][(int) $stage->fields['form_validationsteps_id']] ?? [];
        $configuration = self::configurationDigest($store['source'], (int) $stage->fields['form_validationsteps_id']);
        if ($configuration === null
            || ($entry['configuration_digest'] ?? null) !== $configuration) { return []; }
        $native = \Ticket::getValidationStepInstance();
        if ($native === null || !CurrentRead::load($native, (int) $receipt['step_id']) || ($native->fields['itemtype'] ?? '') !== 'Ticket'
            || (int) $native->fields['items_id'] !== (int) $instance->fields['items_id']) { return []; }
        $rows = CurrentRead::find(new \TicketValidation(), ['tickets_id' => (int) $instance->fields['items_id'], 'itils_validationsteps_id' => (int) $native->getID()]);
        $bindings = $rows ? CurrentRead::find(new OutcomeValidation(), ['validation_itemtype' => 'TicketValidation', 'validations_id' => array_map('intval', array_keys($rows))]) : [];
        if ($owned) {
            if (count($bindings) !== count($rows)) { return []; }
            foreach ($bindings as $binding) {
                if ((int) $binding['plugin_itilflow_steps_id'] !== (int) $step->getID() || (int) $binding['attempt_id'] !== (int) $step->fields['attempt_id']
                    || $binding['binding_kind'] !== OutcomeValidation::KIND_FORM) { return []; }
            }
            foreach (Step::ownersMap('TicketValidation', array_map('intval', array_keys($rows))) as $owners) {
                if ($owners !== [(int) $step->getID()]) { return []; }
            }
            $bindings = []; // Принадлежность уже проверена выше; pure recheck сверяет всю native ступень.
        }
        return ['evidence' => ['transaction_active' => true, 'current_read_complete' => true, 'receipt_from_trusted_store' => true],
            'source' => $store['source'], 'step' => self::nativeStep($native->fields), 'validations' => array_values($rows), 'bindings' => array_values($bindings)];
    }

    /** Фактическое назначение запуска важнее общего выбора в определении; старые запуски читаются как раньше. */
    public static function matchesDestination(array $process, mixed $startSource, int $destinationId): bool
    {
        $actual = FormStart::runDestination($process, $startSource);
        return $destinationId > 0 && ($actual === 0 || $actual === $destinationId);
    }
}
