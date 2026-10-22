<?php

namespace GlpiPlugin\Itilflow;

/**
 * Снимок только разрешённых полей определения; отсутствие снимка не угадывается.
 *
 * Схема снимка растёт по выпускам, у каждой версии — свой список полей:
 * - схема 1 (1.9–1.10) — поля маршрута и этапов до 1.11;
 * - схема 2 (1.11) — плюс раздел «Форма» маршрута, шаг согласования формы у этапа,
 *   списки согласующих и то, как отвечает группа; номер запуска на заявке.
 * Снимок проверяется по списку своей версии: недостающее поле — снимок недействителен,
 * лишнее допускается. Снимок схемы 1 читается с умолчаниями: «формы нет», согласующие —
 * прежние «группа / сотрудник» как список из одного, группа отвечает «любой участник»,
 * запуск первый. Идущие заявки при этом не меняются: снимок в базе не переписывается.
 */
final class DefinitionSnapshot
{
    public const MODE = 'structured_v1';

    /** Схема, которую пишет этот выпуск. */
    public const SCHEMA = 3;

    /** Как отвечает группа-согласующий: «любой участник» — один запрос группе, «каждый участник» — каждому. */
    public const GROUP_ANY = 'any';
    public const GROUP_EACH = 'each';

    /** Поля маршрута, добавленные в каждой версии схемы. */
    private const PROCESS_FIELDS = [
        1 => ['id', 'name', 'entities_id', 'is_recursive', 'is_active',
            'itemtype', 'enforcement', 'version', 'block_resolution', 'public_log', 'helpdesk_tab',
            'allow_withdraw', 'calendars_id', 'outcome_contract'],
        2 => ['forms_forms_id', 'forms_destinations_id'],
        3 => [],
    ];

    /** Поля этапа, добавленные в каждой версии схемы. */
    private const STAGE_FIELDS = [
        1 => ['id', 'plugin_itilflow_processes_id', 'name', 'ranking',
            'execution_mode', 'entity_strategy', 'entities_id_target', 'groups_id', 'users_id',
            'itilcategories_id', 'slas_id', 'duration', 'is_optional', 'completion_comment',
            'deadline_minutes', 'approval_percent', 'approver_source', 'ticket_status',
            'on_reject', 'plugin_itilflow_stages_id_reject', 'content', 'department_result_source',
            'negative_policy', 'return_targets'],
        2 => ['form_validationsteps_id', 'approver_users', 'approver_groups', 'approver_group_mode'],
        3 => ['run_if', 'decision_items', 'unknown_policy'],
    ];

    /** Значения полей схемы 2 у снимка схемы 1: так вёл себя маршрут до 1.11. */
    private const PROCESS_DEFAULTS = ['forms_forms_id' => 0, 'forms_destinations_id' => 0];
    private const STAGE_DEFAULTS = ['form_validationsteps_id' => 0, 'approver_users' => null,
        'approver_groups' => null, 'approver_group_mode' => self::GROUP_ANY];

    public static function enabled(Instance $instance): bool
    {
        return ($instance->fields['outcome_contract'] ?? 'legacy') === self::MODE;
    }

    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Поля маршрута снимка версии $schema — все, накопленные до неё. */
    public static function processFields(int $schema): array
    {
        return self::fieldsUpTo(self::PROCESS_FIELDS, $schema);
    }

    /** Поля этапа снимка версии $schema — все, накопленные до неё. */
    public static function stageFields(int $schema): array
    {
        return self::fieldsUpTo(self::STAGE_FIELDS, $schema);
    }

    private static function fieldsUpTo(array $by_version, int $schema): array
    {
        if (!isset($by_version[$schema])) {
            return [];
        }
        $out = [];
        foreach ($by_version as $version => $fields) {
            if ($version <= $schema) {
                array_push($out, ...$fields);
            }
        }
        return $out;
    }

    /**
     * Вызывается внутри транзакции запуска; блокируется и диапазон этапов.
     *
     * @param int $run номер запуска маршрута на этой заявке (см. ValidationLadder::nextRun)
     */
    public static function capture(Process $process, int $run = 1): array
    {
        global $DB;
        TransactionScope::requireActive();
        $result = $DB->doQuery('SELECT * FROM ' . $DB->quoteName(Process::getTable())
            . ' WHERE id=' . (int) $process->getID() . ' FOR UPDATE');
        if ((int) $DB->numrows($result) !== 1) {
            throw new \RuntimeException('Определение маршрута недоступно.');
        }
        $p = $DB->fetchAssoc($result);
        $result = $DB->doQuery('SELECT * FROM ' . $DB->quoteName(Stage::getTable())
            . ' WHERE plugin_itilflow_processes_id=' . (int) $process->getID()
            . ' ORDER BY ranking,id FOR UPDATE');
        $stages = [];
        $stage_fields = array_flip(self::stageFields(self::SCHEMA));
        while ($row = $DB->fetchAssoc($result)) {
            $stages[] = array_intersect_key($row, $stage_fields);
        }
        $snapshot = self::draft(array_intersect_key($p, array_flip(self::processFields(self::SCHEMA))), $stages, $run);
        if (!self::valid($snapshot)) {
            throw new \RuntimeException('Заполните источник исхода, политику отказа и точки возврата новой версии.');
        }
        return $snapshot;
    }

