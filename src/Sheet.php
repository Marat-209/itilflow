<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use Group;
use Html;

/**
 * Лист согласования (маршрутный лист) — печатная сводка маршрута:
 * кто, когда, что сделал, кому передавал, уложился ли в срок.
 */
final class Sheet
{
    /**
     * Чистая сводка: завершённость не означает, что этап потребовался.
     *
     * Категории required, not_needed, undetermined, skipped_before_start и not_run не пересекаются
     * и вместе дают total. interrupted — отметка внутри required: этап начали, но маршрут его бросил.
     * Брошенный до начала этап — только not_run («не выполнялся»): он не «ещё не ясен» и не
     * «не закончен» (раньше такой этап считался дважды).
     * Этап в очереди без условия «Когда выполнять» точно понадобится — он required; «ещё не ясно»
     * (undetermined) — только этап в очереди с условием: строка с отметкой conditional (progressFor).
     * history — прежние попытки, а не их строки: одна попытка, заменённая новой, — одна, сколько бы
     * этапов в ней ни было (у строк одной попытки один superseded_by_attempt_id).
     */
    public static function requiredProgress(array $rows): array
    {
        $result = ['total' => 0, 'completed' => 0, 'required' => 0, 'not_needed' => 0,
            'undetermined' => 0, 'skipped_before_start' => 0, 'not_run' => 0, 'history' => 0, 'interrupted' => 0];
        $prior = [];
        foreach ($rows as $row) {
            $state = $row['state'] ?? '';
            if (!empty($row['superseded_by_attempt_id'])) {
                $prior[(string) $row['superseded_by_attempt_id']] = true;
                $result['history'] = count($prior);
                continue;
            }
            $result['total']++;
            if (in_array($state, [Step::DONE, Step::SKIPPED, Step::NOT_NEEDED], true)) {
                $result['completed']++;
            }
            $started = !empty($row['date_start']);
            if ($state === Step::NOT_NEEDED) {
                $result['not_needed']++;
            } elseif (in_array($state, [Step::RUNNING, Step::DONE, Step::REJECTED], true)
                || (in_array($state, [Step::SKIPPED, Step::SUPERSEDED], true) && $started)) {
                $result['required']++;
                // Прерывание начатого этапа не равно замене его новой попыткой.
                if ($state === Step::SUPERSEDED) { $result['interrupted']++; }
            } elseif ($state === Step::SKIPPED) {
                $result['skipped_before_start']++;
            } elseif ($state === Step::SUPERSEDED) {
                $result['not_run']++;
            } elseif ($state === Step::PENDING && empty($row['conditional'])) {
                // Безусловный этап в очереди будет нужен.
                $result['required']++;
            } else {
                // Этап в очереди с условием и неизвестное состояние не доказывают требуемость.
                $result['undetermined']++;
            }
        }
        return $result;
    }

    /**
     * Этапы запуска с условием «Когда выполнять» — по снимку, с которым идёт запуск (как
     * Ui::stageRule). У запусков без снимка схемы 3 условий нет.
     *
     * @return list<int> id этапов
     */
    public static function conditionalStages(Instance $instance): array
    {
        $snapshot = DefinitionSnapshot::load($instance);
        if ($snapshot === null || (int) ($snapshot['schema'] ?? 0) < 3) { return []; }
        $out = [];
        foreach ($snapshot['stages'] ?? [] as $stage) {
            $raw = $stage['run_if'] ?? null;
            $rule = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
            if (is_array($rule) && is_array($rule['clauses'] ?? null) && $rule['clauses'] !== []) { $out[] = (int) $stage['id']; }
        }
        return $out;
    }

    /** Отметка conditional у строк этапов: этап с условием «Когда выполнять» из $conditional. */
    public static function markConditional(array $rows, array $conditional): array
    {
        foreach ($rows as &$row) {
            $row['conditional'] = in_array((int) ($row['plugin_itilflow_stages_id'] ?? 0), $conditional, true);
        }
        unset($row);
        return $rows;
    }

