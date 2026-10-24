<?php

namespace GlpiPlugin\Itilflow;

/** Текущее чтение внутри собственного уровня: RR read-view не обновляется от lock. */
final class CurrentRead
{
    public static function load(\CommonDBTM $object, int $id): bool
    {
        if (!TransactionScope::hasActive()) { return $object->getFromDB($id); }
        // Прочитан в команде — при её повторе после отказа из-за блокировок перечитается (Engine::retrying).
        Engine::touch($object);
        $rows = self::find($object, ['id' => $id], [], 1);
        if (count($rows) !== 1) { return false; }
        $object->fields = reset($rows);
        $object->post_getFromDB();
        return true;
    }

    /** Только простые равенства/IN, используемые внутренними моделями; не язык поиска API. */
    public static function find(\CommonDBTM $object, array $where = [], array $order = [], ?int $limit = null): array
    {
        global $DB;
        if (!TransactionScope::hasActive()) { return $object->find($where, $order, $limit); }
        $parts = [];
        foreach ($where as $field => $value) {
            if (!is_string($field) || !preg_match('/^[a-z][a-z0-9_]*$/D', $field)) {
                throw new \LogicException('Неподдержанное условие текущего чтения.');
            }
            $column = $DB->quoteName($field);
            if (is_array($value)) {
                if (!$value) { return []; }
                $values = array_map(self::value(...), $value);
                $parts[] = $column . ' IN (' . implode(',', $values) . ')';
            } else {
                $parts[] = $column . ($value === null ? ' IS NULL' : '=' . self::value($value));
            }
        }
        $sort = [];
        foreach ($order as $entry) {
            if (!preg_match('/^([a-z][a-z0-9_]*)(?: (ASC|DESC))?$/D', $entry, $match)) {
                throw new \LogicException('Неподдержанная сортировка текущего чтения.');
            }
            $sort[] = $DB->quoteName($match[1]) . ' ' . ($match[2] ?? 'ASC');
        }
        $sql = 'SELECT * FROM ' . $DB->quoteName($object::getTable())
            . ($parts ? ' WHERE ' . implode(' AND ', $parts) : '')
            . ' ORDER BY ' . implode(',', $sort ?: [$DB->quoteName('id') . ' ASC'])
            . ($limit !== null && $limit > 0 ? ' LIMIT ' . $limit : '') . self::lockClause($object);
        $result = $DB->doQuery($sql);
        $rows = [];
        while ($row = $DB->fetchAssoc($result)) { $rows[(int) $row['id']] = $row; }
        return $rows;
    }

    /**
     * Справочники GLPI (люди, группы, профили, замещения, правила), которые команда маршрута только
     * читает, — общей блокировкой: она тоже даёт текущие данные, но две команды с общим согласующим
     * или группой не ждут друг друга и не встают во взаимную блокировку (MySQL 8). Так же —
     * маршрут и его этапы (1.13.2): команды по разным заявкам одного маршрута их только
     * читают; правка маршрута берёт исключительную блокировку строки маршрута сама (DefinitionMutation)
     * и ждёт, пока читающие команды закончат. Запуски, этапы запусков, заявки и запросы согласования —
     * по-прежнему исключительной: их команда меняет.
     */
    public const SHARED = [\User::class, \Group::class, \Group_User::class, \Profile_User::class, \ProfileRight::class,
        \Profile::class, \Entity::class, \ValidatorSubstitute::class, \Rule::class, \RuleAction::class, \RuleCriteria::class,
        \ITILValidationTemplate_Target::class, Process::class, Stage::class];

    /** Окончание запроса текущего чтения: общая (справочники) или исключительная блокировка строк. */
    public static function lockClause(\CommonDBTM|string $object): string
    {
        foreach (self::SHARED as $class) {
            if (is_a($object, $class, true)) { return ' LOCK IN SHARE MODE'; }
        }
        return ' FOR UPDATE';
    }

    private static function value($value): string
    {
        global $DB;
        if (!is_int($value) && !is_string($value) && !is_bool($value) && $value !== null) {
            throw new \LogicException('Неподдержанное значение текущего чтения.');
        }
        return (string) $DB->quoteValue($value);
    }

    /** Native ACL может лениво читать actors старым read-view: расхождение закрывает действие. */
    public static function actorsAgree(\CommonITILObject $item): bool
    {
        if (!TransactionScope::hasActive()) { return true; }
        foreach ([\User::class => 'users', \Group::class => 'groups', \Supplier::class => 'suppliers'] as $type => $property) {
            $actor = $item->getActorObjectForItem($type);
            $current = [];
            foreach (self::find($actor, [$actor::getItilObjectForeignKey() => $item->getID()], ['id ASC']) as $row) {
                $current[$row['type']][] = $row;
            }
            // Оба массива — сырые строки одного native адаптера; никакого присвоения private cache.
            if ($current != $item->$property) { return false; }
        }
        return true;
    }
}
