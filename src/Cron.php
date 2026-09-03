<?php

namespace GlpiPlugin\Itilflow;

use CommonDBTM;
use CronTask;

/**
 * Автоматическое действие GLPI: контроль сроков этапов.
 * Регистрируется штатным CronTask::register и виден в
 * Настройки → Автоматические действия наравне с остальными.
 */
class Cron extends CommonDBTM
{
    public static function getTypeName($nb = 0)
    {
        return 'Маршруты этапов';
    }

    /** Описание задачи в списке автоматических действий. */
    public static function cronInfo($name): array
    {
        if ($name === 'itilflow_deadlines') {
            return [
                'description' => 'Контроль сроков этапов: отметка просрочки и запись в журнал',
            ];
        }
        return [];
    }

    /**
     * @return int -1 ошибка, 0 нечего делать, 1 что-то сделано
     */
    public static function cronItilflow_deadlines(CronTask $task): int
    {
        $done = 0;
        $rows = (new Step())->find([
            'state'        => Step::RUNNING,
            'is_escalated' => 0,
            ['NOT' => ['date_due' => null]],
            ['date_due'    => ['<', $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')]],
        ], ['date_due ASC'], 200);

        foreach ($rows as $id => $row) {
            $step = new Step();
            if (!$step->getFromDB((int) $id)) {
                continue;
            }
            if (Engine::escalate($step)) {
                $done++;
                $task->addVolume(1);
            }
        }

        if ($done > 0) {
            $task->log(sprintf('Отмечено просроченных этапов: %d', $done));
            return 1;
        }
        return 0;
    }
}