    /** Сводка требуемости этапов одного запуска. */
    public static function progressFor(Instance $instance): array
    {
        return self::requiredProgress(self::markConditional($instance->getSteps(), self::conditionalStages($instance)));
    }

    /**
     * Подпись результата строки листа — те же слова, что в строке этапа на заявке. У этапа
     * согласования: одобрен — «Согласовано», отказ — «Отклонено» (слово GLPI); закрыт принудительно,
     * без ответа согласующих — «Выполнено»: «Согласовано» было бы неправдой. $source — Outcome::source_kind.
     */
    public static function resultLabel(array $row, ?string $outcome, bool $finished, ?string $source = null): string
    {
        $approval = ($row['execution_mode'] ?? '') === Stage::MODE_APPROVAL;
        $done = $approval && !self::forcedClose($row, $source) ? Terms::APPROVED : Terms::DONE;
        if ($outcome !== null) {
            return match ($outcome) {
                Outcome::COMPLETED => $done,
                Outcome::REFUSED => $approval ? Terms::REJECTED : Terms::REFUSAL,
                Outcome::CLARIFICATION => Terms::ON_CLARIFICATION,
                default => 'Результат неизвестен',
            };
        }
        return match ($row['state'] ?? '') {
            Step::DONE => $done,
            Step::SKIPPED => Terms::SKIPPED,
            Step::NOT_NEEDED => Terms::NOT_NEEDED,
            Step::REJECTED => $approval ? Terms::REJECTED : Terms::REFUSAL,
            Step::RUNNING => Engine::isOverdue($row) ? Terms::IN_PROGRESS . ', срок истёк' : Terms::IN_PROGRESS,
            Step::SUPERSEDED => Step::stateLabel($row),
            default => $finished ? 'Не выполнялся' : Terms::AHEAD,
        };
    }

    /**
     * Комментарий согласующего к его ответу (исход native_validation, поле reason) — коротко, одной
     * строкой, для листа согласования рядом с «Согласовано» / «Отклонено». Пусто — комментария нет
     * или результат дал не ответ на запрос согласования.
     */
    public static function approverComment(array $outcome, int $max = 200): string
    {
        if (($outcome['source_kind'] ?? '') !== 'native_validation') { return ''; }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($outcome['reason'] ?? '')),
            ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    private static function sameText(string $a, string $b): bool
    {
        $norm = static fn(string $s): string => trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
        return $norm($a) !== '' && $norm($a) === $norm($b);
    }

    /**
     * Этап закрыт «Закрыть принудительно…»: у нового порядка — исход privileged_override,
     * у прежнего — комментарий этапа «Принудительное завершение: …» (Engine::overrideStep).
     */
    public static function forcedClose(array $row, ?string $source): bool
    {
        return $source === 'privileged_override'
            || str_starts_with(trim(strip_tags((string) ($row['comment'] ?? ''))), 'Принудительное завершение:');
    }

