<?php

namespace GlpiPlugin\Itilflow;

/** Добавочная схема явных исходов и попыток. История legacy не преобразуется в новые исходы. */
final class StructuredSchema
{
    public static function prepare(\Migration $migration): void
    {
        global $DB;
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

        foreach (self::tables() as $suffix => $fields) {
            $table = $prefix . $suffix;
            if (!$DB->tableExists($table)) {
                $DB->doQuery('CREATE TABLE ' . $DB->quoteName($table) . ' (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `date_creation` TIMESTAMP NULL DEFAULT NULL,
                    `date_mod` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),' . $fields . '
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            }
            // DDL не считается откатываемой частью DML-транзакции.
            self::verifyTable($table, $fields);
        }
    }

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

    private static function verifyTable(string $table, string $definition): void
    {
        global $DB;
        $columns = $DB->listFields($table, false);
        $definition = "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n`date_creation` TIMESTAMP NULL DEFAULT NULL,\n`date_mod` TIMESTAMP NULL DEFAULT NULL,\n" . $definition;
        preg_match_all('/`([a-z_]+)` (INT UNSIGNED|INT|CHAR\([0-9]+\)|VARCHAR\([0-9]+\)|LONGTEXT|TEXT|TIMESTAMP|TINYINT) (NOT NULL|NULL)([^,\n]*)/',
            $definition, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $column = $columns[$match[1]] ?? null;
            $type = $column ? strtoupper(preg_replace('/\b(INT|TINYINT)\([0-9]+\)/i', '$1', $column['Type'])) : '';
            $default = preg_match("/DEFAULT (NULL|0|'')/", $match[4], $d) ? match ($d[1]) { '0' => '0', "''" => '', default => null } : null;
            if ($column === null || $type !== $match[2]
                || $column['Null'] !== ($match[3] === 'NULL' ? 'YES' : 'NO')
                || ($column['Default'] === null ? null : (string) $column['Default']) !== $default
                || ($match[1] === 'id' && !str_contains(strtolower($column['Extra']), 'auto_increment'))) {
                throw new \RuntimeException('Частичная схема структурированных исходов не подтверждена.');
            }
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

    public static function verify(): void
    {
        global $DB;
        foreach (self::tables() as $suffix => $definition) {
            self::verifyTable('glpi_plugin_itilflow_' . $suffix, $definition);
        }
        foreach ([
            'processes' => ['outcome_contract' => ['VARCHAR(255)', 'NO', 'legacy']],
            'stages' => ['department_result_source' => ['VARCHAR(255)', 'NO', ''],
                'negative_policy' => ['VARCHAR(255)', 'NO', ''], 'return_targets' => ['LONGTEXT', 'YES', null]],
            'instances' => ['outcome_contract' => ['VARCHAR(255)', 'NO', 'legacy'],
                'definition_snapshot' => ['LONGTEXT', 'YES', null], 'definition_sha256' => ['VARCHAR(64)', 'YES', null],
                'current_attempt_id' => ['INT UNSIGNED', 'YES', null]],
            'steps' => ['attempt_id' => ['INT UNSIGNED', 'YES', null], 'artifact_entities_id' => ['INT UNSIGNED', 'YES', null],
                'superseded_by_attempt_id' => ['INT UNSIGNED', 'YES', null]],
        ] as $suffix => $fields) {
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
}
