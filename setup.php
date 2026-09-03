<?php

/**
 * itilflow — этапные маршруты обработки заявок для GLPI 11.
 *
 * Плагин добавляет ровно одно, чего нет в штатной системе: управляемый переход
 * между этапами и запрет действий вне текущего этапа. Сроки, права, уведомления
 * и отчётность остаются за GLPI.
 *
 * -------------------------------------------------------------------------
 * Copyright (C) 2026 itilflow contributors
 *
 * Свободное программное обеспечение: распространяется и изменяется на условиях
 * GNU General Public License версии 3 или любой более поздней, опубликованной
 * Free Software Foundation. Полный текст лицензии — в файле LICENSE.
 *
 * Программа распространяется в надежде, что окажется полезной, но БЕЗ КАКИХ
 * ЛИБО ГАРАНТИЙ. Подробности в тексте лицензии.
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ITILFLOW_VERSION', '1.3.2');
define('PLUGIN_ITILFLOW_MIN_GLPI', '11.0.0');
define('PLUGIN_ITILFLOW_MAX_GLPI', '11.99.99');

function plugin_init_itilflow(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['itilflow'] = true;

    $H = 'GlpiPlugin\\Itilflow\\Hook';

    Plugin::registerClass(\GlpiPlugin\Itilflow\Process::class, [
        'addtabon' => [],
    ]);
    Plugin::registerClass(\GlpiPlugin\Itilflow\Instance::class, [
        'addtabon' => ['Ticket', 'Change', 'Problem'],
    ]);

    // --- слой 1: авторизация. Вызывается формой, Kanban, массовыми действиями, API.
    $PLUGIN_HOOKS[Hooks::ITEM_CAN]['itilflow'] = [
        'TicketTask'  => [$H, 'itemCan'],
        'ChangeTask'  => [$H, 'itemCan'],
        'ProblemTask' => [$H, 'itemCan'],
    ];

    // --- слой 2: целостность. Внутри CommonDBTM::update(), обойти нечем.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['itilflow'] = [
        'TicketTask'       => [$H, 'preTaskUpdate'],
        'ChangeTask'       => [$H, 'preTaskUpdate'],
        'ProblemTask'      => [$H, 'preTaskUpdate'],
        'Ticket'           => [$H, 'preItilUpdate'],
        'Change'           => [$H, 'preItilUpdate'],
        'Problem'          => [$H, 'preItilUpdate'],
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['itilflow'] = [
        'ITILSolution' => [$H, 'preSolutionAdd'],
    ];

    // --- продвижение маршрута
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['itilflow'] = [
        'TicketTask'       => [$H, 'postTaskUpdate'],
        'ChangeTask'       => [$H, 'postTaskUpdate'],
        'ProblemTask'      => [$H, 'postTaskUpdate'],
        'Ticket'           => [$H, 'postItilUpdate'],
        'Change'           => [$H, 'postItilUpdate'],
        'Problem'          => [$H, 'postItilUpdate'],
        'TicketValidation' => [$H, 'postValidationUpdate'],
        'ChangeValidation' => [$H, 'postValidationUpdate'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['itilflow'] = [
        'Ticket'  => [$H, 'postItilAdd'],
        'Change'  => [$H, 'postItilAdd'],
        'Problem' => [$H, 'postItilAdd'],
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['itilflow'] = [
        'Ticket'  => [$H, 'prePurgeItil'],
        'Change'  => [$H, 'prePurgeItil'],
        'Problem' => [$H, 'prePurgeItil'],
    ];

    // --- перенос заявки между сущностями
    $PLUGIN_HOOKS[Hooks::ITEM_TRANSFER]['itilflow'] = [$H, 'itemTransfer'];

    // --- расширение штатных бизнес-правил
    $PLUGIN_HOOKS[Hooks::USE_RULES]['itilflow'] = ['RuleTicket', 'RuleChange', 'RuleProblem'];

    // --- меню настройки
    if (Session::haveRight('plugin_itilflow_process', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['itilflow'] = ['admin' => \GlpiPlugin\Itilflow\Process::class];
    }
    Plugin::registerClass(\GlpiPlugin\Itilflow\Cron::class);
    if (Session::haveRight('plugin_itilflow_process', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['itilflow'] = '/front/process.php';
    }
}

function plugin_version_itilflow(): array
{
    return [
        'name'         => 'ITIL Flow — этапные маршруты',
        'version'      => PLUGIN_ITILFLOW_VERSION,
        'author'       => 'itilflow contributors',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'minGlpiVersion' => PLUGIN_ITILFLOW_MIN_GLPI,
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ITILFLOW_MIN_GLPI,
                'max' => PLUGIN_ITILFLOW_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_itilflow_check_prerequisites(): bool
{
    return true;
}

function plugin_itilflow_check_config($verbose = false): bool
{
    return true;
}

/** Критерии для штатных бизнес-правил. */
function plugin_itilflow_getRuleCriteria($params = [])
{
    return [];
}

/** Действие «Запустить маршрут» в штатных бизнес-правилах. */
function plugin_itilflow_getRuleActions($params = [])
{
    if (!in_array($params['rule_itemtype'] ?? '', ['RuleTicket', 'RuleChange', 'RuleProblem'], true)) {
        return [];
    }
    return [
        '_plugin_itilflow_processes_id' => [
            'name'          => 'Запустить маршрут этапов',
            'type'          => 'dropdown',
            'table'         => 'glpi_plugin_itilflow_processes',
            'force_actions' => ['assign'],
        ],
    ];
}
