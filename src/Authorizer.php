<?php

namespace GlpiPlugin\Itilflow;

use CommonITILActor;
use CommonITILObject;
use CommonITILValidation;
use Session;

/** Единый допуск действий маршрута. Проверки не зависят от режима контроля. */
final class Authorizer
{
    public static function isAdministrator(): bool
    {
        return (bool) Session::haveRight('plugin_itilflow_process', UPDATE);
    }

    public static function mayBypass(): bool
    {
        return (bool) Session::haveRight('plugin_itilflow_bypass', READ);
    }

    public static function itemError(CommonITILObject $item, int $right): ?string
    {
        if ((int) Session::getLoginUserID() <= 0
            || !$item->getFromDB($item->getID())
            || !empty($item->fields['is_deleted'])
            || !Session::haveAccessToEntity((int) $item->fields['entities_id'])
            || !$item->can($item->getID(), $right)) {
            return 'Недостаточно прав для действия с этой заявкой.';
        }
        return null;
    }

    /** Связь и состояние проверяются также у событий штатных объектов. */
    public static function contextError(Instance $instance, ?Step $step = null): ?string
    {
        if (!$instance->getFromDB($instance->getID())) {
            return 'Маршрут недоступен.';
        }
        $item = $instance->getItilItem();
        if ($item === null || !empty($item->fields['is_deleted'])
            || (int) $instance->fields['entities_id'] !== (int) $item->fields['entities_id']) {
            return 'Маршрут недоступен.';
        }
        if ($step === null) {
            return null;
        }
        if (in_array((int) $item->fields['status'],
            array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true)) {
            return 'Заявка уже решена или закрыта.';
        }
        if (!$step->getFromDB($step->getID())
            || (int) $step->fields['plugin_itilflow_instances_id'] !== (int) $instance->getID()
            || $instance->fields['state'] !== Instance::RUNNING
            || $step->fields['state'] !== Step::RUNNING
            || (int) $instance->fields['plugin_itilflow_stages_id_current']
                !== (int) $step->fields['plugin_itilflow_stages_id']) {
            return 'Действие не относится к текущему этапу.';
        }
        $stage = $step->getStage();
        if ($stage === null || (int) $stage->fields['plugin_itilflow_processes_id']
            !== (int) $instance->fields['plugin_itilflow_processes_id']) {
            return 'Определение этапа недоступно.';
        }
        // Несколько текущих шагов — неоднозначное состояние, а не выбор первого.
        $running = (new Step())->find([
            'plugin_itilflow_instances_id' => $instance->getID(),
            'state' => Step::RUNNING,
        ]);
        if (count($running) !== 1 || (int) array_key_first($running) !== (int) $step->getID()) {
            return 'Текущий этап не определён однозначно.';
        }
        foreach ((new Step())->find([
            'plugin_itilflow_instances_id' => $instance->getID(),
            'plugin_itilflow_stages_id' => (int) $step->fields['plugin_itilflow_stages_id'],
        ]) as $row) {
            if ((int) $row['pass_number'] > (int) $step->fields['pass_number']) {
                return 'Эта попытка уже заменена новой.';
            }
        }
        return null;
    }

