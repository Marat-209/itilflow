<?php

namespace GlpiPlugin\Itilflow;

/** Снимок только разрешённых полей определения; отсутствие снимка не угадывается. */
final class DefinitionSnapshot
{
    public const MODE = 'structured_v1';
    private const PROCESS_FIELDS = ['id', 'name', 'entities_id', 'is_recursive', 'is_active',
        'itemtype', 'enforcement', 'version', 'block_resolution', 'public_log', 'helpdesk_tab',
        'allow_withdraw', 'calendars_id', 'outcome_contract'];
    private const STAGE_FIELDS = ['id', 'plugin_itilflow_processes_id', 'name', 'ranking',
        'execution_mode', 'entity_strategy', 'entities_id_target', 'groups_id', 'users_id',
        'itilcategories_id', 'slas_id', 'duration', 'is_optional', 'completion_comment',
        'deadline_minutes', 'approval_percent', 'approver_source', 'ticket_status',
        'on_reject', 'plugin_itilflow_stages_id_reject', 'content', 'department_result_source',
        'negative_policy', 'return_targets'];

    public static function enabled(Instance $instance): bool
    {
        return ($instance->fields['outcome_contract'] ?? 'legacy') === self::MODE;
    }

    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Вызывается внутри транзакции запуска; блокируется и диапазон этапов. */
    public static function capture(Process $process): array
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
        while ($row = $DB->fetchAssoc($result)) {
            $stages[] = array_intersect_key($row, array_flip(self::STAGE_FIELDS));
        }
        $snapshot = ['schema' => 1, 'process' => array_intersect_key($p, array_flip(self::PROCESS_FIELDS)),
            'stages' => $stages];
        if (!self::valid($snapshot)) {
            throw new \RuntimeException('Заполните источник исхода, политику отказа и точки возврата новой версии.');
        }
        return $snapshot;
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

    public static function valid(array $snapshot): bool
    {
        if (($snapshot['schema'] ?? null) !== 1 || !is_array($snapshot['process'] ?? null)
            || !is_array($snapshot['stages'] ?? null) || !count($snapshot['stages'])) {
            return false;
        }
        $p = $snapshot['process'];
        foreach (self::PROCESS_FIELDS as $field) {
            if (!array_key_exists($field, $p)) { return false; }
        }
        if (($p['outcome_contract'] ?? '') !== self::MODE || (int) ($p['id'] ?? 0) <= 0
            || !in_array($p['itemtype'] ?? '', ['Ticket', 'Change', 'Problem'], true)) {
            return false;
        }
        $ranks = [];
        $ids = [];
        foreach ($snapshot['stages'] as $s) {
            if (!is_array($s)) { return false; }
            foreach (self::STAGE_FIELDS as $field) {
                if (!array_key_exists($field, $s)) { return false; }
            }
            if (!is_array($s) || (int) ($s['id'] ?? 0) <= 0
                || (int) ($s['plugin_itilflow_processes_id'] ?? 0) !== (int) $p['id']
                || (int) ($s['ranking'] ?? 0) <= 0 || isset($ranks[(int) $s['ranking']])
                || isset($ids[(int) $s['id']])
                || !in_array($s['execution_mode'] ?? '', [Stage::MODE_INLINE, Stage::MODE_APPROVAL, Stage::MODE_DELEGATED], true)
                || !in_array($s['negative_policy'] ?? '', ['block', 'abort'], true)
                || self::targets($s['return_targets'] ?? null) === null) {
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
            foreach (self::targets($s['return_targets']) as $id) {
                if (!isset($ids[$id]) || $ids[$id] > (int) $s['ranking']) {
                    return false;
                }
            }
        }
        return true;
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
            && $value['process']['itemtype'] === $instance->fields['itemtype'] ? $value : null;
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
