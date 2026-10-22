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
 * Форма ищется только по UUID; назначение и ступень требуют явного положительного выбора.
 * Сам класс GLPI не подходит: он не различает «нет» и «несколько», не знает номеров
 * и при неизвестной ссылке бросает исключение. Пользователь ищется среди активных, с профилем
 * в активных организациях.
 */
final class TransferMapper
{
    /** Тип ссылки => поле, по которому объект ищется в другой базе. */
    public const FORM = 'Glpi\\Form\\Form';
    public const QUESTION = 'Glpi\\Form\\Question';
    public const DESTINATION = 'Glpi\\Form\\Destination\\FormDestination';

    public const TYPES = [
        'Entity'       => 'completename',
        'Group'        => 'completename',
        'ITILCategory' => 'completename',
        'Calendar'     => 'name',
        'SLA'          => 'name',
        'User'         => 'name',
        self::FORM => 'uuid',
        self::QUESTION => 'uuid',
        self::DESTINATION => 'name',
        'ValidationStep' => 'name',
    ];

    public const FOUND    = 'found';    // по полному имени (имени) — ровно один
    public const BY_ID    = 'by_id';    // по номеру исходной базы, имя то же
    public const REPLACED = 'replaced'; // выбран администратором; номер 0 — оставлен пустым
    public const MISSING  = 'missing';  // не найден
    public const SEVERAL  = 'several';  // найдено несколько
    public const CHOOSE   = 'choose';   // назначение формы, этап согласования: выбор есть, нужен явный выбор

    /** Сколько найденных показывать списком; больше — выбор из общего списка GLPI. */
    public const CANDIDATES = 20;

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
        return $this->cache[hash('sha256', json_encode($req, JSON_THROW_ON_ERROR))] ??= $this->lookup($req);
    }

    public static function mandatory(string $type): bool
    {
        return in_array($type, [self::FORM, self::DESTINATION, self::QUESTION, 'ValidationStep'], true);
    }

    private function lookup(array $req): array
    {
        $type = $req['itemtype'];
        $key = self::key($type, (int) $req['id']);
        if ($type === self::QUESTION) {
            $parent = $this->resolve(['itemtype' => self::FORM, 'id' => $req['forms_forms_id'], 'name' => '', 'uuid' => $req['form_uuid']]);
            $catalog = $parent['id'] > 0 ? FormFacts::questionCatalog($parent['id']) : [];
            $chosen = $this->replacements[$key] ?? null;
            $candidates = []; $match = null;
            foreach ($catalog as $q) {
                if (!($q['supported'] ?? false)) { continue; }
                $candidates[$q['id']] = $q['label'] . ' (' . $q['id'] . ')';
                if (($chosen !== null && $chosen === $q['id']) || ($chosen === null && $q['uuid'] === $req['uuid'])) {
                    if (FormFacts::validateMapping($req['descriptor'], $q, $chosen !== null ? [$req['uuid'] => $q['uuid']] : []) === []) { $match = $q; }
                }
            }
            return ['state' => $match === null ? self::MISSING : ($chosen === null ? self::FOUND : self::REPLACED),
                'id' => $match['id'] ?? 0, 'candidates' => $candidates];
        }
        if ($type === self::DESTINATION) {
            $parent = $this->resolve(['itemtype' => self::FORM, 'id' => $req['forms_forms_id'], 'name' => '', 'uuid' => $req['form_uuid']]);
            $rows = $parent['id'] > 0 ? $this->find($type, ['forms_forms_id' => $parent['id']]) : [];
            return self::explicitChoice($this->replacements[$key] ?? 0, $rows);
        }
        if ($type === 'ValidationStep') {
            // Служебные этапы согласования плагина — и проходов этапов, и общая запись до 1.11 —
            // назначению формы не принадлежат: в списке выбора их нет.
            $rows = array_filter($this->find($type, []), static fn(array $r): bool => !ValidationLadder::isService((string) $r['name']));
            return self::explicitChoice($this->replacements[$key] ?? 0, $rows);
        }
        if (array_key_exists($key, $this->replacements)) {
            $id = $this->replacements[$key];
            // У организации пустого значения нет: нуль — корневая организация.
            if (($id === 0 && $type !== 'Entity' && !self::mandatory($type)) || ($id > 0 || $type === 'Entity') && $this->visible($type, $id)) {
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
        // Формы: UUID или явная замена после native import/clone, никогда номер/имя.
        if ($type === self::FORM) {
            return ['state' => $rows === [] ? self::MISSING : self::SEVERAL, 'id' => 0,
                'candidates' => array_map(static fn(array $r): string => $r['label'], $rows)];
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

    /**
     * Назначение формы и этап согласования сами не сопоставляются — только явный выбор. Пока он
     * не сделан, а выбирать есть из чего, это «Выберите из списка», а не «Не найдено в этой базе»:
     * в той же базе объект есть.
     *
     * @param array<int,array{label:string}> $rows
     * @return array{state:string,id:int,candidates:array<int,string>}
     */
    public static function explicitChoice(int $chosen, array $rows): array
    {
        $candidates = array_map(static fn(array $r): string => (string) $r['label'], $rows);
        if ($chosen > 0 && isset($rows[$chosen])) {
            return ['state' => self::REPLACED, 'id' => $chosen, 'candidates' => $candidates];
        }
        return ['state' => $rows === [] ? self::MISSING : self::CHOOSE, 'id' => 0, 'candidates' => $candidates];
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
        if ($type === self::FORM) { $criteria[$table . '.is_deleted'] = 0; }
        if ($type === 'User') {
            // У пользователя организации нет (isEntityAssign — false): видимость — профиль
            // в активных организациях, как в списке пользователей GLPI; удалённые и выключенные — нет.
            $criteria[$table . '.is_deleted'] = 0;
            $criteria[$table . '.is_active'] = 1;
            $criteria[] = [$table . '.id' => new \Glpi\DBAL\QuerySubQuery([
                'SELECT' => 'users_id',
                'FROM'   => 'glpi_profiles_users',
                'WHERE'  => getEntitiesRestrictCriteria('glpi_profiles_users', '', $this->entities, true),
            ])];
        }
        // Назначение формы своей организации не хранит: в GLPI оно дочернее к форме и наследует от неё
        // isEntityAssign(), но колонки entities_id в таблице нет. Его видимость — через форму, которую
        // сопоставили выше с ограничением по организациям; своё ограничение дало бы ошибку SQL 1054.
        $in_entity = !in_array($type, ['Entity', self::DESTINATION], true) && $item->isEntityAssign();
        if ($type === 'Entity') {
            $criteria[] = getEntitiesRestrictCriteria($table, '', $this->entities, false);
        } elseif ($in_entity) {
            $criteria[] = getEntitiesRestrictCriteria($table, '', $this->entities, $item->maybeRecursive());
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => array_merge([$table . '.id', $table . '.name'], $tree ? [$table . '.completename'] : [],
                $in_entity ? [$table . '.entities_id'] : []),
            'FROM'   => $table,
            'WHERE'  => $criteria,
            'ORDER'  => $table . '.id',
            'LIMIT'  => in_array($type, [self::DESTINATION, 'ValidationStep'], true) ? 10000 : self::CANDIDATES,
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
