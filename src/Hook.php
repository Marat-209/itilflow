<?php

namespace GlpiPlugin\Itilflow;

use CommonDBTM;
use CommonITILObject;
use CommonITILTask;
use CommonITILValidation;
use Group;
use Planning;
use Session;

/** Обработчики штатных точек расширения GLPI. */
final class Hook
{
    /** @var array<string,Step|false> кэш «объект -> шаг» в пределах запроса */
    private static array $step_cache = [];

    private static function stepFor(string $itemtype, int $items_id): ?Step
    {
        $key = $itemtype . '#' . $items_id;
        if (!array_key_exists($key, self::$step_cache)) {
            self::$step_cache[$key] = Step::getByArtifact($itemtype, $items_id) ?? false;
        }
        return self::$step_cache[$key] ?: null;
    }

    private static function forget(string $itemtype, int $items_id): void
    {
        unset(self::$step_cache[$itemtype . '#' . $items_id]);
    }

    // =========================================================== слой 1: can()

    /**
     * Отзыв права на объекты, которые относятся не к текущему этапу
     * либо закреплены за другой группой. Вызывается формой, Kanban,
     * массовыми действиями и API.
     */
    public static function itemCan(CommonDBTM $item): void
    {
        if (Engine::isInside() || !is_int($item->right) || $item->right <= 0) {
            return;
        }
        if (!($item instanceof CommonITILTask) || $item->isNewItem()) {
            return;
        }
        if ($item->right !== UPDATE && $item->right !== PURGE) {
            return;
        }
        $step = self::stepFor($item->getType(), $item->getID());
        if ($step === null) {
            return; // обычная задача, плагин не вмешивается
        }
        $instance = $step->getInstance();
        if ($instance === null || Engine::enforcement($instance) !== Process::ENFORCE_STRICT) {
            return;
        }
        if (Engine::userMayBypass()) {
            return;
        }
        if ($step->fields['state'] !== Step::RUNNING) {
            $item->right = -1;
            return;
        }
        if (!$step->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups())) {
            $item->right = -1;
        }
    }

    // ====================================================== слой 2: update()

    /** Задача этапа: закрыть можно только текущий этап и только его исполнителю. */
    public static function preTaskUpdate(CommonITILTask $task): void
    {
        if (Engine::isInside() || !is_array($task->input)) {
            return;
        }
        $step = self::stepFor($task->getType(), $task->getID());
        if ($step === null) {
            return;
        }
        $instance = $step->getInstance();
        if ($instance === null) {
            return;
        }

        $wants_done = isset($task->input['state'])
            && (int) $task->input['state'] === Planning::DONE
            && (int) $task->fields['state'] !== Planning::DONE;
        if (!$wants_done) {
            return;
        }

        if ($step->fields['state'] !== Step::RUNNING) {
            $blocking = self::firstOpenStageName($instance, (int) $step->fields['ranking']);
            $msg = $step->fields['state'] === Step::DONE
                ? sprintf('Этап «%s» уже завершён.', (string) $step->fields['stage_name'])
                : sprintf(
                    'Этап «%s» ещё не наступил: не завершён этап «%s». Этапы идут строго по порядку.',
                    (string) $step->fields['stage_name'],
                    $blocking
                );
            if (Engine::deny($instance, $task->getType(), $task->getID(), 'complete_out_of_order', $msg)) {
                $task->input = false;
            }
            return;
        }

        if (!$step->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups())) {
            $g = new Group();
            $owner = (int) $step->fields['groups_id'] > 0 && $g->getFromDB((int) $step->fields['groups_id'])
                ? $g->fields['name']
                : getUserName((int) $step->fields['users_id']);
            $msg = sprintf('Этап «%s» закреплён за «%s». Завершить его может только ответственный.',
                (string) $step->fields['stage_name'], $owner);
            if (Engine::deny($instance, $task->getType(), $task->getID(), 'complete_foreign_stage', $msg)) {
                $task->input = false;
            }
        }
    }

    /**
     * Маршрут держит заявку открытой, пока он идёт ИЛИ остановлен.
     * Остановленный маршрут — это застрявший процесс, а не разрешение
     * тихо закрыть заявку: его нужно либо возобновить, либо прервать явно.
     */
    private static function blocksResolution(?Instance $instance): bool
    {
        if ($instance === null) {
            return false;
        }
        if (!in_array($instance->fields['state'], [Instance::RUNNING, Instance::HALTED], true)) {
            return false;
        }
        $process = $instance->getProcess();
        return $process !== null && (bool) $process->fields['block_resolution'];
    }

    private static function blockReason(Instance $instance): string
    {
        $process = $instance->getProcess();
        $name = $process ? $process->fields['name'] : '';
        if ($instance->fields['state'] === Instance::HALTED) {
            return sprintf(
                'Маршрут «%s» остановлен и требует решения: возобновите его или прервите явно.',
                $name
            );
        }
        $cur = $instance->getCurrentStep();
        return sprintf(
            'Маршрут «%s» не пройден: открыт этап «%s».',
            $name,
            $cur ? (string) $cur->fields['stage_name'] : '—'
        );
    }

    private static function firstOpenStageName(Instance $instance, int $before_ranking): string
    {
        foreach ($instance->getSteps() as $row) {
            if ((int) $row['ranking'] < $before_ranking
                && !in_array($row['state'], [Step::DONE, Step::SKIPPED], true)) {
                return (string) $row['stage_name'];
            }
        }
        return '—';
    }

    /** Пока маршрут идёт, заявку нельзя решить или закрыть. */
    public static function preItilUpdate(CommonITILObject $item): void
    {
        if (Engine::isInside() || !is_array($item->input) || !isset($item->input['status'])) {
            return;
        }
        $new = (int) $item->input['status'];
        $closing = array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray());
        if (!in_array($new, $closing, true) || in_array((int) $item->fields['status'], $closing, true)) {
            return;
        }
        $instance = Instance::getForItem($item);
        if (!self::blocksResolution($instance)) {
            return;
        }
        $msg = self::blockReason($instance) . ' Закрыть заявку можно после завершения всех этапов.';
        if (Engine::deny($instance, $item->getType(), $item->getID(), 'resolve_with_open_stages', $msg)) {
            $item->input = false;
        }
    }

    /** То же самое для решения: перехватываем до записи решения, а не после. */
    public static function preSolutionAdd(CommonDBTM $solution): void
    {
        if (Engine::isInside() || !is_array($solution->input)) {
            return;
        }
        $itemtype = (string) ($solution->input['itemtype'] ?? '');
        $items_id = (int) ($solution->input['items_id'] ?? 0);
        if ($itemtype === '' || $items_id <= 0 || !is_a($itemtype, CommonITILObject::class, true)) {
            return;
        }
        $item = new $itemtype();
        if (!$item->getFromDB($items_id)) {
            return;
        }
        $instance = Instance::getForItem($item);
        if (!self::blocksResolution($instance)) {
            return;
        }
        $msg = 'Нельзя добавить решение. ' . self::blockReason($instance);
        if (Engine::deny($instance, $itemtype, $items_id, 'solution_with_open_stages', $msg)) {
            $solution->input = false;
        }
    }

    // ============================================================= продвижение

    public static function postTaskUpdate(CommonITILTask $task): void
    {
        if (Engine::isInside()) {
            return;
        }
        if (!in_array('state', $task->updates ?? [], true)) {
            return;
        }
        if ((int) $task->fields['state'] !== Planning::DONE) {
            return;
        }
        self::forget($task->getType(), $task->getID());
        $step = Step::getByArtifact($task->getType(), $task->getID());
        if ($step === null || $step->fields['state'] !== Step::RUNNING) {
            return;
        }
        Engine::completeStep($step, 'Задача этапа отмечена выполненной.');
    }

    /** Решение дочерней заявки закрывает соответствующий этап родителя. */
    public static function postItilUpdate(CommonITILObject $item): void
    {
        if (Engine::isInside()) {
            return;
        }
        if (!in_array('status', $item->updates ?? [], true)) {
            return;
        }
        $closing = array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray());
        if (!in_array((int) $item->fields['status'], $closing, true)) {
            return;
        }
        $step = Step::getByArtifact($item->getType(), $item->getID());
        if ($step === null || $step->fields['state'] !== Step::RUNNING) {
            return;
        }
        Engine::completeStep($step, sprintf('Дочерняя заявка №%d решена.', $item->getID()));
    }

    public static function postValidationUpdate(CommonITILValidation $val): void
    {
        if (Engine::isInside()) {
            return;
        }
        if (!in_array('status', $val->updates ?? [], true)) {
            return;
        }
        $step = Step::getByArtifact($val->getType(), $val->getID());
        if ($step === null || $step->fields['state'] !== Step::RUNNING) {
            return;
        }
        $status = (int) $val->fields['status'];
        $comment = strip_tags((string) ($val->fields['comment_validation'] ?? ''));

        if ($status === CommonITILValidation::ACCEPTED) {
            Engine::completeStep($step, 'Согласовано. ' . $comment);
        } elseif ($status === CommonITILValidation::REFUSED) {
            Engine::rejectStep($step, 'Отклонено. ' . $comment);
        }
    }

    /** Запуск маршрута действием штатного бизнес-правила. */
    public static function postItilAdd(CommonITILObject $item): void
    {
        if (Engine::isInside() || !is_array($item->input)) {
            return;
        }
        $pid = (int) ($item->input['_plugin_itilflow_processes_id'] ?? 0);
        if ($pid <= 0) {
            return;
        }
        Engine::start($item, $pid);
    }

    public static function prePurgeItil(CommonITILObject $item): void
    {
        $instance = Instance::getForItem($item);
        if ($instance === null) {
            return;
        }
        $instance->delete(['id' => $instance->getID()], true);
    }

    /** Перенос заявки в другую сущность: экземпляр обязан переехать вместе с ней. */
    public static function itemTransfer(array $params): void
    {
        $itemtype = (string) ($params['type'] ?? '');
        $newID = (int) ($params['newID'] ?? $params['id'] ?? 0);
        $entities_id = (int) ($params['entities_id'] ?? 0);
        if (!is_a($itemtype, CommonITILObject::class, true) || $newID <= 0) {
            return;
        }
        $item = new $itemtype();
        if (!$item->getFromDB($newID)) {
            return;
        }
        $instance = Instance::getForItem($item);
        if ($instance === null) {
            return;
        }
        $instance->update(['id' => $instance->getID(), 'entities_id' => $entities_id]);

        // Ответственные этапов могли остаться в сущности-доноре.
        $cur = $instance->getCurrentStep();
        if ($cur !== null) {
            $stage = $cur->getStage();
            if ($stage !== null && !$stage->groupFitsEntity($entities_id)) {
                Engine::halt(
                    $instance,
                    'после переноса в другую сущность ответственный текущего этапа стал недоступен'
                );
            }
        }
    }
}
