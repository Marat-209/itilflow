<?php

namespace GlpiPlugin\Itilflow;

/** Снимки прежнего действовавшего определения, без восстановления мнимой истории. */
final class Upgrade
{
    public static function approvalSnapshots(): bool
    {
        foreach ((new Step())->find(['execution_mode' => Stage::MODE_APPROVAL]) as $id => $row) {
            if (($row['approval_snapshot_source'] ?? '') !== '') {
                continue;
            }
            $step = new Step();
            if (!$step->getFromDB((int) $id)) {
                return false;
            }
            $stage = $step->getStage();
            $instance = $step->getInstance();
            $process = $instance?->getProcess();
            $item = $instance?->getItilItem();
            $percent = (int) ($stage?->fields['approval_percent'] ?? 0);
            if ($stage === null || $instance === null || $process === null || $item === null
                || (int) $stage->fields['plugin_itilflow_processes_id'] !== (int) $process->getID()
                || (int) $instance->fields['entities_id'] !== (int) $item->fields['entities_id']
                || $percent < 1 || $percent > 100) {
                continue;
            }
            $roster = $step->validationRoster(false);
            if (!$step->update([
                'id' => (int) $id,
                'approval_percent_snapshot' => $percent,
                'approval_snapshot_source' => 'upgrade_snapshot',
                'approval_expected_count' => $roster !== null ? count($roster) : null,
            ])) {
                return false;
            }
        }
        // Исходного requester старых экземпляров не восстанавливаем из текущих actors.
        return true;
    }
}
