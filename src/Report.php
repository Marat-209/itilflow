<?php

namespace GlpiPlugin\Itilflow;

/**
 * Отчёт по узким местам: где маршрут стоит дольше всего.
 * Средняя длительность обманчива — в отчёте есть медиана и максимум,
 * потому что затор обычно виден в хвосте распределения, а не в среднем.
 */
final class Report
{
    /** Сводка только по сохранённым определениям, без текущей настройки и авторов ответов. */
    public static function decisionSummary(array $instances, array $steps): array
    {
        $definitions = [];
        foreach ($instances as $id => $row) {
            $instance = new Instance();
            $instance->fields = $row;
            if (!DefinitionSnapshot::enabled($instance)) {
                $definitions[(int) $id] = ['snapshot' => ['schema' => 0]];
                continue;
            }
            $snapshot = DefinitionSnapshot::load($instance);
            $definitions[(int) $id] = $snapshot === null ? null : [
                'snapshot' => $snapshot, 'sha' => (string) $row['definition_sha256']];
        }
        $out = [];
        foreach ($steps as $row) {
            $sid = (int) $row['plugin_itilflow_stages_id'];
            $out[$sid] ??= ['groups' => [], 'unavailable' => 0];
            $definition = $definitions[(int) $row['plugin_itilflow_instances_id']] ?? null;
            if ($definition === null) { $out[$sid]['unavailable']++; continue; }
            $snapshot = $definition['snapshot'];
            if ($snapshot['schema'] < 3) { continue; }
            $stage = null;
            foreach ($snapshot['stages'] as $candidate) {
                if ((int) $candidate['id'] === $sid) { $stage = $candidate; break; }
            }
            $items = $stage === null ? null : BranchConfiguration::decisionItems($stage['decision_items'] ?? null);
            if ($items === null) { $out[$sid]['unavailable']++; continue; }
            if ($items === []) { continue; }
            $answers = null;
            if (empty($row['superseded_by_attempt_id']) && ($row['state'] ?? '') !== Step::NOT_NEEDED) {
                $step = new Step(); $step->fields = $row;
                try { $answers = Decision::normalize($items, Decision::read($step)['answers']); }
                catch (\Throwable $e) { TransactionScope::keepGoing($e); }
            }
            foreach ($items as $item) {
                $key = DefinitionSnapshot::encode([$definition['sha'], $sid, $item['key'], $item['label']]);
                $out[$sid]['groups'][$key] ??= [
                    'version' => (int) $snapshot['process']['version'], 'label' => $item['label'],
                    'yes' => 0, 'no' => 0, 'unknown' => 0, 'not_needed' => 0, 'history' => 0];
                $group =& $out[$sid]['groups'][$key];
                if (!empty($row['superseded_by_attempt_id'])) { $group['history']++; }
                elseif (($row['state'] ?? '') === Step::NOT_NEEDED) { $group['not_needed']++; }
                elseif ($answers === null) { $group['unknown']++; }
                elseif ($answers[$item['key']]) { $group['yes']++; }
                else { $group['no']++; }
                unset($group);
            }
        }
        foreach ($out as &$summary) {
            $summary['groups'] = array_values($summary['groups']);
            foreach ($summary['groups'] as $i => &$group) { $group['variant'] = $i + 1; }
            unset($group);
        }
        unset($summary);
        return $out;
    }

