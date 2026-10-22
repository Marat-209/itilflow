<?php
namespace GlpiPlugin\Itilflow;

/** Единый состав: план без записи, затем фиксированный набор запросов при отправке. */
final class ApprovalRoster
{
    public const LIMIT = 50;
    public const NO_TARGETS = 'Нет доступных согласующих.';

    public static function plan(array $selection, array $facts): array
    {
        $r = ['ok' => false, 'targets' => [], 'excluded' => [], 'errors' => [], 'warnings' => [],
            'count' => 0, 'group_mode' => $selection['mode'] ?? 'any'];
        $users = $selection['users'] ?? null; $groups = $selection['groups'] ?? null;
        foreach ([$users, $groups] as $list) {
            if (!is_array($list) || !array_is_list($list)) { $r['errors'][] = 'Список согласующих повреждён — выберите заново.'; return $r; }
            foreach ($list as $id) { if (!is_int($id) || $id <= 0) { $r['errors'][] = 'Список согласующих повреждён — выберите заново.'; return $r; } }
        }
        if (!in_array($r['group_mode'], ['any', 'each'], true)) { $r['errors'][] = 'Неизвестное значение «Как согласует группа».'; return $r; }
        if ($users && $groups) {
            if (!empty($selection['legacy'])) { $users = []; }
            else { $r['errors'][] = 'Выберите пользователей или группы, не одновременно.'; return $r; }
        }
        $addUser = static function (int $uid, bool $skipInactive) use (&$r, $facts): void {
            $state = $facts['users'][$uid] ?? null;
            $label = '«' . ($state['name'] ?? 'удалён (№ ' . $uid . ')') . '»';
            if (!is_array($state)) { $r['errors'][] = $label . ': не удалось проверить.'; return; }
            if ($skipInactive && ($state['reason_code'] ?? '') === 'inactive') {
                $r['excluded'][] = ['id' => $uid, 'reason' => 'Неактивный участник пропущен.']; return;
            }
            if (!self::respondable($state)) {
                $r['errors'][] = $label . ': ' . ($state['reason'] ?? 'нет права согласовать.'); return;
            }
            $r['targets']['User:' . $uid] = ['itemtype' => 'User', 'id' => $uid];
            if (!empty($state['substitute_warning'])) { $r['warnings'][] = $label . ': нет заместителя.'; }
        };
        foreach (array_unique($users) as $uid) { $addUser($uid, false); }
        foreach (array_unique($groups) as $gid) {
            $group = $facts['groups'][$gid] ?? null;
            $label = 'Группа «' . ($group['name'] ?? 'удалена (№ ' . $gid . ')') . '»';
            if (!is_array($group) || ($group['available'] ?? false) !== true || !is_array($group['members'] ?? null)) {
                $r['errors'][] = $label . ' не видна в организации заявки.'; continue;
            }
            $available = false;
            foreach (array_unique($group['members']) as $uid) {
                if (!is_int($uid) || $uid <= 0) { $r['errors'][] = $label . ': неверный состав.'; continue; }
                $state = $facts['users'][$uid] ?? [];
                if (self::respondable($state)) { $available = true; }
                if ($r['group_mode'] === 'each') { $addUser($uid, true); }
            }
            if ($r['group_mode'] === 'any') {
                if (!$available) { $r['errors'][] = $label . ': нет участника с правом ответить или действующего заместителя.'; }
                else { $r['targets']['Group:' . $gid] = ['itemtype' => 'Group', 'id' => $gid]; }
            }
        }
        ksort($r['targets']); $r['targets'] = array_values($r['targets']);
        $r['count'] = count($r['targets']);
        if ($r['count'] === 0) { $r['errors'][] = self::NO_TARGETS; }
        if ($r['count'] > self::LIMIT) { $r['errors'][] = 'Не более 50 запросов на этап; сейчас ' . $r['count'] . '.'; }
        $r['errors'] = array_values(array_unique($r['errors']));
        $r['warnings'] = $r['warnings'] ? [implode(' ', array_unique($r['warnings']))] : [];
        $r['ok'] = $r['errors'] === [];
        return $r;
    }

