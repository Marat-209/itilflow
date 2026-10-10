<?php

namespace GlpiPlugin\Itilflow;

use Glpi\Application\View\TemplateRenderer;

/** Формирует только разрешённую проекцию; причины и полные снимки не передаются в Twig. */
final class StructuredUi
{
    public static function childStep(\CommonITILObject $item): ?Step
    {
        if (Authorizer::itemError($item, READ) !== null) { return null; }
        $step = Step::getByArtifact($item->getType(), $item->getID());
        $instance = $step?->getInstance();
        return $instance !== null && DefinitionSnapshot::enabled($instance)
            && StructuredAccess::child($step) !== null ? $step : null;
    }

    public static function show(\CommonITILObject $item, ?Instance $instance, ?Step $child_step): void
    {
        if (Authorizer::itemError($item, READ) !== null) { return; }
        $instance ??= $child_step?->getInstance();
        if ($instance === null || !DefinitionSnapshot::enabled($instance)) { return; }
        $attempt = Attempt::current($instance);
        if ($attempt === null || DefinitionSnapshot::load($instance) === null) {
            echo '<div class="alert alert-danger">Снимок маршрута не подтверждён. Действия недоступны.</div>';
            return;
        }
        $forms = []; $history = [];
        $rows = $child_step !== null ? [$child_step->fields] : $instance->getSteps();
        foreach ($rows as $row) {
            $step = new Step();
            if (!$step->getFromDB((int) $row['id'])) { continue; }
            $outcome = Outcome::forStep($step->getID());
            if ($outcome !== null) {
                $history[] = ['stage' => $row['stage_name'], 'pass' => (int) $row['pass_number'],
                    'label' => Outcome::labels()[$outcome->fields['outcome']] ?? 'Неизвестный исход',
                    'summary' => (string) ($outcome->fields['public_summary'] ?? ''),
                    'actor' => (int) $outcome->fields['users_id'] > 0 ? getUserName((int) $outcome->fields['users_id']) : 'Не подтверждён',
                    'pending' => !empty($outcome->fields['explanation_pending']),
                    'superseded' => !empty($row['superseded_by_attempt_id'])];
                if (!empty($outcome->fields['explanation_pending']) && StructuredAccess::explanationError($outcome, $step) === null) {
                    $positive = $outcome->fields['outcome'] === Outcome::COMPLETED;
                    if (!$positive || Engine::canPublishPositive($outcome, $step)) {
                        $forms[] = self::form($positive ? 'publish_positive_result' : 'explain_outcome', $instance, $attempt, $step)
                        + ['outcomes_id' => $outcome->getID(), 'title' => $positive
                            ? 'Оформить публичный итог и продолжить' : 'Публичное пояснение результата', 'needs_subject' => false];
                    }
                }
            } elseif (StructuredAccess::answerError($step) === null) {
                $forms[] = self::form('department_outcome', $instance, $attempt, $step)
                    + ['title' => 'Исход работы отдела', 'needs_subject' => false];
            }
            if ($outcome === null && StructuredAccess::answerError($step, true) === null && count(self::targets($step))) {
                $forms[] = self::form('request_clarification', $instance, $attempt, $step)
                    + ['title' => 'Вернуть на уточнение', 'targets' => self::targets($step), 'needs_subject' => false];
            }
        }
        $manager = $child_step === null && StructuredAccess::manageError($instance) === null;
        if ($manager && $attempt->fields['state'] === Attempt::WAITING_INPUT) {
            $forms[] = self::form('activate_attempt', $instance, $attempt)
                + ['title' => 'Подтвердить уточнённый предмет и начать попытку', 'needs_subject' => true];
        }
        if ($manager && in_array($attempt->fields['state'], [Attempt::REFUSED, Attempt::ABORTED, Attempt::DONE], true)) {
            $targets = [];
            foreach ($instance->getAttemptSteps() as $row) {
                $step = new Step(); $step->getFromDB((int) $row['id']);
                if (in_array($row['state'], [Step::REJECTED, Step::DONE, Step::SKIPPED], true)) { $targets = self::targets($step); }
            }
            if ($attempt->fields['state'] === Attempt::ABORTED && (int) ($attempt->fields['source_step_id'] ?? 0) > 0) {
                $source = new Step();
                if ($source->getFromDB((int) $attempt->fields['source_step_id'])
                    && (int) $source->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()) { $targets = self::targets($source); }
            }
            if (count($targets)) {
                $forms[] = self::form('new_attempt', $instance, $attempt)
                    + ['title' => 'Новая попытка в этой заявке', 'targets' => $targets, 'needs_subject' => true];
            }
        }
        $pending_transfer = false;
        $current = $instance->getCurrentStep();
        if ($current !== null && Outcome::forStep($current->getID()) === null) {
            $roster = StructuredAccess::roster($current);
            if ($roster !== null) {
                foreach ($roster as $row) {
                    if (in_array((int) $row['status'], [\CommonITILValidation::ACCEPTED, \CommonITILValidation::REFUSED], true)) {
                        $pending_transfer = true;
                        if ($manager) {
                            $forms[] = self::form('reconcile_outcome', $instance, $attempt, $current)
                                + ['title' => 'Сверить сохранённый ответ', 'validation_itemtype' => $row['_type'],
                                    'validations_id' => (int) $row['id'], 'needs_subject' => false];
                        }
                        break;
                    }
                }
            }
        }
        TemplateRenderer::getInstance()->display('@itilflow/structured.html.twig', [
            'forms' => $forms, 'history' => $history, 'attempt_number' => (int) $attempt->fields['number'],
            'attempt_state' => $attempt->fields['state'], 'pending_transfer' => $pending_transfer,
        ]);
    }

    private static function targets(Step $step): array
    {
        $instance = $step->getInstance(); $stage = $step->getStage(); $result = [];
        if ($instance === null || $stage === null) { return []; }
        foreach (DefinitionSnapshot::targets($stage->fields['return_targets']) ?? [] as $id) {
            $target = DefinitionSnapshot::stage($instance, $id);
            if ($target !== null) { $result[$id] = $target->fields['name']; }
        }
        return $result;
    }

    private static function form(string $action, Instance $instance, Attempt $attempt, ?Step $step = null): array
    {
        return ['action' => $action, 'command_key' => bin2hex(random_bytes(32)),
            'instances_id' => $instance->getID(), 'steps_id' => $step?->getID() ?? 0,
            'expected_attempt_id' => $attempt->getID()];
    }
}
