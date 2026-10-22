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

    /** @var array<string,true> заявки, которые удаляются навсегда в этом запросе: «Тип#номер» */
    private static array $purging = [];

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

        $error = Authorizer::stepError($step, 'complete');
        if ($error !== null) {
            Violation::record($instance, $task->getType(), $task->getID(),
                'action_task_complete', true, $error);
            Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
            $task->input = false;
            return;
        }

        // Обязательный отчёт живёт в форме «Завершить этап». Отметка задачи
        // выполненной его не спрашивает, поэтому при обязательном отчёте
        // такой обход закрываем. Статус задачи не придумываем: она остаётся
        // в штатном «к выполнению», пока этап не закроют формой.
        $stage = $step->getStage();
        if ($stage !== null
            && $stage->fields['execution_mode'] === Stage::MODE_INLINE
            && $stage->fields['completion_comment'] === 'required') {
            Session::addMessageAfterRedirect(
                'Завершите этап кнопкой «Завершить этап» и заполните «Что сделано».',
                false,
                ERROR
            );
            $task->input = false;
        }
    }

    /**
     * Пункт в меню действий ленты заявки. Пустой массив — пункта нет:
     * согласование с уже созданным запросом и дочерняя заявка закрываются
     * штатными средствами GLPI.
     *
     * @param array{item?: CommonITILObject} $params
     * @return array<string, array<string, mixed>>
     */
    public static function timelineAnswerActions(array $params): array
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof CommonITILObject || $item->isNewItem()) {
            return [];
        }
        // Самообслуживание сюда не пускаем: приём формы требует центральный
        // интерфейс, как и кнопка на вкладке маршрута.
        if (Session::getCurrentInterface() !== 'central') {
            return [];
        }
        $action = Ui::currentAction($item);
        if ($action === null) {
            return [];
        }
        $step = new Step();
        if (!$step->getFromDB((int) $action['steps_id'])) {
            return [];
        }
        $approver = $action['kind'] === 'approver';
        return [
            'itilflow_stage' => [
                'type'         => 'itilflow',
                'class'        => 'ItilflowStage',
                'icon'         => $approver ? 'ti ti-user-question' : 'ti ti-route',
                'label'        => $approver ? 'Указать согласующего' : 'Завершить этап',
                'short_label'  => $approver ? 'Согласующий' : 'Этап',
                'template'     => '@itilflow/timeline_stage.html.twig',
                'item'         => $step,
            ],
        ];
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
                'Маршрут «%s» остановлен: возобновите или прервите его.',
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
        // Промежуточный пересчёт ядра, пока маршрут снимает свой запрос (см. dropWaitingValidations):
        // ядро записало бы «Отклонено» за опустевшую ступень. Итоговый статус маршрут запишет сам.
        if (is_array($item->input) && !empty($item->input['_from_itilvalidation'])
            && ($item->input['_trigger'] ?? null) instanceof CommonITILValidation
            && Engine::isDropping($item->input['_trigger'])) {
            $item->input = false;
            return;
        }
        if (Engine::isInside() || !is_array($item->input) || !isset($item->input['status'])) {
            return;
        }
        $new = (int) $item->input['status'];
        $closing = array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray());
        if (!in_array($new, $closing, true) || in_array((int) $item->fields['status'], $closing, true)) {
            return;
        }
        if (self::childAwaitsOutcome($item)) {
            $item->input = false;
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

    /**
     * Дочерняя работа нового порядка решается исходом отдела, а не вручную:
     * иначе этап ждал бы исхода вечно. Проверка целостности — в любом режиме контроля.
     */
    private static function childAwaitsOutcome(CommonITILObject $item): bool
    {
        $step = Step::getByArtifact($item->getType(), (int) $item->getID());
        $instance = $step?->getInstance();
        if ($step === null || $instance === null || !DefinitionSnapshot::enabled($instance)
            || $step->fields['state'] !== Step::RUNNING || Outcome::forStep($step->getID()) !== null) {
            return false;
        }
        Session::addMessageAfterRedirect('Это этап маршрута: отметьте результат на вкладке «Результат отдела».', false, ERROR);
        return true;
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
        if (self::childAwaitsOutcome($item)) {
            $solution->input = false;
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
        Engine::taskCompleted($task);
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
        Engine::delegatedCompleted($item);
    }

    /** Комментарий инициатора: ответ на уточнение этапа согласования продолжает маршрут (Engine::initiatorAnswered). */
    public static function postFollowupAdd(\ITILFollowup $followup): void
    {
        // Комментарий уже сохранён ядром — исключение маршрута в ядро не выпускаем.
        self::safely('postFollowupAdd', static function () use ($followup): void {
            if (Engine::isInside()) {
                return;
            }
            Engine::initiatorAnswered($followup);
        });
    }

    public static function postValidationUpdate(CommonITILValidation $val): void
    {
        // Ответ согласующего уже сохранён ядром — исключение маршрута в ядро не выпускаем;
        // этап, который не учёл ответ, догонит Engine::catchUpApprovals.
        self::safely('postValidationUpdate', static function () use ($val): void {
            if (Engine::isInside() || !in_array('status', $val->updates ?? [], true)) {
                return;
            }
            // Шаг ищем по привязкам запроса — прежней строке номеров и строкам привязок:
            // на этапе может быть несколько отдельных согласующих.
            $step = Step::ownerOf($val->getType(), $val->getID());
            if ($step === null || $step->fields['state'] !== Step::RUNNING) {
                return;
            }
            // Решение принимает движок: он смотрит все запросы шага и порог этапа.
            // Один ответ не обязательно закрывает этап — при нескольких согласующих
            // ждём, пока порог будет достигнут или станет недостижим.
            Engine::validationUpdated($val);
        });
    }

    /** Обработчик события ядра: любой сбой маршрута — в itilflow.log, не в ядро GLPI (HTTP 500). */
    public static function safely(string $what, callable $handler): void
    {
        try {
            $handler();
        } catch (\Throwable $e) {
            try {
                \Toolbox::logInFile('itilflow', sprintf("Обработчик %s не завершён: %s: %s\n", $what, get_class($e),
                    mb_substr($e->getMessage(), 0, 500)), true, false);
            } catch (\Throwable) {}
        }
    }

    /** Native ответ не превращается в полномочие для другого адресата или старой попытки. */
    public static function preValidationUpdate(CommonITILValidation $val): void
    {
        if (!is_array($val->input) || !array_key_exists('status', $val->input)) {
            return;
        }
        // Владельцы запроса — одной функцией по всем привязкам (Step::ownersOf).
        $bindings = Step::ownersOf($val->getType(), $val->getID());
        if (count($bindings) === 0) {
            return; // штатное согласование, не связанное с маршрутом
        }
        if (count($bindings) !== 1) {
            Violation::record(null, $val->getType(), $val->getID(),
                'action_approval_answer', true, 'Неоднозначная связь согласования.');
            $val->input = false;
            return;
        }
        $step = $bindings[0];
        $instance = $step->getInstance();
        if ($instance !== null && DefinitionSnapshot::enabled($instance)) {
            $stored = new ($val->getType())();
            if (!$stored->getFromDB($val->getID()) || StructuredAccess::validationError($stored, $step) !== null
                || Outcome::forStep($step->getID()) !== null
                || !in_array((int) $val->input['status'], [CommonITILValidation::ACCEPTED, CommonITILValidation::REFUSED], true)) {
                $val->input = false;
            }
            return;
        }
        $stored = new ($val->getType())();
        $stored->getFromDB($val->getID());
        if (Authorizer::validationError($stored, $step) !== null
            || $step->validationRoster() === null
            || (int) ($step->fields['approval_percent_snapshot'] ?? 0) < 1
            || (int) ($step->fields['approval_percent_snapshot'] ?? 0) > 100
            || !in_array((int) $val->input['status'], [CommonITILValidation::ACCEPTED, CommonITILValidation::REFUSED], true)) {
            Violation::record($step->getInstance(), $val->getType(), $val->getID(),
                'action_approval_answer', true, 'Ответ на согласование отклонён.');
            $val->input = false;
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
        // Один путь запуска на форму. Если у формы заявки есть маршрут
        // с «Запускать, если», маршрут выберет обратный вызов формы, правило его не запускает.
        if (FormStart::suppressesRuleStart($item)) {
            RuleStart::consume($item);
            return;
        }
        Engine::startFromRule($item);
    }

    /**
     * Запрос на согласование идущего этапа не удаляется вручную («Удалить навсегда» в ленте,
     * массовое действие, API): без него состав этапа не сходится, и этап не закрыть ни ответом,
     * ни принудительно. Неотвеченные запросы маршрут снимает сам — при отказе, возврате,
     * прерывании и принудительном закрытии. Проверка целостности — в любом режиме контроля.
     */
    public static function prePurgeValidation(CommonITILValidation $val): void
    {
        if (!is_array($val->input)) {
            return;
        }
        if (Engine::dropInProgress()) {
            // Маршрут снимает свои запросы; ядро, убирая опустевшую ступень, удаляет все запросы
            // заявки (ITIL_ValidationStep::cleanDBonPurge). Чужие — не трогаем.
            if (!Engine::isDropping($val)) {
                $val->input = false;
            }
            return;
        }
        if (Engine::isInside()
            || isset(self::$purging[$val::$itemtype . '#' . (int) ($val->fields[$val::$items_id] ?? 0)])) {
            return; // удаляется вся заявка, которой принадлежит запрос
        }
        // Все привязки запроса — одной функцией: прежняя строка номеров, строки привязок
        // согласования в заявке и в заявке отдела, любого вида (создан маршрутом, из формы, принят вручную).
        $steps = Step::ownersOf($val->getType(), (int) $val->getID());
        foreach ($steps as $step) {
            if ($step->fields['state'] !== Step::RUNNING) {
                continue;
            }
            $instance = $step->getInstance();
            Violation::record($instance, $val->getType(), (int) $val->getID(), 'action_validation_purge', true,
                'Удаление запроса на согласование идущего этапа отклонено.');
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                'Запрос нельзя удалить: этап %d «%s» ещё идёт. Если согласование не нужно, '
                . 'администратор маршрутов закроет этап принудительно.',
                (int) $step->fields['ranking'],
                (string) $step->fields['stage_name']
            )), false, ERROR);
            $val->input = false;
            return;
        }
    }

    public static function prePurgeItil(CommonITILObject $item): void
    {
        // Вместе с заявкой удаляются и её запросы на согласование — их защита этому не мешает.
        self::$purging[$item->getType() . '#' . (int) $item->getID()] = true;
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
                Engine::transferred($item);
            }
        }
    }
}
