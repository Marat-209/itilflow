<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use Group;
use Html;

/**
 * Лист согласования (маршрутный лист) — печатная сводка прохождения
 * маршрута: кто, когда, что сделал, кому передавал, уложился ли в срок.
 */
final class Sheet
{
    public static function rows(Instance $instance): array
    {
        $out = [];
        foreach ($instance->getSteps() as $row) {
            $planned = self::actorName((int) $row['groups_id_from'], (int) $row['users_id_from']);
            $actual = self::actorName((int) $row['groups_id'], (int) $row['users_id']);
            $reassigned = $planned !== '' && $planned !== $actual;

            $closed_by = (int) $row['users_id_actual'] > 0
                ? getUserName((int) $row['users_id_actual']) : '';

            $result = '';
            if ($row['state'] === Step::DONE) {
                $result = 'Выполнено';
            } elseif ($row['state'] === Step::SKIPPED) {
                $result = 'Пропущено';
            } elseif ($row['state'] === Step::REJECTED) {
                $result = 'Не согласовано';
            } elseif ($row['state'] === Step::RUNNING) {
                $result = Engine::isOverdue($row) ? 'В работе, срок истёк' : 'В работе';
            } else {
                $result = 'Ожидает очереди';
            }

            $out[] = [
                'no'          => sprintf('%d%s', (int) $row['ranking'],
                    (int) $row['pass_number'] > 1 ? '.' . $row['pass_number'] : ''),
                'name'        => (string) $row['stage_name'],
                'mode'        => Stage::getModeLabels()[$row['execution_mode']] ?? $row['execution_mode'],
                'planned'     => $reassigned ? $planned : $actual,
                'actual'      => $actual,
                'reassigned'  => $reassigned,
                'reason'      => (string) ($row['reassign_reason'] ?? ''),
                'closed_by'   => $closed_by,
                'date_start'  => $row['date_start'] ? Html::convDateTime((string) $row['date_start']) : '',
                'date_end'    => $row['date_end'] ? Html::convDateTime((string) $row['date_end']) : '',
                'date_due'    => $row['date_due'] ? Html::convDateTime((string) $row['date_due']) : '',
                'overdue'     => self::wasOverdue($row),
                'duration'    => (int) $row['duration'] > 0
                    ? Html::timestampToString((int) $row['duration'], false) : '',
                'result'      => $result,
                'comment'     => (string) ($row['comment'] ?? ''),
                'state'       => (string) $row['state'],
                // Согласующего указали при прохождении: в документе для аудита
                // должно быть видно, кто выбрал и на каком основании.
                'approver_set_by' => (int) ($row['approver_set_by'] ?? 0) > 0
                    ? getUserName((int) $row['approver_set_by']) : '',
                'approver_reason' => (string) ($row['approver_reason'] ?? ''),
            ];
        }
        return $out;
    }

    /** Просрочен сейчас или был просрочен на момент закрытия. */
    public static function wasOverdue(array $row): bool
    {
        if (empty($row['date_due'])) {
            return false;
        }
        $due = strtotime((string) $row['date_due']);
        if ($row['state'] === Step::RUNNING) {
            return $due < time();
        }
        if (!empty($row['date_end'])) {
            return strtotime((string) $row['date_end']) > $due;
        }
        return false;
    }

    private static function actorName(int $groups_id, int $users_id): string
    {
        if ($groups_id > 0) {
            $g = new Group();
            if ($g->getFromDB($groups_id)) {
                return $g->fields['name'];
            }
        }
        if ($users_id > 0) {
            return getUserName($users_id);
        }
        return '';
    }

    public static function header(Instance $instance, CommonITILObject $item): array
    {
        $process = $instance->getProcess();
        $requester = '';
        foreach ($item->getUsers(\CommonITILActor::REQUESTER) as $r) {
            $requester = getUserName((int) $r['users_id']);
            break;
        }
        $done = 0;
        $total = 0;
        foreach ($instance->getSteps() as $s) {
            $total++;
            if (in_array($s['state'], [Step::DONE, Step::SKIPPED], true)) {
                $done++;
            }
        }
        return [
            'item_type'   => $item::getTypeName(1),
            'item_id'     => $item->getID(),
            'item_name'   => (string) $item->fields['name'],
            'entity'      => \Dropdown::getDropdownName('glpi_entities', (int) $item->fields['entities_id']),
            'requester'   => $requester,
            'process'     => $process ? $process->fields['name'] : '',
            'version'     => (int) $instance->fields['process_version'],
            'state'       => Instance::getStateLabels()[$instance->fields['state']] ?? '',
            'date_start'  => $instance->fields['date_start']
                ? Html::convDateTime((string) $instance->fields['date_start']) : '',
            'date_end'    => $instance->fields['date_end']
                ? Html::convDateTime((string) $instance->fields['date_end']) : '',
            'progress'    => sprintf('%d из %d', $done, $total),
            'printed'     => Html::convDateTime(date('Y-m-d H:i:s')),
        ];
    }
}