    /** Снимок текущей схемы из строк маршрута и этапов — для проверки до запуска (строка готовности, перенос). */
    public static function draft(array $process, array $stages, int $run = 1): array
    {
        foreach ($stages as &$stage) {
            $stage += ['run_if' => null, 'decision_items' => null, 'unknown_policy' => 'run'];
        }
        unset($stage);
        return ['schema' => self::SCHEMA, 'run' => $run, 'process' => $process, 'stages' => $stages];
    }

    public static function targets($value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            $ids = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($ids) || !array_is_list($ids)) {
            return null;
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                return null;
            }
        }
        return count(array_unique($ids)) === count($ids) ? $ids : null;
    }

    /**
     * Список номеров из поля списка (согласующие сотрудники или группы).
     *
     * @return list<int>|null|false null — поле не заполнено (прежняя запись), false — записано неверно
     */
    public static function idList($value): array|null|false
    {
        if ($value === null) {
            return null;
        }
        $ids = self::targets($value);
        return $ids === null ? false : $ids;
    }

    /**
     * Согласующие этапа «Указан в этапе»: списки сотрудников и групп и то, как отвечает группа.
     *
     * Пока списки не заполнены (NULL — этап настроен до 1.11 и с тех пор не сохранён человеком),
     * прежние «группа / сотрудник» читаются как список из одного. Строка этапа при этом
     * не переписывается.
     *
     * @return array{users:list<int>,groups:list<int>,mode:string,legacy:bool}|null null — записано неверно
     */
    public static function approvers(array $stage): ?array
    {
        $users = self::idList($stage['approver_users'] ?? null);
        $groups = self::idList($stage['approver_groups'] ?? null);
        $mode = $stage['approver_group_mode'] ?? self::GROUP_ANY;
        if ($users === false || $groups === false || !in_array($mode, [self::GROUP_ANY, self::GROUP_EACH], true)) {
            return null;
        }
        $legacy = $users === null && $groups === null;
        if ($legacy) {
            $users = (int) ($stage['users_id'] ?? 0) > 0 ? [(int) $stage['users_id']] : [];
            $groups = (int) ($stage['groups_id'] ?? 0) > 0 ? [(int) $stage['groups_id']] : [];
        }
        return ['users' => $users ?? [], 'groups' => $groups ?? [], 'mode' => $mode, 'legacy' => $legacy];
    }

    /** Целое неотрицательное: из базы (число) или из снимка (число после JSON). */
    private static function count0($value): bool
    {
        return (is_int($value) && $value >= 0)
            || (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) === 1);
    }

    public static function valid(array $snapshot): bool
    {
        $schema = $snapshot['schema'] ?? null;
        if (!is_int($schema) || $schema < 1 || $schema > self::SCHEMA || !is_array($snapshot['process'] ?? null)
            || !is_array($snapshot['stages'] ?? null) || !count($snapshot['stages'])) {
            return false;
        }
        if ($schema >= 2 && (!is_int($snapshot['run'] ?? null) || $snapshot['run'] < 1)) {
            return false;
        }
        $p = $snapshot['process'];
        foreach (self::processFields($schema) as $field) {
            if (!array_key_exists($field, $p)) { return false; }
        }
        if (($p['outcome_contract'] ?? '') !== self::MODE || (int) ($p['id'] ?? 0) <= 0
            || !in_array($p['itemtype'] ?? '', ['Ticket', 'Change', 'Problem'], true)) {
            return false;
        }
        if ($schema >= 2 && (!self::count0($p['forms_forms_id']) || !self::count0($p['forms_destinations_id']))) {
            return false;
        }
        $stage_fields = self::stageFields($schema);
        $ranks = [];
        $ids = [];
        foreach ($snapshot['stages'] as $s) {
            if (!is_array($s)) { return false; }
            foreach ($stage_fields as $field) {
                if (!array_key_exists($field, $s)) { return false; }
            }
            if ((int) ($s['id'] ?? 0) <= 0
                || (int) ($s['plugin_itilflow_processes_id'] ?? 0) !== (int) $p['id']
                || (int) ($s['ranking'] ?? 0) <= 0 || isset($ranks[(int) $s['ranking']])
                || isset($ids[(int) $s['id']])
                || !in_array($s['execution_mode'] ?? '', [Stage::MODE_INLINE, Stage::MODE_APPROVAL, Stage::MODE_DELEGATED], true)
                || !in_array($s['negative_policy'] ?? '', ['block', 'abort'], true)
                || self::targets($s['return_targets'] ?? null) === null) {
                return false;
            }
            // Списки согласующих читает только согласование; у других режимов они не значат ничего.
            if (!in_array($s['approver_source'] ?? '', [Stage::APPROVER_FIXED, Stage::APPROVER_RUNTIME, Stage::APPROVER_FORM], true)
                || (($s['approver_source'] ?? '') === Stage::APPROVER_FORM
                    && ($schema < 2 || $p['itemtype'] !== 'Ticket' || $s['execution_mode'] !== Stage::MODE_APPROVAL))) { return false; }
            if ($schema >= 2 && (!self::count0($s['form_validationsteps_id'])
                    || ($s['execution_mode'] === Stage::MODE_APPROVAL && self::approvers($s) === null))) {
                return false;
            }
            if (($s['execution_mode'] === Stage::MODE_APPROVAL
                    || ($s['execution_mode'] === Stage::MODE_DELEGATED
                        && ($s['department_result_source'] ?? '') === 'native_validation'))
                && ((int) ($s['approval_percent'] ?? 0) < 1 || (int) $s['approval_percent'] > 100)) {
                return false;
            }
            if ($s['execution_mode'] === Stage::MODE_DELEGATED
                && !in_array($s['department_result_source'] ?? '', ['assignee_action', 'native_validation'], true)) {
                return false;
            }
            if ($s['execution_mode'] === Stage::MODE_DELEGATED
                && (int) ($s['groups_id'] ?? 0) <= 0 && (int) ($s['users_id'] ?? 0) <= 0) {
                return false;
            }
            $ids[(int) $s['id']] = (int) $s['ranking'];
            $ranks[(int) $s['ranking']] = true;
        }
        foreach ($snapshot['stages'] as $s) {
            if ($schema >= 3 && BranchConfiguration::validateSnapshotStage($s, $snapshot['stages'], $p) !== []) {
                return false;
            }
            foreach (self::targets($s['return_targets']) as $id) {
                if (!isset($ids[$id]) || $ids[$id] > (int) $s['ranking']) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Снимок любой поддерживаемой схемы — в виде текущей: недостающие поля старой схемы
     * получают значения, с которыми маршрут шёл до их появления. Номер схемы остаётся
     * прежним: по нему видно, что запуск начат до 1.11 (например, общая ступень согласования).
     */
    public static function normalize(array $snapshot): array
    {
        if (($snapshot['schema'] ?? 0) < 3) {
            foreach ($snapshot['stages'] as $i => $stage) {
                // Даже лишние поля старого снимка не включают новую семантику.
                $snapshot['stages'][$i] = array_replace($stage, ['run_if' => null, 'decision_items' => null, 'unknown_policy' => 'run']);
            }
        }
        if (($snapshot['schema'] ?? null) >= 2) { return $snapshot; }
        $snapshot['run'] = 1;
        $snapshot['process'] += self::PROCESS_DEFAULTS;
        foreach ($snapshot['stages'] as $i => $stage) {
            $snapshot['stages'][$i] = $stage + self::STAGE_DEFAULTS;
        }
        return $snapshot;
    }

    public static function load(Instance $instance): ?array
    {
        $raw = (string) ($instance->fields['definition_snapshot'] ?? '');
        if (!self::enabled($instance) || $raw === ''
            || !hash_equals((string) ($instance->fields['definition_sha256'] ?? ''), hash('sha256', $raw))) {
            return null;
        }
        try {
            $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($value) && self::valid($value)
            && (int) $value['process']['id'] === (int) $instance->fields['plugin_itilflow_processes_id']
            && (int) $value['process']['version'] === (int) $instance->fields['process_version']
            && $value['process']['itemtype'] === $instance->fields['itemtype'] ? self::normalize($value) : null;
    }

    /** Версия схемы снимка экземпляра: 0 — снимка нет или он недействителен. */
    public static function schemaOf(Instance $instance): int
    {
        $snapshot = self::load($instance);
        return $snapshot === null ? 0 : (int) $snapshot['schema'];
    }

    /** Номер запуска маршрута на заявке: у снимков до 1.11 — первый. */
    public static function runOf(Instance $instance): int
    {
        $snapshot = self::load($instance);
        return $snapshot === null ? 1 : (int) $snapshot['run'];
    }

    public static function stage(Instance $instance, int $id): ?Stage
    {
        $snapshot = self::load($instance);
        if ($snapshot === null) {
            return null;
        }
        foreach ($snapshot['stages'] as $row) {
            if ((int) $row['id'] === $id) {
                $stage = new Stage();
                $stage->fields = $row;
                return $stage;
            }
        }
        return null;
    }

    public static function process(Instance $instance): ?Process
    {
        $snapshot = self::load($instance);
        if ($snapshot === null) {
            return null;
        }
        $process = new Process();
        $process->fields = $snapshot['process'];
        return $process;
    }
}
