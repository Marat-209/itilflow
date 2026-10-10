<?php

namespace GlpiPlugin\Itilflow;

/**
 * Отчёт по узким местам: где процесс стоит дольше всего.
 * Средняя длительность обманчива — в отчёте есть медиана и максимум,
 * потому что затор обычно виден в хвосте распределения, а не в среднем.
 */
final class Report
{
    /** Отчёт включает только объекты, доступные текущей сессии. */
    private static function visibleInstances(int $processes_id): array
    {
        $process = new Process();
        if (!\Session::haveRight('plugin_itilflow_process', READ)
            || !$process->getFromDB($processes_id) || !$process->can($processes_id, READ)
            || !\Session::haveAccessToEntity((int) $process->fields['entities_id'])) {
            return [];
        }
        $rows = [];
        foreach ((new Instance())->find(['plugin_itilflow_processes_id' => $processes_id]) as $id => $row) {
            $instance = new Instance();
            if ($instance->getFromDB((int) $id) && Authorizer::instanceError($instance, 'read') === null) {
                $rows[(int) $id] = $row;
            }
        }
        return $rows;
    }

    /** @return array<int, array> строки отчёта по этапам маршрута */
    public static function byStage(int $processes_id): array
    {
        $instances = self::visibleInstances($processes_id);
        if (!count($instances)) {
            return [];
        }

        $steps = (new Step())->find(['plugin_itilflow_instances_id' => array_keys($instances)], ['ranking ASC']);
        $acc = [];
        foreach ($steps as $row) {
            $iid = (int) $row['plugin_itilflow_instances_id'];
            if (!isset($instances[$iid])) {
                continue;
            }
            $key = (int) $row['plugin_itilflow_stages_id'];
            if (!isset($acc[$key])) {
                $acc[$key] = [
                    'ranking'   => (int) $row['ranking'],
                    'name'      => (string) $row['stage_name'],
                    'mode'      => (string) $row['execution_mode'],
                    'passes'    => 0,
                    'done'      => 0,
                    'skipped'   => 0,
                    'rejected'  => 0,
                    'running'   => 0,
                    'overdue'   => 0,
                    'reassigned' => 0,
                    'durations' => [],
                ];
            }
            $a =& $acc[$key];
            $a['passes']++;
            if ($row['state'] === Step::DONE) {
                $a['done']++;
                // Ноль — тоже результат: этап, закрытый за секунды, не должен
                // выпадать из статистики, иначе средняя посчитается по хвосту.
                if (!empty($row['date_end'])) {
                    $a['durations'][] = (int) $row['duration'];
                }
            } elseif ($row['state'] === Step::SKIPPED) {
                $a['skipped']++;
            } elseif ($row['state'] === Step::REJECTED) {
                $a['rejected']++;
            } elseif ($row['state'] === Step::RUNNING) {
                $a['running']++;
            }
            if (Sheet::wasOverdue($row)) {
                $a['overdue']++;
            }
            if ((int) $row['groups_id_from'] > 0 || (int) $row['users_id_from'] > 0) {
                $a['reassigned']++;
            }
            unset($a);
        }

        $out = [];
        foreach ($acc as $key => $a) {
            $d = $a['durations'];
            sort($d);
            $n = count($d);
            $a['stages_id'] = $key;
            $a['avg'] = $n ? (int) round(array_sum($d) / $n) : 0;
            $a['median'] = $n ? (int) ($n % 2 ? $d[intdiv($n, 2)]
                : round(($d[$n / 2 - 1] + $d[$n / 2]) / 2)) : 0;
            $a['max'] = $n ? (int) end($d) : 0;
            unset($a['durations']);
            $out[] = $a;
        }
        usort($out, static fn($x, $y) => $x['ranking'] <=> $y['ranking']);
        return $out;
    }

    /** Сводка по маршруту целиком. */
    public static function summary(int $processes_id): array
    {
        $rows = self::visibleInstances($processes_id);
        $by_state = [];
        $lead = [];
        foreach ($rows as $r) {
            $by_state[$r['state']] = ($by_state[$r['state']] ?? 0) + 1;
            if ($r['state'] === Instance::DONE && $r['date_start'] && $r['date_end']) {
                $lead[] = strtotime((string) $r['date_end']) - strtotime((string) $r['date_start']);
            }
        }
        sort($lead);
        $n = count($lead);
        return [
            'total'    => count($rows),
            'by_state' => $by_state,
            'lead_avg' => $n ? (int) round(array_sum($lead) / $n) : 0,
            'lead_med' => $n ? (int) ($n % 2 ? $lead[intdiv($n, 2)]
                : round(($lead[$n / 2 - 1] + $lead[$n / 2]) / 2)) : 0,
            'lead_max' => $n ? (int) end($lead) : 0,
        ];
    }
}
