<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use RuleAction;
use RuleCommonITILObject;

/** Одноразовая связь native PRE_ITEM_ADD -> RULE_MATCHED -> ITEM_ADD, только в RAM. */
final class RuleStart
{
    private static array $pending = [];

    private static function signature(string $type, array $input): ?string
    {
        if (!in_array($type, ['Ticket', 'Change', 'Problem'], true)
            || !is_string($input['name'] ?? null) || !is_string($input['content'] ?? null)
            || !isset($input['entities_id'], $input['_plugin_itilflow_processes_id'])) {
            return null;
        }
        return hash('sha256', json_encode([$type, (int) $input['entities_id'],
            (int) $input['_plugin_itilflow_processes_id'], $input['name'], $input['content']], JSON_THROW_ON_ERROR));
    }

    /** Параметры задаёт Rule::process после успешных критериев и действий. */
    public static function matched(array $params): void
    {
        $classes = ['RuleTicket' => 'Ticket', 'RuleChange' => 'Change', 'RuleProblem' => 'Problem'];
        $class = $params['sub_type'] ?? '';
        $input = $params['output'] ?? null;
        if (!isset($classes[$class]) || !is_array($input) || !empty($input['id'])) {
            return;
        }
        $pid = (int) ($input['_plugin_itilflow_processes_id'] ?? 0);
        // Только правило внутри prepareInputForAdd того самого native объекта.
        // Аргументы стека и содержимое заявки не сохраняются и не журналируются.
        $item = null;
        $native_rule = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $object = $frame['object'] ?? null;
            if ($object instanceof \Rule && ($frame['function'] ?? '') === 'process'
                && get_class($object) === $class
                && (int) $object->getID() === (int) ($params['ruleid'] ?? 0)) {
                $native_rule = true;
            }
            if ($object instanceof CommonITILObject && ($frame['function'] ?? '') === 'prepareInputForAdd') {
                $item = $object;
                break;
            }
        }
        if (!$native_rule || $item === null || $item->getType() !== $classes[$class]
            || (self::$pending[spl_object_id($item)]['object'] ?? null) !== $item) {
            return;
        }
        $rule = new $class();
        if ($pid <= 0 || !$rule->getFromDB((int) ($params['ruleid'] ?? 0))
            || empty($rule->fields['is_active'])
            || (((int) $rule->fields['condition'] & RuleCommonITILObject::ONADD) === 0)) {
            return;
        }
        // Совпадение иного правила с унаследованным входным флагом не даёт полномочия.
        $actions = (new RuleAction())->find(['rules_id' => $rule->getID(),
            'field' => '_plugin_itilflow_processes_id', 'action_type' => 'assign']);
        $assigned = false;
        foreach ($actions as $action) {
            $assigned = $assigned || (int) $action['value'] === $pid;
        }
        $signature = self::signature($classes[$class], $input);
        if ($assigned && $signature !== null) {
            self::$pending[spl_object_id($item)] = ['object' => $item,
                'signature' => $signature, 'process' => $pid, 'rule' => (int) $rule->getID(),
                'rule_name' => (string) ($rule->fields['name'] ?? '')];
        }
    }

    /** PRE_ITEM_ADD предшествует правилам; новый add всегда стирает прежнее свидетельство. */
    public static function beforeAdd(CommonITILObject $item): void
    {
        $oid = spl_object_id($item);
        unset(self::$pending[$oid]);
        if (!is_array($item->input)) {
            return;
        }
        self::$pending[$oid] = ['object' => $item];
    }

    /** Название именно сработавшего правила; чтение не расходует одноразовое свидетельство. */
    public static function ruleName(CommonITILObject $item): ?string
    {
        $proof = self::$pending[spl_object_id($item)] ?? null;
        if ($proof === null || ($proof['object'] ?? null) !== $item || !is_array($item->input)
            || !isset($proof['signature'])
            || self::signature($item->getType(), $item->input) !== $proof['signature']) {
            return null;
        }
        $name = $proof['rule_name'] ?? null;
        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    public static function consume(CommonITILObject $item): ?int
    {
        $oid = spl_object_id($item);
        $proof = self::$pending[$oid] ?? null;
        unset(self::$pending[$oid]);
        if ($proof === null || !isset($proof['signature'], $proof['process'])
            || $proof['object'] !== $item || !is_array($item->input)
            || self::signature($item->getType(), $item->input) !== $proof['signature']) {
            return null;
        }
        $persisted = new ($item->getType())();
        if (!$persisted->getFromDB($item->getID()) || !empty($persisted->fields['is_deleted'])) {
            return null;
        }
        $fields = $persisted->fields;
        $fields['_plugin_itilflow_processes_id'] = $proof['process'];
        if (self::signature($item->getType(), $fields) !== $proof['signature']) {
            return null;
        }
        return $proof['process'];
    }
}
