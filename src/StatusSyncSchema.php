<?php

namespace GlpiPlugin\Itilflow;

final class StatusSyncSchema
{
    public static function prepare(\Migration $migration): void
    {
        $table = Step::getTable();
        $migration->addField($table, 'status_sync_state', 'VARCHAR(24) NULL DEFAULT NULL');
        $migration->addField($table, 'status_sync_error', 'VARCHAR(48) NULL DEFAULT NULL');
        foreach (['target', 'actor', 'before', 'after', 'caller'] as $name) {
            $migration->addField($table, 'status_sync_' . $name, 'INT UNSIGNED NULL DEFAULT NULL');
        }
        foreach (['definition_sha', 'fingerprint'] as $name) {
            $migration->addField($table, 'status_sync_' . $name, 'CHAR(64) NULL DEFAULT NULL');
        }
        $migration->addKey($table, ['status_sync_state', 'id'], 'status_sync_queue');
        // Прежние строки остаются NULL; прошлые целевые статусы не восстанавливаются предположительно.
    }

    public static function verify(): void
    {
        global $DB;
        $table = Step::getTable();
        $expected = ['state'=>'VARCHAR(24)','error'=>'VARCHAR(48)',
            'definition_sha'=>'CHAR(64)','fingerprint'=>'CHAR(64)'];
        foreach (['target','actor','before','after','caller'] as $name) { $expected[$name]='INT UNSIGNED'; }
        $columns=$DB->listFields($table,false);
        foreach ($expected as $suffix=>$type) {
            $column=$columns['status_sync_'.$suffix]??null;
            $actual=$column ? strtoupper(preg_replace('/\bINT\([0-9]+\)/i','INT',$column['Type'])) : '';
            if ($column===null || $actual!==$type || $column['Null']!=='YES' || $column['Default']!==null) {
                throw new \RuntimeException('Схема синхронизации статусов не подтверждена.');
            }
        }
        $result=$DB->doQuery('SHOW INDEX FROM '.$DB->quoteName($table));
        $found=[];
        while ($row=$DB->fetchAssoc($result)) {
            if ($row['Key_name']==='status_sync_queue') {
                if ((int)$row['Non_unique']!==1 || $row['Sub_part']!==null
                    || strtoupper((string)$row['Index_type'])!=='BTREE') {
                    throw new \RuntimeException('Индекс очереди синхронизации не подтверждён.');
                }
                $found[(int)$row['Seq_in_index']]=$row['Column_name'];
            }
        }
        ksort($found);
        if (array_values($found)!==['status_sync_state','id']) {
            throw new \RuntimeException('Индекс очереди синхронизации не подтверждён.');
        }
    }
}