    /** Штатный ответ может дать заместитель активного адресата; выключенный адресат не оживает. */
    private static function respondable(array $state): bool
    {
        return ($state['available'] ?? false) === true
            || (($state['active'] ?? false) === true
                && in_array($state['reason_code'] ?? '', ['no_validation_right'], true)
                && !empty($state['substitutes']));
    }
    public static function resolve(array $selection, \CommonITILObject $item): array
    {
        return self::read($selection, (int) $item->fields['entities_id'], $item->getType());
    }

    public static function previewForStage(array $stage, \CommonITILObject $item): array
    {
        return self::previewForEntity($stage, (int) $item->fields['entities_id'], $item->getType());
    }

    public static function previewForEntity(array $stage, int $entity, string $itemtype): array
    {
        $selection = DefinitionSnapshot::approvers($stage);
        return self::read($selection ?? [], $entity, $itemtype);
    }

    private static function read(array $selection, int $entity, string $itemtype): array
    {
        $facts = ['users' => [], 'groups' => []];
        $users = $selection['users'] ?? [];
        $ancestors = array_map('intval', getAncestorsOf('glpi_entities', $entity));
        foreach ($selection['groups'] ?? [] as $gid) {
            $g = new \Group();
            $available = CurrentRead::load($g, (int) $gid)
                && ((int) $g->fields['entities_id'] === $entity || (!empty($g->fields['is_recursive'])
                    && in_array((int) $g->fields['entities_id'], $ancestors, true)));
            $members = [];
            if ($available) {
                foreach (CurrentRead::find(new \Group_User(), ['groups_id' => (int) $gid]) as $row) {
                    $members[] = (int) $row['users_id'];
                }
            }
            $facts['groups'][$gid] = ['available' => $available, 'members' => array_values(array_unique($members)),
                'name' => ConditionText::name('Group', (int) $gid)];
            $users = array_merge($users, $members);
        }
        foreach (array_unique($users) as $uid) {
            $facts['users'][$uid] = Authorizer::approvalUserState((int) $uid, $entity, $itemtype);
            $facts['users'][$uid]['name'] = ConditionText::name('User', (int) $uid);
        }
        return self::plan($selection, $facts);
    }

    public static function threshold(int $total, int $percent, bool $form = false): array
    {
        if ($total < 1 || $total > self::LIMIT || $percent < ($form ? 0 : 1) || $percent > 100) {
            return ['needed' => 0, 'can_stall' => false];
        }
        $stall = false;
        for ($a = 0; $a <= $total; $a++) {
            if (ValidationLadder::status($a, $total - $a, 0, $percent) === 2) { $stall = true; break; }
        }
        return ['needed' => self::needed($total, $percent), 'can_stall' => $stall];
    }
    /** Минимум одобрений по тем же операциям с дробями, что использует GLPI. */
    private static function needed(int $total, int $percent): int
    {
        for ($accepted = 1; $accepted <= $total; $accepted++) {
            if (ValidationLadder::status($accepted, 0, $total - $accepted, $percent) === \CommonITILValidation::ACCEPTED) {
                return $accepted;
            }
        }
        return $total;
    }
    /** Знаменатель — снимок отправки, снятые голоса не превращаются в согласия. */
    public static function progress(array $counts, int $percent, bool $form = false): array
    {
        $a = max(0, (int) ($counts['accepted'] ?? 0)); $r = max(0, (int) ($counts['refused'] ?? 0));
        $w = max(0, (int) ($counts['waiting'] ?? 0)); $d = max(0, (int) ($counts['dropped'] ?? 0));
        $total = max(0, (int) ($counts['total'] ?? ($a + $r + $w + $d)));
        $valid = $total > 0 && $a + $r + $w + $d === $total && $percent >= ($form ? 0 : 1) && $percent <= 100;
        $status = $valid ? ValidationLadder::status($a, $r, $w, $percent) : null;
        return ['accepted' => $a, 'refused' => $r, 'waiting' => $w, 'dropped' => $d, 'total' => $total,
            'needed' => $valid ? self::needed($total, $percent) : 0,
            'native_status' => $status, 'stalled' => $valid && $w === 0 && $d === 0 && $status === 2,
            'frozen' => true, 'errors' => $valid ? [] : ['Не удалось проверить список согласующих.']];
    }