    /**
     * Отчёт включает только объекты, доступные текущей сессии. Заявки в корзине в отчёт
     * не входят, как и в статистику GLPI, хотя их маршрут можно посмотреть на вкладке.
     */
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
            if ($instance->getFromDB((int) $id) && Authorizer::instanceError($instance, 'read') === null
                && empty($instance->getItilItem()?->fields['is_deleted'])) {
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
        $decisions = self::decisionSummary($instances, $steps);
        $acc = [];
        $conditional = [];
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
                    'not_needed' => 0,
                    'rejected'  => 0,
                    'clarification' => 0,
                    'refused' => 0,
                    'superseded' => 0,
                    'running'   => 0,
                    'overdue'   => 0,
                    'reassigned' => 0,
                    'duration_unknown' => 0,
                    'durations' => [],
                    'required_rows' => [],
                ];
            }
            $a =& $acc[$key];
            $a['passes']++;
            // Условие «Когда выполнять» — из снимка этого запуска: у разных версий маршрута оно разное.
            if (!isset($conditional[$iid])) {
                $instance = new Instance();
                $instance->fields = $instances[$iid];
                $conditional[$iid] = Sheet::conditionalStages($instance);
            }
            $a['required_rows'][] = array_intersect_key($row,
                array_flip(['state', 'date_start', 'superseded_by_attempt_id']))
                + ['conditional' => in_array($key, $conditional[$iid], true)];
            if ($row['state'] === Step::SUPERSEDED) { $a['superseded']++; }
            if (($instances[$iid]['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE) {
                $outcome = Outcome::forStep((int) $row['id']);
                if ($outcome !== null && $outcome->fields['outcome'] === Outcome::REFUSED) { $a['refused']++; }
                if ($outcome !== null && $outcome->fields['outcome'] === Outcome::CLARIFICATION) { $a['clarification']++; }
            }
            if ($row['state'] === Step::DONE) {
                $a['done']++;
                $duration = ($instances[$iid]['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE
                    ? Step::elapsedSeconds($row)
                    : (!empty($row['date_end']) ? (int) $row['duration'] : null);
                if ($duration !== null) { $a['durations'][] = $duration; }
                else { $a['duration_unknown']++; }
            } elseif ($row['state'] === Step::SKIPPED) {
                $a['skipped']++;
            } elseif ($row['state'] === Step::NOT_NEEDED) {
                $a['not_needed']++;
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
            $a['duration_samples'] = $n;
            $a['avg'] = $n ? (int) round(array_sum($d) / $n) : 0;
            $a['median'] = $n ? (int) ($n % 2 ? $d[intdiv($n, 2)]
                : round(($d[$n / 2 - 1] + $d[$n / 2]) / 2)) : 0;
            $a['max'] = $n ? (int) end($d) : 0;
            // Длительность словами плагина («2 мин»), а не GLPI («2 минут»).
            foreach (['avg', 'median', 'max'] as $f) { $a[$f . '_text'] = Ui::duration((int) $a[$f]); }
            $a['required_progress'] = Sheet::requiredProgress($a['required_rows']);
            $a['decisions'] = self::mergeByLabel($decisions[$key] ?? ['groups' => [], 'unavailable' => 0]);
            unset($a['durations'], $a['required_rows']);
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
        $out = [
            'total'    => count($rows),
            'by_state' => $by_state,
            'lead_avg' => $n ? (int) round(array_sum($lead) / $n) : 0,
            'lead_med' => $n ? (int) ($n % 2 ? $lead[intdiv($n, 2)]
                : round(($lead[$n / 2 - 1] + $lead[$n / 2]) / 2)) : 0,
            'lead_max' => $n ? (int) end($lead) : 0,
        ];
        foreach (['lead_avg', 'lead_med', 'lead_max'] as $f) { $out[$f . '_text'] = Ui::duration($out[$f]); }
        return $out;
    }

    /**
     * Вопросы этапа в отчёте — одной строкой на вопрос: группы разных версий маршрута с той же
     * подписью складываются. Сами группы (decisionSummary) по-прежнему различают смысл по версии.
     */
    public static function mergeByLabel(array $summary): array
    {
        $merged = [];
        foreach ($summary['groups'] ?? [] as $g) {
            $key = (string) ($g['label'] ?? '');
            if (!isset($merged[$key])) { $merged[$key] = $g; continue; }
            foreach (['yes', 'no', 'unknown', 'not_needed', 'history'] as $f) {
                $merged[$key][$f] = (int) ($merged[$key][$f] ?? 0) + (int) ($g[$f] ?? 0);
            }
        }
        $summary['groups'] = array_values($merged);
        return $summary;
    }
}
