<?php

namespace GlpiPlugin\Itilflow;

/**
 * Отчёт по узким местам: где процесс стоит дольше всего.
 * Средняя длительность обманчива — в отчёте есть медиана и максимум,
 * потому что затор обычно виден в хвосте распределения, а не в среднем.
 */
final class Report
{
    /** @return array<int, array> строки отчёта по этапам маршрута */
    public static function byStage(int $processes_id): array
    {
        $steps = (new Step())->find([], ['ranking ASC']);
        $instances = [];
        foreach ((new Instance())->find(['plugin_itilflow_processes_id' => $processes_id]) as $id => $r) {
            $instances[(int) $id] = $r;
        }
        if (!count($instances)) {
            return [];
        }

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
        $rows = [];
        foreach ((new Instance())->find(['plugin_itilflow_processes_id' => $processes_id]) as $r) {
            $rows[] = $r;
        }
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