    public static function forStep(Step $step): array
    {
        $rows = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
            ? StructuredAccess::roster($step) : $step->validationRoster(false);
        $dropped = self::dropped($step->fields['approval_dropped'] ?? null);
        $counts = ['accepted' => 0, 'refused' => 0, 'waiting' => 0, 'dropped' => count($dropped),
            'total' => (int) ($step->fields['approval_expected_count'] ?? 0)];
        $targets = [];
        // Снятые записи читаются отдельно: validationRoster намеренно не считает отсутствие нормой.
        if ($rows === null && $dropped) {
            $rows = [];
            $bindings = OutcomeValidation::forStep($step);
            $boundIds = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
                ? array_map('intval', array_keys($bindings)) : $step->validationIds();
            foreach ($dropped as $id => $record) {
                $binding = $bindings[$id] ?? null;
                $type = $binding['validation_itemtype'] ?? (string) $step->fields['artifact_itemtype'];
                if (!in_array($id, $boundIds, true) || !in_array($type, ['TicketValidation', 'ChangeValidation'], true)
                    || ($binding !== null && (!in_array(OutcomeValidation::kindOf($binding), [OutcomeValidation::KIND_CREATED, OutcomeValidation::KIND_FORM], true)
                        || $binding['itemtype_target'] !== $record['itemtype_target']
                        || (int) $binding['items_id_target'] !== (int) $record['items_id_target']))) {
                    $rows = null; break;
                }
                $v = new $type();
                if (CurrentRead::load($v, $id)) { $rows = null; break; }
            }
            foreach ($rows === null ? [] : $boundIds as $id) {
                if (isset($dropped[$id])) { continue; }
                $bindings = OutcomeValidation::forStep($step);
                $binding = $bindings[$id] ?? null;
                $type = $binding['validation_itemtype'] ?? (string) $step->fields['artifact_itemtype'];
                if (!in_array($type, ['TicketValidation', 'ChangeValidation'], true)) { $rows = null; break; }
                $item = $step->fields['execution_mode'] === Stage::MODE_DELEGATED
                    ? StructuredAccess::child($step) : $step->getInstance()?->getItilItem();
                $v = new $type();
                if ($item === null || !CurrentRead::load($v, $id)
                    || (int) ($v->fields[$item::getForeignKeyField()] ?? 0) !== (int) $item->getID()
                    || (int) ($v->fields['entities_id'] ?? -1) !== (int) $item->fields['entities_id']
                    || (Step::ownersMap($type, [$id])[$id] ?? []) !== [(int) $step->getID()]
                    || ($binding !== null && ((int) $binding['attempt_id'] !== (int) $step->fields['attempt_id']
                        || $binding['itemtype_target'] !== $v->fields['itemtype_target']
                        || (int) $binding['items_id_target'] !== (int) $v->fields['items_id_target']
                        || (int) $binding['validation_step_id'] !== (int) $v->fields['itils_validationsteps_id']))) {
                    $rows = null; break;
                }                $rows[$id] = $v->fields;
            }
        }
        foreach ($rows ?? [] as $row) {
            $key = [2 => 'waiting', 3 => 'accepted', 4 => 'refused'][(int) $row['status']] ?? null;
            if ($key !== null) { $counts[$key]++; }
            $targets[] = ['itemtype' => $row['itemtype_target'], 'id' => (int) $row['items_id_target']];
        }
        foreach ($dropped as $row) { $targets[] = ['itemtype' => $row['itemtype_target'], 'id' => (int) $row['items_id_target']]; }
        $out = self::progress($counts, (int) ($step->fields['approval_percent_snapshot'] ?? 0), $step->hasExternalBindings());
        if ($rows === null) {
            $out['errors'][] = 'Запросы на согласование изменили вне маршрута.';
            $out['native_status'] = null; $out['stalled'] = false;
        }
        return $out + ['targets' => $targets];
    }

    public static function dropped(mixed $json): array
    {
        if (!is_string($json) || $json === '') { return []; }
        $rows = json_decode($json, true);
        if (!is_array($rows)) { return []; }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0 || !in_array($row['itemtype_target'] ?? '', ['User', 'Group'], true)) { return []; }
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }
}
