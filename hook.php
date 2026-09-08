<?php

function plugin_itilflow_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_ITILFLOW_VERSION);
    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    /* ---------------------------------------------------------- processes */
    if (!$DB->tableExists('glpi_plugin_itilflow_processes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_itilflow_processes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `itemtype` VARCHAR(100) NOT NULL DEFAULT 'Ticket',
            `enforcement` VARCHAR(20) NOT NULL DEFAULT 'strict',
            `version` INT NOT NULL DEFAULT 1,
            `block_resolution` TINYINT NOT NULL DEFAULT 1,
            `public_log` TINYINT NOT NULL DEFAULT 1,
            `helpdesk_tab` TINYINT NOT NULL DEFAULT 1,
            `allow_withdraw` TINYINT NOT NULL DEFAULT 1,
            `calendars_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ------------------------------------------------------------ stages */
    if (!$DB->tableExists('glpi_plugin_itilflow_stages')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_itilflow_stages` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_itilflow_processes_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name` VARCHAR(255) DEFAULT NULL,
            `ranking` INT NOT NULL DEFAULT 0,
            `execution_mode` VARCHAR(20) NOT NULL DEFAULT 'inline',
            `entity_strategy` VARCHAR(20) NOT NULL DEFAULT 'inherit',
            `entities_id_target` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itilcategories_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `slas_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `duration` INT NOT NULL DEFAULT 0,
            `is_optional` TINYINT NOT NULL DEFAULT 0,
            `completion_comment` VARCHAR(20) NOT NULL DEFAULT 'optional',
            `deadline_minutes` INT NOT NULL DEFAULT 0,
            `approval_percent` TINYINT UNSIGNED NOT NULL DEFAULT 100,
            `approver_source` VARCHAR(20) NOT NULL DEFAULT 'fixed',
                  `ticket_status` INT NOT NULL DEFAULT 0,
            `on_reject` VARCHAR(20) NOT NULL DEFAULT 'block',
            `plugin_itilflow_stages_id_reject` INT UNSIGNED NOT NULL DEFAULT 0,
            `content` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `process` (`plugin_itilflow_processes_id`,`ranking`),
            KEY `groups_id` (`groups_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* --------------------------------------------------------- instances */
    if (!$DB->tableExists('glpi_plugin_itilflow_instances')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_itilflow_instances` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `itemtype` VARCHAR(100) NOT NULL DEFAULT 'Ticket',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `plugin_itilflow_processes_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `process_version` INT NOT NULL DEFAULT 1,
            `plugin_itilflow_stages_id_current` INT UNSIGNED NOT NULL DEFAULT 0,
            `state` VARCHAR(20) NOT NULL DEFAULT 'running',
            `date_start` TIMESTAMP NULL DEFAULT NULL,
            `date_end` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item` (`itemtype`,`items_id`),
            KEY `state` (`state`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* ------------------------------------------------------------- steps */
    if (!$DB->tableExists('glpi_plugin_itilflow_steps')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_itilflow_steps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_itilflow_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_itilflow_stages_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `stage_name` VARCHAR(255) DEFAULT NULL,
            `ranking` INT NOT NULL DEFAULT 0,
            `execution_mode` VARCHAR(20) NOT NULL DEFAULT 'inline',
            `state` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `pass_number` INT NOT NULL DEFAULT 1,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `group_attached` TINYINT NOT NULL DEFAULT 0,
            `artifact_itemtype` VARCHAR(100) DEFAULT NULL,
            `artifact_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_start` TIMESTAMP NULL DEFAULT NULL,
            `date_end` TIMESTAMP NULL DEFAULT NULL,
            `duration` INT NOT NULL DEFAULT 0,
            `date_due` TIMESTAMP NULL DEFAULT NULL,
            `is_escalated` TINYINT NOT NULL DEFAULT 0,
            `users_id_actual` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id_from` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_from` INT UNSIGNED NOT NULL DEFAULT 0,
            `reassign_reason` TEXT DEFAULT NULL,
            `approver_set_by` INT UNSIGNED NOT NULL DEFAULT 0,
            `approver_reason` TEXT DEFAULT NULL,
            `approver_items_ids` VARCHAR(255) NOT NULL DEFAULT '',
            `comment` TEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `instance` (`plugin_itilflow_instances_id`,`ranking`),
            KEY `date_due` (`date_due`,`state`),
            KEY `artifact` (`artifact_itemtype`,`artifact_items_id`),
            KEY `state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    /* -------------------------------------------------------- violations */
    if (!$DB->tableExists('glpi_plugin_itilflow_violations')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_itilflow_violations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_itilflow_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itemtype` VARCHAR(100) DEFAULT NULL,
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `action` VARCHAR(60) DEFAULT NULL,
            `was_blocked` TINYINT NOT NULL DEFAULT 1,
            `message` TEXT DEFAULT NULL,
            `date` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `instance` (`plugin_itilflow_instances_id`),
            KEY `date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}");
    }

    // обновление уже установленных копий
    $migration->addField('glpi_plugin_itilflow_processes', 'public_log', 'bool', ['value' => 1]);
    $migration->addField('glpi_plugin_itilflow_stages', 'completion_comment', 'string', ['value' => 'optional']);
    $migration->addField('glpi_plugin_itilflow_processes', 'helpdesk_tab', 'bool', ['value' => 1]);
    $migration->addField('glpi_plugin_itilflow_processes', 'allow_withdraw', 'bool', ['value' => 1]);
    $migration->addField('glpi_plugin_itilflow_processes', 'calendars_id', 'integer', ['value' => 0]);
    $migration->addField('glpi_plugin_itilflow_stages', 'deadline_minutes', 'integer', ['value' => 0]);
    $migration->addField('glpi_plugin_itilflow_steps', 'date_due', 'timestamp', ['value' => null]);
    $migration->addField('glpi_plugin_itilflow_steps', 'is_escalated', 'bool', ['value' => 0]);
    $migration->addField('glpi_plugin_itilflow_steps', 'users_id_actual', 'integer', ['value' => 0]);
    $migration->addField('glpi_plugin_itilflow_steps', 'groups_id_from', 'integer', ['value' => 0]);
    $migration->addField('glpi_plugin_itilflow_steps', 'users_id_from', 'integer', ['value' => 0]);
    $migration->addField('glpi_plugin_itilflow_steps', 'reassign_reason', 'text', ['value' => null]);

    // 1.4.0 — откуда берётся согласующий: задан в этапе, указывается при
    // прохождении или вычисляется как руководитель инициатора.
    $migration->addField('glpi_plugin_itilflow_stages', 'approver_source', 'string',
        ['value' => 'fixed']);
    $migration->addField('glpi_plugin_itilflow_steps', 'approver_set_by', 'integer',
        ['value' => 0]);
    $migration->addField('glpi_plugin_itilflow_steps', 'approver_reason', 'text',
        ['value' => null]);

    // 1.5.0 — на одном этапе может быть несколько отдельных согласований:
    // например руководитель заявителя и владелец ресурса согласуют по отдельности.
    // Список объектов согласования шага, через запятую.
    $migration->addField('glpi_plugin_itilflow_steps', 'approver_items_ids', 'string',
        ['value' => '']);

    // 1.6.0 — статус заявки, который выставляется при открытии этапа.
    // 0 — не менять: так ведут себя все маршруты, созданные до 1.6.0.
    $migration->addField('glpi_plugin_itilflow_stages', 'ticket_status', 'integer',
        ['value' => 0]);

    $migration->addRight('plugin_itilflow_process', ALLSTANDARDRIGHT);
    $migration->addRight('plugin_itilflow_bypass', 0);
    $migration->executeMigration();

    CronTask::register(
        'GlpiPlugin\\Itilflow\\Cron',
        'itilflow_deadlines',
        900,
        [
            'comment' => 'Контроль сроков этапов маршрутов: отметка просрочки и эскалация',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );

    return true;
}

function plugin_itilflow_uninstall(): bool
{
    global $DB;

    // История прохождения маршрутов не удаляется вместе с плагином:
    // таблицы сохраняются, чтобы не потерять данные заявок.
    foreach (['glpi_plugin_itilflow_stages', 'glpi_plugin_itilflow_processes'] as $t) {
        if ($DB->tableExists($t)) {
            $DB->doQuery("RENAME TABLE `{$t}` TO `{$t}_backup_" . date('Ymd') . "`");
        }
    }

    ProfileRight::deleteProfileRights(['plugin_itilflow_process', 'plugin_itilflow_bypass']);

    return true;
}
