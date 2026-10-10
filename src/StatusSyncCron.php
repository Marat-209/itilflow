<?php

namespace GlpiPlugin\Itilflow;

final class StatusSyncCron
{
    public static function run(\CronTask $task): int
    {
        StatusSync::assertCron($task);
        $started=hrtime(true); $done=0;
        $rows=(new Step())->find(['status_sync_state'=>StatusSync::QUEUED],['id ASC'],10);
        foreach($rows as $id=>$row) {
            if((hrtime(true)-$started)/1e9>=2.0) { break; } // Проверка между записями, не жёсткий предел одного вызова.
            $step=new Step();
            if(!$step->getFromDB((int)$id)) { continue; }
            try { $result=Engine::synchronizeStatusFromCron($step,$task); }
            catch (\Throwable $e) { $result=['state'=>StatusSync::UNKNOWN]; }
            $done++; $task->addVolume(1);
            if($result['state']===StatusSync::UNKNOWN || $result['state']===StatusSync::EXECUTING) {
                // CronTask::end сохраняет это поле объекта обработчика в его собственной строке.
                // Отрицательный код в GLPI означает повторный запуск и здесь не подходит.
                $task->fields['state']=\CronTask::STATE_DISABLE;
                $task->log('Автоматическое действие выключено: исход записи неизвестен. Нужна проверка перед ручным включением.');
                return 0;
            }
        }
        if($done>0) { $task->log(sprintf('Обработано запросов синхронизации: %d',$done)); }
        return $done>0 ? 1 : 0;
    }
}
