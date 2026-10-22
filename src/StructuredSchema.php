<?php

namespace GlpiPlugin\Itilflow;

/**
 * Добавочная схема явных исходов и попыток и поля 1.11. История legacy не преобразуется в новые исходы.
 *
 * Порядок при установке и обновлении: недостающие таблицы создаются целиком (prepare),
 * недостающие поля только добавляются (queue — addField и addKey, без UPDATE: строки
 * маршрутов, этапов и запусков не переписываются), миграция выполняется, и лишь потом
 * схема проверяется строго (verify). Сначала колонка, потом проверка: иначе обновление
 * с прошлой версии остановила бы проверка таблицы, в которой новой колонки ещё нет.
 */
final class StructuredSchema
{
    /** Таблицы нового порядка исходов, которых нет, — целиком; затем очередь добавочных полей. */
    public static function prepare(\Migration $migration): void
    {
        global $DB;
        foreach (self::tables() as $suffix => $fields) {
            $table = 'glpi_plugin_itilflow_' . $suffix;
            if (!$DB->tableExists($table)) {
                $DB->doQuery('CREATE TABLE ' . $DB->quoteName($table) . ' (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),' . $fields . '
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            }
        }
        self::queue($migration);
    }

    /**
     * Добавочные поля и ключи — только в очередь миграции, без выполнения: addField добавляет
     * поле, которого нет, и ничего не делает, если оно есть. Повтор безопасен.
     */
    public static function queue(\Migration $migration): void
    {
        $prefix = 'glpi_plugin_itilflow_';
        $migration->addField($prefix . 'processes', 'outcome_contract', 'string', ['value' => 'legacy']);
        foreach (['department_result_source', 'negative_policy'] as $name) {
            $migration->addField($prefix . 'stages', $name, 'string', ['value' => '']);
        }
        $migration->addField($prefix . 'stages', 'return_targets', 'LONGTEXT NULL');
        $migration->addField($prefix . 'instances', 'outcome_contract', 'string', ['value' => 'legacy']);
        $migration->addField($prefix . 'instances', 'definition_snapshot', 'LONGTEXT NULL');
        $migration->addField($prefix . 'instances', 'definition_sha256', 'VARCHAR(64) NULL');
        $migration->addField($prefix . 'instances', 'current_attempt_id', 'INT UNSIGNED NULL DEFAULT NULL');
        $migration->addField($prefix . 'steps', 'attempt_id', 'INT UNSIGNED NULL DEFAULT NULL');
        $migration->addField($prefix . 'steps', 'artifact_entities_id', 'INT UNSIGNED NULL DEFAULT NULL');
        $migration->addField($prefix . 'steps', 'superseded_by_attempt_id', 'INT UNSIGNED NULL DEFAULT NULL');
        $migration->addKey($prefix . 'steps', ['attempt_id', 'plugin_itilflow_stages_id'], 'attempt_stage', 'UNIQUE');

        // 1.11.0. Значения по умолчанию — поведение до 1.11: формы нет, списки согласующих
        // не заполнены (NULL — прежние «группа / сотрудник» как список из одного), группа
        // отвечает «любой участник», привязка запроса — «создан маршрутом».
        foreach (self::ADDED_1_11 as $suffix => $fields) {
            foreach ($fields as $field => $definition) {
                $migration->addField($prefix . $suffix, $field, $definition);
            }
        }
        foreach (self::ADDED_1_12 as $suffix => $fields) {
            foreach ($fields as $field => $definition) {
                $migration->addField($prefix . $suffix, $field, $definition);
            }
        }
        foreach (self::ADDED_1_13 as $suffix => $fields) {
            foreach ($fields as $field => $definition) {
                $migration->addField($prefix . $suffix, $field, $definition);
            }
        }
    }

    /** Поля 1.11: таблица => поле => определение. Проверка — те же определения (verify). */
    private const ADDED_1_11 = [
        'processes' => [
            'forms_forms_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'forms_destinations_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ],
        'stages' => [
            'form_validationsteps_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'approver_users' => 'LONGTEXT NULL',
            'approver_groups' => 'LONGTEXT NULL',
            'approver_group_mode' => "VARCHAR(8) NOT NULL DEFAULT 'any'",
        ],
        'steps' => [
            'approval_dropped' => 'TEXT NULL',
            'approver_fallback' => 'TEXT NULL',
        ],
        'instances' => [
            'form_source' => 'LONGTEXT NULL',
        ],
        'outcomevalidations' => [
            'binding_kind' => "VARCHAR(16) NOT NULL DEFAULT 'created'",
        ],
    ];

    /** Только добавление: определения и исторические запуски не переписываются. */
    private const ADDED_1_12 = [
        'stages' => ['run_if' => 'LONGTEXT NULL', 'decision_items' => 'LONGTEXT NULL',
            'unknown_policy' => "VARCHAR(16) NOT NULL DEFAULT 'run'"],
        'steps' => ['decision' => 'TEXT NULL', 'condition_note' => 'TEXT NULL'],
        'instances' => ['form_facts' => 'LONGTEXT NULL'],
    ];

    /**
     * 1.13 — запуск маршрута по ответу формы. Только добавление, строки не переписываются:
     * - processes.start_if — «Запускать, если» (формат — StartRule). NULL — по форме не
     *   запускать: маршрут запускает бизнес-правило, как до 1.13;
     * - instances.start_source — чем начат этот запуск (формат — FormStart::source*).
     *   NULL — запуск до 1.13, правилом без записи или вручную.
     * Снимок определения (DefinitionSnapshot::SCHEMA = 3) не растёт: start_if нужен только до
     * запуска, а фактическое назначение запуска хранится в start_source. Поэтому откат на 1.12
     * не ломает запуски, начатые в 1.13.
     */
    private const ADDED_1_13 = [
        'processes' => ['start_if' => 'LONGTEXT NULL'],
        'instances' => ['start_source' => 'LONGTEXT NULL'],
    ];

    private static function tables(): array
    {
        return [
            'attempts' => "
                `plugin_itilflow_instances_id` INT UNSIGNED NOT NULL,
                `number` INT UNSIGNED NOT NULL,
                `previous_attempt_id` INT UNSIGNED NULL,
                `source_step_id` INT UNSIGNED NULL,
                `target_stage_id` INT UNSIGNED NOT NULL,
                `preserved_before_rank` INT NOT NULL DEFAULT 0,
                `state` VARCHAR(24) NOT NULL,
                `reason` TEXT NULL,
                `subject_summary` LONGTEXT NOT NULL,
                `source_followups_id` INT UNSIGNED NULL,
                `users_id` INT UNSIGNED NOT NULL,
                `date_start` TIMESTAMP NULL DEFAULT NULL,
                `date_end` TIMESTAMP NULL DEFAULT NULL,
                `command_key` CHAR(64) NOT NULL,
                UNIQUE KEY `attempt_number` (`plugin_itilflow_instances_id`,`number`),
                UNIQUE KEY `command` (`plugin_itilflow_instances_id`,`command_key`)",
            'outcomes' => "
                `plugin_itilflow_instances_id` INT UNSIGNED NOT NULL,
                `plugin_itilflow_steps_id` INT UNSIGNED NOT NULL,
                `attempt_id` INT UNSIGNED NOT NULL,
                `artifact_itemtype` VARCHAR(100) NOT NULL,
                `artifact_items_id` INT UNSIGNED NOT NULL,
                `outcome` VARCHAR(24) NOT NULL,
                `source_kind` VARCHAR(24) NOT NULL,
                `source_itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `source_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `source_digest` CHAR(64) NOT NULL,
                `users_id` INT UNSIGNED NOT NULL,
                `reason` TEXT NULL,
                `public_summary` TEXT NULL,
                `public_followups_id` INT UNSIGNED NULL,
                `explanation_pending` TINYINT NOT NULL DEFAULT 0,
                `command_key` CHAR(64) NOT NULL,
                UNIQUE KEY `step` (`plugin_itilflow_steps_id`),
                UNIQUE KEY `command` (`plugin_itilflow_instances_id`,`command_key`),
                KEY `attempt` (`attempt_id`)",
            // Привязка запроса на согласование к шагу маршрута: одна строка на запрос (уникальный
            // ключ по запросу). С 1.11 — и для согласования в самой заявке; binding_kind — откуда
            // запрос у шага: created — создан маршрутом, form — отправлен формой и доказан при
            // подаче, manual — принят вручную. Снимает маршрут только неотвеченные created.
            'outcomevalidations' => "
                `plugin_itilflow_steps_id` INT UNSIGNED NOT NULL,
                `attempt_id` INT UNSIGNED NOT NULL,
                `validation_itemtype` VARCHAR(100) NOT NULL,
                `validations_id` INT UNSIGNED NOT NULL,
                `artifact_itemtype` VARCHAR(100) NOT NULL,
                `artifact_items_id` INT UNSIGNED NOT NULL,
                `entities_id` INT UNSIGNED NOT NULL,
                `itemtype_target` VARCHAR(100) NOT NULL,
                `items_id_target` INT UNSIGNED NOT NULL,
                `validation_step_id` INT UNSIGNED NOT NULL,
                `binding_kind` VARCHAR(16) NOT NULL DEFAULT 'created',
                UNIQUE KEY `validation` (`validation_itemtype`,`validations_id`),
                KEY `step` (`plugin_itilflow_steps_id`,`attempt_id`)",
            'commandreceipts' => "
                `plugin_itilflow_instances_id` INT UNSIGNED NOT NULL,
                `command_key` CHAR(64) NOT NULL,
                `action` VARCHAR(40) NOT NULL,
                `users_id` INT UNSIGNED NOT NULL,
                `payload_digest` CHAR(64) NOT NULL,
                `result_json` LONGTEXT NOT NULL,
                UNIQUE KEY `command` (`plugin_itilflow_instances_id`,`command_key`)",
        ];
    }

    /**
     * Ожидаемые колонки по определению: имя => [тип, Null, Default], как их показывает
     * SHOW COLUMNS (INT(10) — как INT). Значение по умолчанию — NULL, 0, '' или слово в кавычках.
     *
     * @return array<string,array{0:string,1:string,2:?string}>
     */
    public static function expectedColumns(string $definition): array
    {
        preg_match_all("/`([a-z_]+)` (INT UNSIGNED|INT|CHAR\\([0-9]+\\)|VARCHAR\\([0-9]+\\)|LONGTEXT|TEXT|TIMESTAMP|TINYINT) (NOT NULL|NULL)([^,\\n]*)/",
            $definition, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $match) {
            $default = null;
            if (preg_match("/DEFAULT (NULL|[0-9]+|'[a-z_]*')/", $match[4], $d)) {
                $default = $d[1] === 'NULL' ? null : trim($d[1], "'");
            }
            $out[$match[1]] = [$match[2], $match[3] === 'NULL' ? 'YES' : 'NO', $default];
        }
        return $out;
    }

    /**
     * Сверка колонок таблицы (строки SHOW COLUMNS) с определением: первая расхождение — словами,
     * null — совпало. Без базы: проверяется и в «чистых» проверках.
     */
    public static function columnsMismatch(array $columns, string $definition): ?string
    {
        foreach (self::expectedColumns($definition) as $name => [$type, $null, $default]) {
            $column = $columns[$name] ?? null;
            if ($column === null) {
                return 'нет колонки ' . $name;
            }
            $actual = strtoupper(preg_replace('/\b(INT|TINYINT)\([0-9]+\)/i', '$1', (string) $column['Type']));
            $actual_default = $column['Default'] === null ? null : (string) $column['Default'];
            if ($actual !== $type || $column['Null'] !== $null || $actual_default !== $default
                || ($name === 'id' && !str_contains(strtolower((string) ($column['Extra'] ?? '')), 'auto_increment'))) {
                return 'колонка ' . $name . ' не такая, как ожидается';
            }
        }
        return null;
    }

    private static function verifyTable(string $table, string $definition): void
    {
        global $DB;
        $columns = $DB->listFields($table, false);
        $definition = "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n`date_creation` TIMESTAMP NULL DEFAULT NULL,\n`date_mod` TIMESTAMP NULL DEFAULT NULL,\n" . $definition;
        if (self::columnsMismatch($columns, $definition) !== null) {
            throw new \RuntimeException('Частичная схема структурированных исходов не подтверждена.');
        }
        $result = $DB->doQuery('SHOW TABLE STATUS WHERE Name = ' . $DB->quote($table));
        $status = $DB->fetchAssoc($result);
        if (($status['Engine'] ?? '') !== 'InnoDB') {
            throw new \RuntimeException('Таблица исходов должна поддерживать транзакции.');
        }
        preg_match_all('/UNIQUE KEY `([a-z_]+)` \(([^)]+)\)/', $definition, $indexes, PREG_SET_ORDER);
        foreach ($indexes as $index) {
            self::verifyUnique($table, $index[1], str_replace('`', '', $index[2]));
        }
        self::verifyUnique($table, 'PRIMARY', 'id');
    }

    private static function verifyUnique(string $table, string $name, string $columns): void
    {
        global $DB;
        $result = $DB->doQuery('SHOW INDEX FROM ' . $DB->quoteName($table));
        $found = [];
        while ($row = $DB->fetchAssoc($result)) {
            if ($row['Key_name'] === $name) {
                if ((int) $row['Non_unique'] !== 0 || $row['Sub_part'] !== null
                    || isset($found[(int) $row['Seq_in_index']])) {
                    throw new \RuntimeException('Уникальность структурированных исходов не подтверждена.');
                }
                $found[(int) $row['Seq_in_index']] = $row['Column_name'];
            }
        }
        ksort($found);
        if (implode(',', $found) !== $columns) {
            throw new \RuntimeException('Уникальность структурированных исходов не подтверждена.');
        }
    }

    /** Строгая проверка после выполнения миграции: таблицы исходов целиком и добавочные поля. */
    public static function verify(): void
    {
        global $DB;
        foreach (self::tables() as $suffix => $definition) {
            self::verifyTable('glpi_plugin_itilflow_' . $suffix, $definition);
        }
        $added = [
            'processes' => ['outcome_contract' => ['VARCHAR(255)', 'NO', 'legacy']],
            'stages' => ['department_result_source' => ['VARCHAR(255)', 'NO', ''],
                'negative_policy' => ['VARCHAR(255)', 'NO', ''], 'return_targets' => ['LONGTEXT', 'YES', null]],
            'instances' => ['outcome_contract' => ['VARCHAR(255)', 'NO', 'legacy'],
                'definition_snapshot' => ['LONGTEXT', 'YES', null], 'definition_sha256' => ['VARCHAR(64)', 'YES', null],
                'current_attempt_id' => ['INT UNSIGNED', 'YES', null]],
            'steps' => ['attempt_id' => ['INT UNSIGNED', 'YES', null], 'artifact_entities_id' => ['INT UNSIGNED', 'YES', null],
                'superseded_by_attempt_id' => ['INT UNSIGNED', 'YES', null]],
        ];
        // Поля 1.11 — из тех же определений, что и миграция (outcomevalidations проверена целиком выше).
        foreach (self::ADDED_1_11 as $suffix => $fields) {
            if ($suffix === 'outcomevalidations') {
                continue;
            }
            foreach ($fields as $field => $definition) {
                $added[$suffix][$field] = self::expectedColumns('`' . $field . '` ' . $definition)[$field];
            }
        }
        foreach ([self::ADDED_1_12, self::ADDED_1_13] as $version) {
            foreach ($version as $suffix => $fields) {
                foreach ($fields as $field => $definition) {
                    $added[$suffix][$field] = self::expectedColumns('`' . $field . '` ' . $definition)[$field];
                }
            }
        }
        foreach ($added as $suffix => $fields) {
            $actual = $DB->listFields('glpi_plugin_itilflow_' . $suffix, false);
            foreach ($fields as $field => $expected) {
                $column = $actual[$field] ?? null;
                $type = $column ? strtoupper(preg_replace('/\b(INT|TINYINT)\([0-9]+\)/i', '$1', $column['Type'])) : '';
                if ($column === null || [$type, $column['Null'], $column['Default'] === null ? null : (string) $column['Default']] !== $expected) {
                    throw new \RuntimeException('Добавочные поля схемы не подтверждены.');
                }
            }
        }
        self::verifyUnique('glpi_plugin_itilflow_steps', 'attempt_stage', 'attempt_id,plugin_itilflow_stages_id');
    }

    /** Определение таблицы исходов — для «чистых» проверок схемы. */
    public static function definition(string $suffix): ?string
    {
        return self::tables()[$suffix] ?? null;
    }

    /** Поля 1.11 по таблицам — для проверки обновления. */
    public static function added111(): array
    {
        return self::ADDED_1_11;
    }

    public static function added112(): array
    {
        return self::ADDED_1_12;
    }

    public static function added113(): array
    {
        return self::ADDED_1_13;
    }
}