    public static function isAssignee(CommonITILObject $item): bool
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return false;
        }
        if ($item->isUser(CommonITILActor::ASSIGN, $uid)) {
            return true;
        }
        foreach (Engine::myGroups() as $gid) {
            if ($item->isGroup(CommonITILActor::ASSIGN, $gid)) {
                return true;
            }
        }
        return false;
    }

    /** Допуск роли; отчёт и причина проверяются движком отдельно. */
    public static function stepError(Step $step, string $action): ?string
    {
        $instance = $step->getInstance();
        if ($instance === null) {
            return 'Маршрут недоступен.';
        }
        $error = self::contextError($instance, $step);
        $item = $instance->getItilItem();
        if ($error !== null || $item === null) {
            return $error ?? 'Маршрут недоступен.';
        }
        if (($error = self::itemError($item, UPDATE)) !== null) {
            return $error;
        }
        $mode = (string) $step->fields['execution_mode'];
        $admin = self::isAdministrator();
        $owned = $step->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups());
        if (in_array($action, ['complete', 'skip'], true)) {
            if ($mode !== Stage::MODE_INLINE) {
                return 'Этот этап завершается штатным результатом связанного действия.';
            }
            return $owned ? null : 'Текущий исполнитель этапа не подтверждён.';
        }
        if ($action === 'override') {
            $allowed = in_array($mode, [Stage::MODE_APPROVAL, Stage::MODE_INLINE], true)
                ? (self::mayBypass() || $admin)
                : ($mode === Stage::MODE_DELEGATED && self::mayBypass());
            return $allowed ? null : 'Нет права на принудительное завершение этого этапа.';
        }
        if ($action === 'set_approver') {
            return $step->awaitsApprover() && ($admin || self::isAssignee($item))
                ? null : 'Согласующего указывает исполнитель заявки или администратор процессов.';
        }
        if ($action === 'reassign') {
            return $mode !== Stage::MODE_APPROVAL && ($owned || $admin)
                ? null : 'Этот этап нельзя передать выбранным действием.';
        }
        return 'Неизвестное действие маршрута.';
    }

    public static function instanceError(Instance $instance, string $action): ?string
    {
        if (($error = self::contextError($instance)) !== null) {
            return $error;
        }
        $item = $instance->getItilItem();
        $right = in_array($action, ['withdraw', 'read'], true) ? READ : UPDATE;
        if ($item === null || ($error = self::itemError($item, $right)) !== null) {
            return $error ?? 'Маршрут недоступен.';
        }
        if ($action === 'read') {
            return null;
        }
        if (in_array((int) $item->fields['status'],
            array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true)) {
            return 'Заявка уже решена или закрыта.';
        }
        if ($action === 'withdraw') {
            $process = $instance->getProcess();
            $uid = (int) ($instance->fields['users_id_requester_start'] ?? 0);
            return $uid > 0 && $uid === (int) Session::getLoginUserID()
                && $process !== null && !empty($process->fields['allow_withdraw'])
                && in_array($instance->fields['state'], [Instance::RUNNING, Instance::HALTED], true)
                ? null : 'Отзыв недоступен: не подтверждён исходный инициатор или состояние маршрута.';
        }
        if (!self::isAdministrator()) {
            return 'Недостаточно прав на управление маршрутом.';
        }
        $states = match ($action) {
            'abort', 'reopen' => [Instance::RUNNING, Instance::HALTED],
            'resume' => [Instance::HALTED],
            'halt' => [Instance::RUNNING],
            default => [],
        };
        return in_array($instance->fields['state'], $states, true)
            ? null : 'Действие недоступно в этом состоянии маршрута.';
    }

    public static function processFitsItem(Process $process, CommonITILObject $item): bool
    {
        if (!$process->fields['is_active'] || $process->fields['itemtype'] !== $item->getType()
            || !empty($item->fields['is_deleted'])
            || in_array((int) $item->fields['status'],
                array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray()), true)) {
            return false;
        }
        $pe = (int) $process->fields['entities_id'];
        $ie = (int) $item->fields['entities_id'];
        return $pe === $ie || (!empty($process->fields['is_recursive'])
            && in_array($pe, array_map('intval', getAncestorsOf('glpi_entities', $ie)), true));
    }

    public static function startError(CommonITILObject $item, int $processes_id): ?string
    {
        if (($error = self::itemError($item, UPDATE)) !== null) {
            return $error;
        }
        $process = new Process();
        if (!self::isAdministrator() || !$process->getFromDB($processes_id)
            || !self::processFitsItem($process, $item)
            || !$process->can($processes_id, READ)) {
            return 'Недостаточно прав на запуск этого маршрута.';
        }
        return null;
    }

    /** Штатные canAnswer учитывают адресата, группы и срок замещения. */
    public static function validationError(CommonITILValidation $validation, Step $step): ?string
    {
        $instance = $step->getInstance();
        if ($instance === null || ($error = self::contextError($instance, $step)) !== null) {
            return $error ?? 'Маршрут недоступен.';
        }
        $item = $instance->getItilItem();
        if ($item === null || ($error = self::itemError($item, READ)) !== null) {
            return $error ?? 'Заявка недоступна.';
        }
        if ($step->fields['execution_mode'] !== Stage::MODE_APPROVAL
            || !$validation->getFromDB($validation->getID())
            || $step->fields['artifact_itemtype'] !== $validation->getType()
            || (int) ($validation->fields[$item::getForeignKeyField()] ?? 0) !== (int) $item->getID()
            || (int) ($validation->fields['entities_id'] ?? -1) !== (int) $item->fields['entities_id']
            || !in_array((int) $validation->getID(), $step->validationIds(), true)
            || !$validation->canAnswer()
            || !$validation->can($validation->getID(), UPDATE)) {
            return 'Нет полномочия ответить на это согласование.';
        }
        return null;
    }

    /** Человек должен иметь профиль в сущности объекта; профиль не меняется. */
    public static function userFitsItem(int $users_id, CommonITILObject $item): bool
    {
        global $DB;
        $user = new \User();
        if ($users_id <= 0 || !$user->getFromDB($users_id)
            || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
            return false;
        }
        $entity = (int) $item->fields['entities_id'];
        $ancestors = array_map('intval', getAncestorsOf('glpi_entities', $entity));
        foreach ($DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $users_id]]) as $grant) {
            $ge = (int) $grant['entities_id'];
            if ($ge === $entity || (!empty($grant['is_recursive']) && in_array($ge, $ancestors, true))) {
                return true;
            }
        }
        return false;
    }
}