    public static function rows(Instance $instance): array
    {
        $out = [];
        $structured = DefinitionSnapshot::enabled($instance);
        $stages = Ui::snapshotStages($instance);
        $finished = in_array($instance->fields['state'], [Instance::DONE, Instance::ABORTED], true);
        foreach ($instance->getSteps() as $row) {
            $approvalStep = new Step();
            $approvalStep->fields = $row;
            $planned = self::actorName((int) $row['groups_id_from'], (int) $row['users_id_from']);
            $actual = $row['execution_mode'] === Stage::MODE_APPROVAL
                ? Ui::owner($approvalStep, $row) : self::actorName((int) $row['groups_id'], (int) $row['users_id']);
            if ($row['execution_mode'] === Stage::MODE_APPROVAL) { $planned = $actual; }
            $reassigned = $planned !== '' && $planned !== $actual;

            $closed_by = (int) $row['users_id_actual'] > 0
                ? getUserName((int) $row['users_id_actual']) : '';

            $outcome = $structured ? Outcome::forStep((int) $row['id']) : null;
            $result = self::resultLabel($row, $outcome?->fields['outcome'] ?? null, $finished, $outcome?->fields['source_kind'] ?? null);
            $public_comment = (string) ($row['comment'] ?? '');
            $approver_comment = '';
            if ($outcome !== null) {
                // Ответ согласующего — рядом с «Согласовано» / «Отклонено»; в «Что сделано» не повторяем
                // ни метку («Согласовано.»), ни тот же комментарий.
                $approver_comment = self::approverComment($outcome->fields);
                $public_comment = StepLine::withoutRepeat($result, trim(strip_tags((string) ($outcome->fields['public_summary'] ?? ''))));
                if ($approver_comment !== '' && self::sameText($public_comment, (string) $outcome->fields['reason'])) { $public_comment = ''; }
                if (!empty($outcome->fields['explanation_pending'])) { $result .= ' — ждёт ответа инициатору'; }
                $closed_by = (int) $outcome->fields['users_id'] > 0 ? getUserName((int) $outcome->fields['users_id']) : '';
            }
            if ($structured && !empty($row['superseded_by_attempt_id']) && $result !== Terms::PREVIOUS_ATTEMPT) {
                $result .= ' (прежняя попытка)';
            }

            // Почему этап не нужен и при каком условии выполнится будущий.
            $why = '';
            if ($row['state'] === Step::NOT_NEEDED) {
                $line = Ui::notNeededLine($instance, $approvalStep, $row, false, $stages);
                $why = $line['summary'];
                if ($line['author'] !== null) {
                    $why .= ($why !== '' ? ' ' : '') . 'Выбрал: ' . $line['author'] . ($line['date'] !== null ? ', ' . $line['date'] : '') . '.';
                }
            } elseif ($row['state'] === Step::PENDING && !$finished) {
                // Этап не открылся — причина в комментарии этапа; это не отчёт о выполнении.
                $why = empty($row['date_start']) ? StepLine::notStarted(strip_tags($public_comment)) : '';
                if ($why !== '') { $public_comment = ''; }
                else { $why = Ui::aheadLine($approvalStep, false, $stages)['summary']; }
            }

            $duration = $structured ? Step::elapsedSeconds($row) : (int) $row['duration'];
            if ($row['state'] === Step::NOT_NEEDED) { $duration = null; }
            $answers = Ui::decisionSummary($approvalStep);
            $out[] = [
                'approval' => Ui::approvalInfo($approvalStep),
                'decisions' => $answers,
                'why'       => $why,
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
                'duration' => $duration !== null && ($duration > 0 || $structured)
                    ? Ui::duration($duration) : '—',
                'result'      => $result,
                'approver_comment' => $approver_comment,
                'comment'     => $public_comment,
                'state'       => (string) $row['state'],
                // Согласующего указали при прохождении маршрута: в документе для аудита
                // должно быть видно, кто выбрал и почему.
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
        if (in_array($row['state'], [Step::SUPERSEDED, Step::NOT_NEEDED], true)) { return false; }
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
        $progress = self::progressFor($instance);
        return [
            'item_type'   => $item::getTypeName(1),
            'item_id'     => $item->getID(),
            'item_name'   => (string) $item->fields['name'],
            'entity'      => \Dropdown::getDropdownName('glpi_entities', (int) $item->fields['entities_id']),
            'requester'   => $requester,
            'process'     => $process ? $process->fields['name'] : '',
            'version'     => (int) $instance->fields['process_version'],
            // То же слово, что на вкладке заявки: отказ — «Отказ», а не «Прерван».
            'state'       => Ui::stateWord($instance, $item),
            'date_start'  => $instance->fields['date_start']
                ? Html::convDateTime((string) $instance->fields['date_start']) : '',
            'date_end'    => $instance->fields['date_end']
                ? Html::convDateTime((string) $instance->fields['date_end']) : '',
            'progress'    => sprintf('Закончено этапов: %d из %d', $progress['completed'], $progress['total']),
            'required_progress' => $progress,
            'printed'     => Html::convDateTime(date('Y-m-d H:i:s')),
        ];
    }
}
