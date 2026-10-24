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

    /**
     * Видит ли человек заявку этапа после своего действия: основную или, у этапа «Дочерняя заявка»,
     * дочернюю. После «Завершить этап» группа этапа снимается с заявки, после «Вернуть на уточнение»
     * у согласующего пропадает запрос — тогда возвращать его на страницу заявки нельзя («Доступ запрещен»).
     */
    public static function stepItemVisible(Instance $instance, ?Step $step): bool
    {
        $parent = $instance->getItilItem();
        if ($parent !== null && self::itemError($parent, READ) === null) { return true; }
        if ($step === null || $step->isNewItem() || ($step->fields['execution_mode'] ?? '') !== Stage::MODE_DELEGATED
            || (int) ($step->fields['artifact_items_id'] ?? 0) <= 0
            || !is_a((string) ($step->fields['artifact_itemtype'] ?? ''), CommonITILObject::class, true)) {
            return false;
        }
        $child = new ($step->fields['artifact_itemtype'])();
        return $child->getFromDB((int) $step->fields['artifact_items_id']) && self::itemError($child, READ) === null;
    }

    /**
     * Заявка в корзине: маршрут можно посмотреть в центральном интерфейсе, а прервать —
     * только администратору маршрутов. Остальные действия маршрута с ней недоступны.
     * $action — действие маршрута целиком (`read`, `abort`, …); у действий этапа его нет.
     */
    public static function trashError(string $action, bool $trashed, bool $central, bool $administrator): ?string
    {
        if (!$trashed) {
            return null;
        }
        return $central && ($action === 'read' || ($action === 'abort' && $administrator))
            ? null : 'Заявка в корзине. Её маршрут можно только посмотреть, а прервать — администратору маршрутов.';
    }

    private static function itemTrashError(CommonITILObject $item, string $action): ?string
    {
        return self::trashError($action, !empty($item->fields['is_deleted']),
            Session::getCurrentInterface() === 'central', $action === 'abort' && self::isAdministrator());
    }

    public static function itemError(CommonITILObject $item, int $right, string $action = ''): ?string
    {
        if ((int) Session::getLoginUserID() <= 0
            || !CurrentRead::load($item, (int) $item->getID())) {
            return 'Недостаточно прав для действия с этой заявкой.';
        }
        if (($trash = self::itemTrashError($item, $action)) !== null) {
            return $trash;
        }
        if (!CurrentRead::actorsAgree($item)
            || !Session::haveAccessToEntity((int) $item->fields['entities_id'])
            || !$item->can($item->getID(), $right)) {
            return 'Недостаточно прав для действия с этой заявкой.';
        }
        return null;
    }

    /** Связь и состояние проверяются также у событий штатных объектов. */
    public static function contextError(Instance $instance, ?Step $step = null, string $action = ''): ?string
    {
        if (!$instance->getFromDB($instance->getID())) {
            return 'Маршрут недоступен.';
        }
        $item = $instance->getItilItem();
        if ($item === null
            || (int) $instance->fields['entities_id'] !== (int) $item->fields['entities_id']) {
            return 'Маршрут недоступен.';
        }
        if (($trash = self::itemTrashError($item, $step === null ? $action : '')) !== null) {
            return $trash;
        }
        if (DefinitionSnapshot::enabled($instance)
            && (!StructuredAccess::currentRow($instance, ['state', 'current_attempt_id', 'plugin_itilflow_stages_id_current',
                'definition_sha256', 'itemtype', 'items_id', 'entities_id'])
                || !StructuredAccess::currentRow($item, ['status', 'entities_id', 'is_deleted']))) {
            return 'Заявка изменилась. Обновите страницу.';
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
            return 'Этот этап уже не идёт. Обновите страницу.';
        }
        $stage = $step->getStage();
        if ($stage === null || (int) $stage->fields['plugin_itilflow_processes_id']
            !== (int) $instance->fields['plugin_itilflow_processes_id']) {
            return 'Настройка этапа недоступна. Обновите страницу.';
        }
        if (DefinitionSnapshot::enabled($instance)) {
            $attempt = Attempt::current($instance);
            if (DefinitionSnapshot::load($instance) === null || $attempt === null
                || $attempt->fields['state'] !== Attempt::RUNNING
                || (int) $step->fields['attempt_id'] !== (int) $attempt->getID()
                || (int) $step->fields['pass_number'] !== (int) $attempt->fields['number']
                || !empty($step->fields['superseded_by_attempt_id'])) {
                return 'Этап уже изменился. Обновите страницу.';
            }
            if (!StructuredAccess::currentRow($attempt, ['state', 'number', 'plugin_itilflow_instances_id'])
                || !StructuredAccess::currentRow($step, ['state', 'attempt_id', 'superseded_by_attempt_id',
                    'artifact_itemtype', 'artifact_items_id', 'approval_expected_count'])) {
                return 'Этап уже изменился. Обновите страницу.';
            }
        }
        // Несколько текущих шагов — неоднозначное состояние, а не выбор первого.
        // Этапы своего запуска — по индексу запуска, состояние — здесь (1.13.2): условие по state
        // в запросе с блокировкой MySQL 8 мог вести по индексу state и блокировать идущие этапы других заявок.
        $running = array_filter((new Step())->find(['plugin_itilflow_instances_id' => $instance->getID()]),
            static fn(array $row): bool => $row['state'] === Step::RUNNING);
        if (count($running) !== 1 || (int) array_key_first($running) !== (int) $step->getID()) {
            return 'Не удалось понять, какой этап идёт. Сообщите администратору маршрутов.';
        }
        foreach ((new Step())->find([
            'plugin_itilflow_instances_id' => $instance->getID(),
            'plugin_itilflow_stages_id' => (int) $step->fields['plugin_itilflow_stages_id'],
        ]) as $row) {
            if ((int) $row['pass_number'] > (int) $step->fields['pass_number']) {
                return 'Маршрут уже начат заново. Обновите страницу.';
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
        return self::stepRoleError($step, $item, $action, false);
    }

    /** Повтор квитанции не повторяет эффект; полномочия проверяются заново без требования RUNNING. */
    public static function replayStepError(Step $step, string $action): ?string
    {
        if (!$step->getFromDB($step->getID()) || ($instance = $step->getInstance()) === null
            || self::contextError($instance) !== null || ($item = $instance->getItilItem()) === null
            || self::itemError($item, UPDATE) !== null || $step->getStage() === null) {
            return 'У вас нет права на это действие на этом этапе.';
        }
        return self::stepRoleError($step, $item, $action, true);
    }

    private static function stepRoleError(Step $step, CommonITILObject $item, string $action, bool $replay): ?string
    {
        $mode = (string) $step->fields['execution_mode'];
        $admin = self::isAdministrator();
        $owned = $step->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups());
        if (in_array($action, ['complete', 'skip'], true)) {
            if ($mode !== Stage::MODE_INLINE) {
                return $mode === Stage::MODE_APPROVAL
                    ? 'Согласование закрывает только ответ согласующего: «Одобрить» или «Отказать».'
                    : 'Работу отдела закрывает результат в дочерней заявке: «Выполнено» или «Отказ».';
            }
            return $owned ? null : 'Вы не исполнитель этого этапа.';
        }
        if ($action === 'override') {
            $allowed = in_array($mode, [Stage::MODE_APPROVAL, Stage::MODE_INLINE], true)
                ? (self::mayBypass() || $admin)
                : ($mode === Stage::MODE_DELEGATED && self::mayBypass());
            return $allowed ? null : 'Нет права закрыть этот этап принудительно.';
        }
        if ($action === 'set_approver') {
            $fallback = json_decode((string) ($step->fields['approver_fallback'] ?? ''), true);
            $stage = $step->getStage();
            $replacement = ($fallback['waiting'] ?? false) === true
                || $stage === null || !in_array($stage->fields['approver_source'] ?? Stage::APPROVER_FIXED, [Stage::APPROVER_RUNTIME, 'form'], true);
            if ($replacement && !$admin) {
                return 'Недоступного согласующего заменяет администратор маршрутов.';
            }
            return ($replay || $step->awaitsApprover()) && ($admin || self::isAssignee($item))
                ? null : 'Согласующего указывает исполнитель заявки или администратор маршрутов.';
        }
        if ($action === 'reassign') {
            return $mode !== Stage::MODE_APPROVAL && ($owned || $admin)
                ? null : 'Этот этап нельзя передать.';
        }
        return 'Неизвестное действие маршрута.';
    }

    /** Принятие готового запроса не даёт права голосовать от имени его адресата. */
    public static function formAdoptionError(Step $step, bool $answered = false, bool $replay = false): ?string
    {
        $instance = $step->getInstance(); $item = $instance?->getItilItem();
        if (($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'central' || $instance === null || $item === null
            || self::itemError($item, UPDATE) !== null || self::contextError($instance) !== null
            || (!$replay && (self::contextError($instance, $step) !== null || !$step->awaitsExistingRequests()))) {
            return 'Это согласование нельзя засчитать этому этапу.';
        }
        if ($answered && !self::isAdministrator()) { return 'Уже отвеченное согласование засчитывает администратор маршрутов.'; }
        return self::isAdministrator() || self::isAssignee($item) ? null : 'Засчитать согласование может исполнитель заявки.';
    }
    public static function instanceError(Instance $instance, string $action): ?string
    {
        // Заявку в корзине пропускают только «read» и «abort» (trashError), остальное — как раньше.
        if (($error = self::contextError($instance, null, $action)) !== null) {
            return $error;
        }
        $item = $instance->getItilItem();
        $right = in_array($action, ['withdraw', 'read'], true) ? READ : UPDATE;
        if ($item === null || ($error = self::itemError($item, $right, $action)) !== null) {
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
                ? null : 'Отозвать заявку может только её инициатор, пока маршрут идёт.';
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

    public static function replayInstanceError(Instance $instance, string $action): ?string
    {
        if (self::contextError($instance, null, $action) !== null || ($item = $instance->getItilItem()) === null
            || self::itemError($item, $action === 'withdraw' ? READ : UPDATE, $action) !== null) {
            return 'У вас нет права на это действие.';
        }
        if ($action === 'withdraw') {
            $process = $instance->getProcess();
            return (int) ($instance->fields['users_id_requester_start'] ?? 0) > 0
                && (int) $instance->fields['users_id_requester_start'] === (int) Session::getLoginUserID()
                && $process !== null && !empty($process->fields['allow_withdraw'])
                ? null : 'Отозвать заявку может только её инициатор.';
        }
        return in_array($action, ['abort', 'resume', 'halt'], true) && self::isAdministrator()
            ? null : 'Недостаточно прав на управление маршрутом.';
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
            || !CurrentRead::load($validation, (int) $validation->getID())
            || $step->fields['artifact_itemtype'] !== $validation->getType()
            || (int) ($validation->fields[$item::getForeignKeyField()] ?? 0) !== (int) $item->getID()
            || (int) ($validation->fields['entities_id'] ?? -1) !== (int) $item->fields['entities_id']
            || !in_array((int) $validation->getID(), $step->validationIds(), true)
            || !ValidationAuthority::canAnswer($validation)
            || !$validation->can($validation->getID(), UPDATE)) {
            return 'Вы не согласующий по этому запросу.';
        }
        return null;
    }

    /** Человек должен иметь профиль в сущности объекта; профиль не меняется. */
    public static function userFitsItem(int $users_id, CommonITILObject $item): bool
    {
        global $DB;
        $user = new \User();
        if ($users_id <= 0 || !CurrentRead::load($user, $users_id)
            || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
            return false;
        }
        $entity = (int) $item->fields['entities_id'];
        $ancestors = array_map('intval', getAncestorsOf('glpi_entities', $entity));
        foreach (CurrentRead::find(new \Profile_User(), ['users_id' => $users_id]) as $grant) {
            $ge = (int) $grant['entities_id'];
            if ($ge === $entity || (!empty($grant['is_recursive']) && in_array($ge, $ancestors, true))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Предварительная доступность адресата без подмены сессии редактора.
     * Не заменяет native canAnswer/can(UPDATE) при настоящем ответе.
     * Замещение читается по тем же связям и датам, что getTargetCriteriaForUser.
     */
    public static function approvalUserState(int $uid, int $entity, string $itemtype, ?string $now = null): array
    {
        global $DB;
        $validation = match ($itemtype) {
            \Ticket::class => new \TicketValidation(),
            \Change::class => new \ChangeValidation(),
            \Problem::class => method_exists(\Problem::class, 'getValidationClassInstance')
                ? \Problem::getValidationClassInstance() : null,
            default => null,
        };
        if ($validation === null) {
            return ['available' => false, 'active' => false, 'reason_code' => 'unsupported',
                'reason' => 'Для этого типа объекта согласование недоступно.', 'substitutes' => [], 'substitute_warning' => true];
        }
        if ($now === null) {
            $clock = $DB->doQuery('SELECT CURRENT_TIMESTAMP AS current_time_value')->fetch_assoc();
            $now = (string) ($clock['current_time_value'] ?? '');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now)) {
            throw new \RuntimeException('Не удалось определить время для проверки полномочий.');
        }
        $ancestors = array_map('intval', getAncestorsOf('glpi_entities', $entity));
        $load = static function (int $user_id) use ($validation, $entity, $ancestors, $now): array {
            $user = new \User();
            if ($user_id <= 0 || !CurrentRead::load($user, $user_id)) { return ['code' => 'missing', 'user' => []]; }
            $grants = CurrentRead::find(new \Profile_User(), ['users_id' => $user_id]);
            $rights = [];
            foreach ($grants as $grant) {
                $pid = (int) $grant['profiles_id'];
                if (!array_key_exists($pid, $rights)) {
                    $rights[$pid] = 0;
                    foreach (CurrentRead::find(new \ProfileRight(), ['profiles_id' => $pid, 'name' => $validation::$rightname]) as $right) {
                        $rights[$pid] |= (int) $right['rights'];
                    }
                }
            }
            return ['code' => self::approvalAccountReason($user->fields, $grants, $rights,
                $entity, $ancestors, array_merge($validation::getCreateRights(), $validation::getValidateRights()), $now), 'user' => $user->fields];
        };
        $original = $load($uid);
        $substitutes = [];
        // У самого адресата срок замещения, у связи — номер заместителя. Это не поле «имя».
        if ($original['user'] && self::substitutionWindow($original['user'], $now)) {
            foreach (CurrentRead::find(new \ValidatorSubstitute(), ['users_id' => $uid]) as $binding) {
                $candidate = (int) $binding['users_id_substitute'];
                if ($candidate > 0 && $candidate !== $uid && $load($candidate)['code'] === 'available') {
                    $substitutes[$candidate] = $candidate;
                }
            }
        }
        sort($substitutes, SORT_NUMERIC);
        $code = $original['code'];
        $reason = match ($code) {
            'available' => '',
            'missing' => 'Согласующий не найден.',
            'deleted' => 'Согласующий удалён.',
            'inactive' => 'Согласующий неактивен.',
            'outside_period' => 'Срок действия учётной записи согласующего не наступил или истёк.',
            'no_entity' => 'У согласующего нет профиля в организации заявки.',
            default => 'У согласующего нет права согласования в организации заявки.',
        };
        return ['available' => $code === 'available', 'active' => !in_array($code,
            ['missing', 'deleted', 'inactive', 'outside_period'], true), 'reason_code' => $code,
            'reason' => $reason, 'substitutes' => $substitutes, 'substitute_warning' => $substitutes === []];
    }

    /** Чистая часть проверки профиля: право должно принадлежать подходящей организации. */
    public static function approvalAccountReason(array $user, array $grants, array $rights,
        int $entity, array $ancestors, array $validateRights, string $now): string
    {
        if (empty($user['id'])) { return 'missing'; }
        if (!empty($user['is_deleted'])) { return 'deleted'; }
        if (empty($user['is_active'])) { return 'inactive'; }
        if ((!empty($user['begin_date']) && $user['begin_date'] > $now)
            || (!empty($user['end_date']) && $user['end_date'] < $now)) { return 'outside_period'; }
        $entityFits = false;
        foreach ($grants as $grant) {
            $ge = (int) ($grant['entities_id'] ?? -1);
            if ($ge !== $entity && (empty($grant['is_recursive']) || !in_array($ge, $ancestors, true))) { continue; }
            $entityFits = true;
            $mask = (int) ($rights[(int) $grant['profiles_id']] ?? 0);
            foreach ($validateRights as $bit) {
                if ((int) $bit > 0 && ($mask & (int) $bit) !== 0) { return 'available'; }
            }
        }
        return $entityFits ? 'no_validation_right' : 'no_entity';
    }

    /** NULL означает открытую границу; сравнение включительное, как native canAnswer. */
    public static function substitutionWindow(array $user, string $now): bool
    {
        return (!isset($user['substitution_start_date']) || $user['substitution_start_date'] <= $now)
            && (!isset($user['substitution_end_date']) || $user['substitution_end_date'] >= $now);
    }
}
