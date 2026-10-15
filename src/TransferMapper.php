<?php

namespace GlpiPlugin\Itilflow;

/**
 * Сопоставление ссылок файла переноса с объектами этой базы.
 *
 * Поиск — как у штатного импорта форм GLPI 11 (Glpi\Form\Export\Context\DatabaseMapper):
 * древовидный справочник (организация, группа, категория) — по полному имени, остальное —
 * по имени (у пользователя это логин), в пределах активных организаций; ровно один
 * найденный объект — сопоставлен. Сверх того, как требует перенос маршрутов:
 * - не найдено или найдено несколько — берётся объект с тем же номером, если имя то же;
 * - «не найдено» и «найдено несколько» различаются, найденные перечисляются для выбора;
 * - замену от администратора можно оставить пустой (0); замена принимается, только если
 *   объект есть и виден в активных организациях.
 * Сам класс GLPI не подходит: он не различает «нет» и «несколько», не знает номеров
 * и при неизвестной ссылке бросает исключение. Удалённые пользователи не ищутся.
 */
final class TransferMapper
{
    /** Тип ссылки => поле, по которому объект ищется в другой базе. */
    public const TYPES = [
        'Entity'       => 'completename',
        'Group'        => 'completename',
        'ITILCategory' => 'completename',
        'Calendar'     => 'name',
        'SLA'          => 'name',
        'User'         => 'name',
    ];

    public const FOUND    = 'found';    // по полному имени (имени) — ровно один
    public const BY_ID    = 'by_id';    // по номеру исходной базы, имя то же
    public const REPLACED = 'replaced'; // выбран администратором; номер 0 — оставлен пустым
    public const MISSING  = 'missing';  // не найден
    public const SEVERAL  = 'several';  // найдено несколько

    private const CANDIDATES = 20;

    /** @var list<int> */
    private array $entities;
    /** @var array<string,int> «Тип#номер в файле» => номер в этой базе */
    private array $replacements = [];
    /** @var array<string,array{state:string,id:int,candidates:array<int,string>}> */
    private array $cache = [];

    /** @param array<int|string> $entities активные организации того, кто импортирует */
    public function __construct(array $entities)
    {
        $entities = array_values(array_unique(array_map('intval', $entities)));
        if ($entities === []) {
            throw new \InvalidArgumentException('Нужна хотя бы одна организация.');
        }
        $this->entities = $entities;
    }

    public static function key(string $itemtype, int $id): string
    {
        return $itemtype . '#' . $id;
    }

    /** Замена от администратора. Неизвестный тип и отрицательные номера не принимаются. */
    public function addReplacement(string $itemtype, int $original_id, int $replacement_id): void
    {
        if (!isset(self::TYPES[$itemtype]) || $original_id < 0 || $replacement_id < 0) {
            return;
        }
        $this->replacements[self::key($itemtype, $original_id)] = $replacement_id;
        $this->cache = [];
    }

    /**
     * Принятые замены — для скрытых полей следующего шага.
     *
     * @return list<array{itemtype:string,original_id:int,replacement_id:int}>
     */
    public function getReplacements(): array
    {
        $out = [];
        foreach ($this->replacements as $key => $id) {
            [$type, $original] = explode('#', $key, 2);
            $out[] = ['itemtype' => $type, 'original_id' => (int) $original, 'replacement_id' => $id];
        }
        return $out;
    }

    /**
     * @param array{itemtype:string,id:int,name:string,completename?:string} $req требование из файла
     * @return array{state:string,id:int,candidates:array<int,string>}
     */
    public function resolve(array $req): array
    {
        return $this->cache[self::key($req['itemtype'], (int) $req['id'])] ??= $this->lookup($req);
    }

    private function lookup(array $req): array
    {
        $type = $req['itemtype'];
        $key = self::key($type, (int) $req['id']);
        if (array_key_exists($key, $this->replacements)) {
            $id = $this->replacements[$key];
            // У организации пустого значения нет: нуль — корневая организация.
            if (($id === 0 && $type !== 'Entity') || $this->visible($type, $id)) {
                return ['state' => self::REPLACED, 'id' => $id, 'candidates' => []];
            }
            // Несуществующая или невидимая замена не принимается: ищем как без неё.
        }
        $field = self::TYPES[$type];
        $lookup = (string) ($req[$field] ?? '');
        $rows = $lookup === '' ? [] : $this->find($type, [$field => $lookup]);
        if (count($rows) === 1) {
            return ['state' => self::FOUND, 'id' => (int) array_key_first($rows), 'candidates' => []];
        }
        // Номер исходной базы — только при том же имени.
        $same = $this->find($type, ['id' => (int) $req['id']]);
        $row = $same[(int) $req['id']] ?? null;
        if ($row !== null && (string) $req['name'] !== '' && $row['name'] === (string) $req['name']) {
            return ['state' => self::BY_ID, 'id' => (int) $req['id'], 'candidates' => []];
        }
        if ($rows === []) {
            return ['state' => self::MISSING, 'id' => 0, 'candidates' => []];
        }
        return ['state' => self::SEVERAL, 'id' => 0, 'candidates' => array_map(static fn(array $r): string => $r['label'], $rows)];
    }

    /** Объект есть и виден в активных организациях. */
    public function visible(string $type, int $id): bool
    {
        return isset(self::TYPES[$type]) && $id >= 0 && $this->find($type, ['id' => $id]) !== [];
    }

    /**
     * Объекты типа по условию, видимые в активных организациях.
     *
     * @return array<int,array{name:string,label:string}>
     */
    private function find(string $type, array $where): array
    {
        global $DB;
        $item = getItemForItemtype($type);
        if (!$item instanceof \CommonDBTM) {
            return [];
        }
        $table = $item::getTable();
        $tree = self::TYPES[$type] === 'completename';
        $criteria = [];
        foreach ($where as $field => $value) {
            $criteria[$table . '.' . $field] = $value;
        }
        if ($type === 'User') {
            $criteria[$table . '.is_deleted'] = 0;
        }
        if ($type === 'Entity') {
            $criteria[] = getEntitiesRestrictCriteria($table, '', $this->entities, false);
        } elseif ($item->isEntityAssign()) {
            $criteria[] = getEntitiesRestrictCriteria($table, '', $this->entities, $item->maybeRecursive());
        }
        $in_entity = $type !== 'Entity' && $item->isEntityAssign();
        $out = [];
        foreach ($DB->request([
            'SELECT' => array_merge([$table . '.id', $table . '.name'], $tree ? [$table . '.completename'] : [],
                $in_entity ? [$table . '.entities_id'] : []),
            'FROM'   => $table,
            'WHERE'  => $criteria,
            'ORDER'  => $table . '.id',
            'LIMIT'  => self::CANDIDATES,
        ]) as $row) {
            $label = (string) ($tree ? $row['completename'] : $row['name']) . ' (' . (int) $row['id'] . ')';
            // Найденные несколько различает организация (у пользователя — ФИО).
            $extra = match (true) {
                $type === 'User' => trim((string) getUserName((int) $row['id'])),
                $in_entity => \Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                default => '',
            };
            if ($extra !== '' && $extra !== (string) $row['name']) {
                $label .= ' — ' . $extra;
            }
            $out[(int) $row['id']] = ['name' => (string) $row['name'], 'label' => $label];
        }
        return $out;
    }
}
