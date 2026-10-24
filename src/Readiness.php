<?php

namespace GlpiPlugin\Itilflow;

/**
 * Строка готовности маршрута: только чтение, без блокировок и записи.
 *
 * Проверки — те же, что делает плагин при запуске (DefinitionSnapshot::valid,
 * Stage::validateInput, движок при открытии этапа), плюс три, которых при
 * запуске нет: правило запуска не видит маршрут, группа не видна в организации,
 * маршрут выключен. Строка про этап несёт
 * ссылку на этап.
 *
 * Каждая проблема: text, url, link, stage (id этапа или null) и snapshot —
 * не пропустит ли её и проверка определения при запуске.
 */
final class Readiness
{
    /**
     * $stages — этапы с черновиком вместо сохранённого (пересчёт без сохранения, front/draft.php);
     * null — этапы маршрута из базы.
     *
     * @return array{ready:bool,title:string,problems:list<array>,notes:list<array>,rules:list<string>,stages:int}
     */
    public static function check(Process $process, ?array $stages = null): array
    {
        $p = $process->fields;
        $stages ??= $process->getStages();
        $structured = ($p['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;

        $problems = [];
        if (!$stages) {
            $problems[] = self::problem('Нет ни одного этапа.', null, $structured);
        }
        if (empty($p['is_active'])) {
            $problems[] = self::problem('Маршрут выключен — новые заявки его не запустят.', null, false);
        }
        $previews = self::approvalPreviews($p, $stages);
        $problems = array_merge($problems, self::stageProblems($p, $stages, $previews));
        foreach ($stages as $stage) {
            if (($stage['execution_mode'] ?? '') !== Stage::MODE_APPROVAL || ($stage['approver_source'] ?? '') !== Stage::APPROVER_FORM) { continue; }
            foreach (FormConfiguration::problems($p, $stage) as $text) {
                $problems[] = self::problem(self::stageName($stage) . ': ' . $text, (int) $stage['id'], false,
                    Stage::getFormURLWithID((int) $stage['id']), 'Открыть этап');
            }
        }

        $rules = self::rules($p);
        foreach ($rules as $rule) {
            $problem = self::ruleProblem($p, $rule);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        // Готовность «Запускать, если».
        $start = StartReadiness::check($p);
        foreach ($start['problems'] as $text) {
            $problems[] = self::problem($text, null, false);
        }

        // Страховка: то, что не пропустит проверка запуска, не может быть «готово».
        if ($structured && $stages && !array_filter($problems, static fn(array $x): bool => $x['snapshot'])
            && !DefinitionSnapshot::valid(DefinitionSnapshot::draft($p, $stages))) {
            $problems[] = self::problem('Маршрут не запустится: откройте этапы по очереди и сохраните заново.', null, true);
        }

        $notes = self::notes($stages, $previews);
        // 1.13.2: правило этого маршрута молча не запускает заявки форм с «Запускать, если» —
        // администратор маршрута видит это здесь, а не только в журнале каждой заявки.
        if ($rules !== []) {
            $line = StartReadiness::rulesSkipFormsLine(StartReadiness::formsChoosingRoute($p), count($rules));
            if ($line !== null) { $notes[] = self::problem($line, null, false); }
        }
        foreach ($start['notes'] as $text) {
            $notes[] = self::problem($text, null, false);
        }
        if ($structured) {
            $catalog = (int) ($p['forms_forms_id'] ?? 0) > 0 ? FormFacts::questionCatalog((int) $p['forms_forms_id'], false) : [];
            $lines = self::conditionLines($p, $stages, $catalog);
            foreach ($lines['problems'] as $x) { $problems[] = self::problem($x['text'], $x['stage'], $x['snapshot']); }
            foreach ($lines['notes'] as $x) { $notes[] = self::problem($x['text'], $x['stage'], false); }
        }
        foreach ($stages as $stage) {
            if (($stage['approver_source'] ?? '') !== Stage::APPROVER_FORM) { continue; }
            foreach (FormConfiguration::warnings($p, $stage) as $text) {
                $notes[] = ['text' => self::stageName($stage) . ': ' . $text, 'stage' => (int) $stage['id'],
                    'service' => false, 'snapshot' => false];
            }
        }
        $ready = $problems === [];
        $title = self::title(count($problems), count($stages), $start['title'], array_column($rules, 'name'));
        return ['ready' => $ready, 'title' => $title, 'problems' => $problems, 'notes' => $notes,
            'rules' => array_column($rules, 'name'), 'stages' => count($stages)];
    }

    /**
     * Заголовок строки готовности — коротко и только про этот маршрут:
     * «Готов к запуску · 4 этапа · запуск: правило «А»» / «… · запуск: правила (3)»,
     * без правил — «Готов к запуску · запуск вручную». $start — хвост запуска по форме или null.
     */
    public static function title(int $problems, int $stages, ?string $start, array $rules): string
    {
        if ($problems > 0) {
            return 'Не готов к запуску: ' . $problems . ' ' . self::plural($problems, 'проблема', 'проблемы', 'проблем');
        }
        $head = 'Готов к запуску · ' . $stages . ' ' . self::plural($stages, 'этап', 'этапа', 'этапов');
        if ($start !== null) {
            return $head . ' · ' . $start;
        }
        if ($rules !== []) {
            return $head . ' · запуск: ' . (count($rules) === 1 ? 'правило «' . reset($rules) . '»' : 'правила (' . count($rules) . ')');
        }
        return 'Готов к запуску · запуск вручную';
    }

    /**
     * Первая причина, по которой определение не пройдёт проверку запуска.
     * Для сообщения при сохранении маршрута нового порядка.
     */
    public static function firstSnapshotReason(array $process, array $stages): ?string
    {
        if (DefinitionSnapshot::valid(DefinitionSnapshot::draft($process, $stages))) {
            return null;
        }
        if (!$stages) {
            return 'Нет ни одного этапа.';
        }
        foreach (self::stageProblems($process, $stages) as $x) {
            if ($x['snapshot']) {
                return $x['text'];
            }
        }
        return 'Маршрут не запустится: откройте этапы по очереди и сохраните заново.';
    }

    /** Проблемы одного этапа — для формы этапа. */
    public static function forStage(Process $process, int $stages_id): array
    {
        $split = self::forStageSplit($process, $stages_id);
        return array_merge($split['problems'], $split['notes']);
    }

    /**
     * Строки готовности одного этапа для формы этапа: problems — «Этот этап
     * не готов», notes — «Проверьте этот этап». $stages — с черновиком (front/draft.php).
     *
     * @return array{problems:list<array>,notes:list<array>}
     */
    public static function forStageSplit(Process $process, int $stages_id, ?array $stages = null): array
    {
        $check = self::check($process, $stages);
        $mine = static fn(array $list): array => array_values(array_filter($list,
            static fn(array $x): bool => ($x['stage'] ?? null) === $stages_id));
        return ['problems' => $mine($check['problems']), 'notes' => $mine($check['notes'])];
    }

    /**
     * Строки готовности по условиям этапов;
     * везде названия, а не номера. Без базы: $catalog — FormFacts::questionCatalog формы маршрута.
     *
     * @return array{problems:list<array{text:string,stage:?int,snapshot:bool}>,notes:list<array{text:string,stage:?int,snapshot:bool}>}
     */
    public static function conditionLines(array $p, array $stages, array $catalog): array
    {
        $problems = []; $notes = [];
        $line = static fn(string $text, ?int $stage, bool $snapshot = false): array => ['text' => $text, 'stage' => $stage, 'snapshot' => $snapshot];
        $byId = [];
        foreach ($stages as $stage) { $byId[(int) $stage['id']] = $stage; }
        $told = [];
        foreach ($stages as $stage) {
            $id = (int) $stage['id'];
            $condition = BranchConfiguration::decode($stage['run_if'] ?? null) ?? [];
            foreach (BranchConfiguration::validateSnapshotStage($stage, $stages, $p) as $error) {
                $problems[] = $line(self::stageName($stage) . ': ' . self::lower($error), $id, true);
            }
            foreach (is_array($condition['clauses'] ?? null) ? $condition['clauses'] : [] as $i => $clause) {
                if (!is_array($clause)) { continue; }
                if (($clause['source'] ?? '') === 'stage_not_needed' && isset($byId[(int) ($clause['stage_id'] ?? 0)])) {
                    $target = $byId[(int) $clause['stage_id']];
                    if (((BranchConfiguration::decode($target['run_if'] ?? null) ?? [])['clauses'] ?? []) === []) {
                        $notes[] = $line(self::stageName($stage) . ': условие ' . ((int) $i + 1) . ' не выполнится: этап ' . (int) $target['ranking']
                            . ' «' . $target['name'] . '» нужен всегда.', $id);
                    }
                }
                if (($clause['source'] ?? '') !== 'form_answer') { continue; }
                $label = (string) ($clause['question']['label'] ?? 'вопрос формы');
                $current = $catalog[$clause['question_uuid'] ?? ''] ?? null;
                if ($current === null || FormFacts::validateMapping($clause['question'] ?? [], $current) !== []) {
                    $problems[] = $line(self::stageName($stage) . ': вопрос формы «' . $label . '» удалён или изменён. Проверьте условие и сохраните этап.', $id);
                    continue;
                }
                // С 1.13 пустой видимый ответ у текста, файла, даты, объекта и флажка известен
                // («не заполнено», «не приложен», «не отмечен»): ответа нет, только когда вопрос скрыт.
                $emptyKnown = in_array($current['type'] ?? '', ['text', 'file', 'date', 'ref', 'bool'], true);
                $why = !empty($current['conditional']) ? 'бывает скрыт' : (!$emptyKnown && empty($current['required']) ? 'необязательный' : null);
                if ($why !== null && !isset($told[$clause['question_uuid']]) && ($clause['operator'] ?? '') !== 'unknown') {
                    $told[$clause['question_uuid']] = true;
                    $notes[] = $line(self::stageName($stage) . ': вопрос «' . $label . '» ' . $why
                        . ' — сработает «Если ответа нет».', $id);
                }
            }
            if ((($stage['execution_mode'] ?? '') === Stage::MODE_APPROVAL
                || (($stage['execution_mode'] ?? '') === Stage::MODE_DELEGATED && ($stage['department_result_source'] ?? '') === 'native_validation'))
                && BranchConfiguration::applicantControls($condition)) {
                $notes[] = $line(self::stageName($stage) . ': согласование отключается ответом инициатора — лучше вопросом этапа.', $id);
            }
        }
        $startRule = StartRule::decode($p['start_if'] ?? null);
        foreach (BranchConfiguration::uncoveredOptions($stages, ($startRule['mode'] ?? '') === StartRule::MODE_CONDITIONS ? $startRule['rule'] : []) as $gap) {
            $notes[] = $line('Вопрос «' . $gap['question'] . '»: ' . (count($gap['options']) === 1
                ? 'вариант «' . $gap['options'][0] . '» не ведёт ни к одному этапу.'
                : 'варианты «' . implode('», «', $gap['options']) . '» не ведут ни к одному этапу.')
                . ' Добавьте его в условие или выберите «ни один из».', $gap['stage'] ?? null);
        }
        return ['problems' => $problems, 'notes' => $notes];
    }

    /** Ошибка после «Этап N «…»: » — со строчной буквы. */
    private static function lower(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Проблемы этапов: исполнитель, организация, исходы, точки возврата, порог, порядок. */
    public static function stageProblems(array $p, array $stages, ?array $previews = null): array
    {
        $previews ??= self::approvalPreviews($p, $stages);
        $structured = ($p['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;
        $out = [];
        $ids = [];
        foreach ($stages as $s) {
            $ids[(int) $s['id']] = $s;
        }
        $ranks = [];
        foreach ($stages as $s) {
            $id = (int) $s['id'];
            $mode = (string) ($s['execution_mode'] ?? '');
            $name = self::stageName($s);
            $ranks[(int) $s['ranking']][] = $s;

            if ((int) $s['ranking'] <= 0) {
                $out[] = self::problem('Этап «' . $s['name'] . '» — номер «Порядок» должен быть больше нуля.', $id, $structured);
            }
            if (!array_key_exists($mode, Stage::getModeLabels())) {
                $out[] = self::problem($name . ' — не выбрано, как выполняется этап.', $id, $structured);
                continue;
            }

            // Исполнитель: группа или сотрудник, кроме согласования «при прохождении».
            $runtime = $mode === Stage::MODE_APPROVAL && ($s['approver_source'] ?? Stage::APPROVER_FIXED) !== Stage::APPROVER_FIXED;
            $gid = (int) ($s['groups_id'] ?? 0);
            $uid = (int) ($s['users_id'] ?? 0);
            $group = new \Group();
            $group_ok = $gid > 0 && $group->getFromDB($gid);
            $user = new \User();
            $user_ok = $uid > 0 && $user->getFromDB($uid) && empty($user->fields['is_deleted']);
            // Согласующие «Указан в этапе» — списки (1.11); незаполненные списки — прежние «группа / сотрудник».
            $who_ok = $group_ok || $user_ok;
            $lists = null;
            if ($mode === Stage::MODE_APPROVAL) {
                $lists = DefinitionSnapshot::approvers($s);
                if ($lists === null) {
                    $out[] = self::problem($name . ' — список согласующих повреждён: выберите заново.', $id, $structured);
                } elseif (!$lists['legacy']) {
                    $who_ok = false;
                    foreach ($lists['users'] as $list_uid) {
                        $list_user = new \User();
                        $who_ok = $who_ok || ($list_user->getFromDB($list_uid) && empty($list_user->fields['is_deleted']));
                    }
                    foreach ($lists['groups'] as $list_gid) {
                        $who_ok = $who_ok || (new \Group())->getFromDB($list_gid);
                    }
                }
            }
            if (!$runtime && !$who_ok) {
                $out[] = self::problem($name . ($mode === Stage::MODE_APPROVAL ? ' — ' . Stage::CHOOSE_APPROVERS
                    : ' — не выбран исполнитель: группа или пользователь.'), $id,
                    $structured && $mode === Stage::MODE_DELEGATED && $gid <= 0 && $uid <= 0);
            }
            if (isset($previews[$id])) {
                foreach ($previews[$id]['errors'] as $error) {
                    // Согласующих не выбрали — «Нет доступных согласующих» только повторило бы строку выше.
                    if (!$runtime && !$who_ok && $error === ApprovalRoster::NO_TARGETS) { continue; }
                    $out[] = self::problem($name . ' — ' . $error, $id, false);
                }
            }
            // Срок и SLA, сохранённые до 1.13 (без проверки при сохранении), — теми же словами.
            foreach (self::deadlineProblems($s, $p) as $error) {
                $out[] = self::problem($name . ' — ' . mb_strtolower(mb_substr($error, 0, 1)) . mb_substr($error, 1), $id, false);
            }
            // Запуск не останавливает: идущие и новые заявки получают запрос группе, как прежде.
            // Заполненные списки прежние поля не читают — там противоречия нет.
            if (Stage::approverConflict($s) && ($lists['legacy'] ?? true)) {
                $out[] = self::problem($name . ' — указаны и согласующая группа, и пользователь: запрос получит только группа. '
                    . 'Оставьте что-то одно.', $id, false);
            }

            // Дочерняя заявка: организация и видимость группы в ней.
            if ($mode === Stage::MODE_DELEGATED) {
                $strategy = (string) ($s['entity_strategy'] ?? 'inherit');
                $entity = null;
                if ($strategy === 'fixed') {
                    $e = new \Entity();
                    if ($e->getFromDB((int) $s['entities_id_target'])) {
                        $entity = (int) $s['entities_id_target'];
                    } else {
                        $out[] = self::problem($name . ' — не выбрана организация для дочерней заявки.', $id, false);
                    }
                } elseif ($strategy !== 'by_group') {
                    $entity = (int) ($p['entities_id'] ?? 0);
                }
                if ($entity !== null && $group_ok) {
                    $stage = new Stage();
                    $stage->fields = $s;
                    if (!$stage->groupFitsEntity($entity)) {
                        $out[] = self::problem(sprintf('%s — группа «%s» не видна в организации «%s»: маршрут остановится на этом этапе.',
                            $name, $group->fields['name'], \Dropdown::getDropdownName('glpi_entities', $entity)), $id, false);
                    }
                }
            }

            if ($structured) {
                if ($mode === Stage::MODE_DELEGATED
                    && !in_array($s['department_result_source'] ?? '', ['assignee_action', 'native_validation'], true)) {
                    $out[] = self::problem($name . ' — не выбрано, как отдел сообщает результат.', $id, true);
                }
                if (!in_array($s['negative_policy'] ?? '', [Stage::REJECT_BLOCK, Stage::REJECT_ABORT], true)) {
                    $out[] = self::problem($mode === Stage::MODE_INLINE
                        ? $name . ' — не заполнено, что делать при отказе: откройте этап и сохраните его.'
                        : $name . ' — не выбрано, что делать при отказе.', $id, true);
                }
                $targets = DefinitionSnapshot::targets($s['return_targets'] ?? null);
                if ($targets === null) {
                    $out[] = self::problem($name . ' — этапы для возврата повреждены: отметьте заново.', $id, true);
                } else {
                    foreach ($targets as $t) {
                        $target = $ids[$t] ?? null;
                        if ($target === null || (int) $target['ranking'] > (int) $s['ranking']) {
                            $label = $target === null ? 'удалённый этап' : $target['ranking'] . '. ' . $target['name'];
                            $out[] = self::problem($name . ' — этап для возврата «' . $label . '» стоит позже или удалён.', $id, true);
                        }
                    }
                }
                if (($mode === Stage::MODE_APPROVAL
                        || ($mode === Stage::MODE_DELEGATED && ($s['department_result_source'] ?? '') === 'native_validation'))
                    && ((int) ($s['approval_percent'] ?? 0) < 1 || (int) $s['approval_percent'] > 100)) {
                    $out[] = self::problem($name . ' — «Нужно одобрений»: укажите от 1 до 100 %.', $id, true);
                }
            } elseif ($mode !== Stage::MODE_INLINE && ($s['on_reject'] ?? '') === Stage::REJECT_BACK
                && !isset($ids[(int) ($s['plugin_itilflow_stages_id_reject'] ?? 0)])) {
                $out[] = self::problem($name . ' — «Вернуть на доработку на этап», но этап не выбран.', $id, false);
            }
        }
        foreach ($ranks as $rank => $same) {
            if (count($same) > 1) {
                $out[] = self::problem(sprintf('Этапы %d и %d — одинаковый порядок.', $rank, $rank), (int) $same[1]['id'], $structured);
            }
        }
        return $out;
    }

    /** Активные правила запуска с этим маршрутом. */
    private static function rules(array $p): array
    {
        global $DB;
        $class = ['Ticket' => 'RuleTicket', 'Change' => 'RuleChange', 'Problem' => 'RuleProblem'][$p['itemtype'] ?? 'Ticket'] ?? 'RuleTicket';
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['r.id', 'r.name', 'r.entities_id', 'r.is_recursive'],
            'FROM'   => 'glpi_rules AS r',
            'INNER JOIN' => ['glpi_ruleactions AS a' => ['ON' => ['a' => 'rules_id', 'r' => 'id']]],
            'WHERE'  => ['r.sub_type' => $class, 'r.is_active' => 1, 'a.field' => '_plugin_itilflow_processes_id',
                'a.action_type' => 'assign', 'a.value' => (string) (int) ($p['id'] ?? 0)],
            'ORDER'  => 'r.id',
        ]) as $row) {
            $out[(int) $row['id']] = $row + ['class' => $class];
        }
        return array_values($out);
    }

    /** Правило описано там, где маршрут не виден, или шире маршрута. */
    private static function ruleProblem(array $p, array $rule): ?array
    {
        $pe = (int) ($p['entities_id'] ?? 0);
        $re = (int) $rule['entities_id'];
        $recursive = !empty($p['is_recursive']);
        $visible = $pe === $re || ($recursive && in_array($pe, array_map('intval', getAncestorsOf('glpi_entities', $re)), true));
        $url = $rule['class']::getFormURLWithID((int) $rule['id']);
        if (!$visible) {
            return self::problem(sprintf('Правило «%s» (организация «%s») не видит маршрут: включите «Подчинённые организации» или перенесите маршрут выше.',
                $rule['name'], \Dropdown::getDropdownName('glpi_entities', $re)), null, false, $url, 'Открыть правило');
        }
        if (!empty($rule['is_recursive']) && !$recursive && count(getSonsOf('glpi_entities', $re)) > 1) {
            return self::problem(sprintf('Правило «%s» работает и в подчинённых организациях «%s», а маршрут там не виден: включите «Подчинённые организации».',
                $rule['name'], \Dropdown::getDropdownName('glpi_entities', $re)), null, false, $url, 'Открыть правило');
        }
        return null;
    }

    /**
     * Не мешает запуску. О выключенном автоматическом действии смены статуса строка готовности
     * не говорит: это предупреждение одно на все маршруты — в списке маршрутов (front/process.php).
     */
    private static function notes(array $stages, array $previews): array
    {
        return self::approvalWarnings($stages, $previews);
    }

    /** Тот же состав, что перед отправкой; только чтение в организации маршрута. */
    private static function approvalPreviews(array $p, array $stages): array
    {
        $out = [];
        foreach ($stages as $stage) {
            if (($stage['execution_mode'] ?? '') !== Stage::MODE_APPROVAL
                || ($stage['approver_source'] ?? Stage::APPROVER_FIXED) !== Stage::APPROVER_FIXED) { continue; }
            try {
                $out[(int) $stage['id']] = ApprovalRoster::previewForEntity($stage,
                    (int) ($p['entities_id'] ?? 0), (string) ($p['itemtype'] ?? ''));
            } catch (\Throwable) {
                TransactionScope::assertHealthy();
                $out[(int) $stage['id']] = ['ok' => false, 'count' => 0, 'warnings' => [],
                    'errors' => ['Не удалось проверить согласующих. Их проверят ещё раз при отправке.']];
            }
        }
        return $out;
    }

    /** Предупреждение не запрещает запуск: доступность снова проверяется перед отправкой. */
    public static function approvalWarnings(array $stages, array $previews): array
    {
        $notes = [];
        foreach ($stages as $stage) {
            $id = (int) $stage['id'];
            $preview = $previews[$id] ?? null;
            if ($preview === null) { continue; }
            $name = self::stageName($stage);
            foreach ($preview['warnings'] ?? [] as $warning) {
                $notes[] = ['text' => $name . ' — ' . $warning, 'stage' => $id, 'service' => false, 'snapshot' => false];
            }
            $m = (int) ($preview['count'] ?? 0);
            $p = (int) ($stage['approval_percent'] ?? 0);
            if (($preview['ok'] ?? false) && $m > 0 && $m <= ApprovalRoster::LIMIT && $p >= 1 && $p <= 100) {
                $threshold = ApprovalRoster::threshold($m, $p);
                if ($threshold['can_stall']) {
                    $notes[] = ['text' => $name . ' — нужно ' . $threshold['needed'] . ' из ' . $m
                        . ': GLPI может не подвести итог, тогда этап закроет администратор маршрутов.',
                        'stage' => $id, 'service' => false, 'snapshot' => false];
                }
            }
        }
        return $notes;
    }

    private static function problem(string $text, ?int $stage, bool $snapshot, ?string $url = null, string $link = ''): array
    {
        if ($stage !== null && $url === null) {
            $url = \Plugin::getWebDir('itilflow') . '/front/stage.form.php?id=' . $stage;
            $link = 'Открыть этап';
        }
        return ['text' => $text, 'url' => $url, 'link' => $link, 'stage' => $stage, 'snapshot' => $snapshot];
    }

    /**
     * Срок этапа и SLA дочерней заявки, которые база не сохранит (после 18.01.2038): те же проверки
     * и слова, что при сохранении этапа (Stage::validateInput). Этапы, сохранённые до 1.13, эту
     * проверку не проходили — без строки готовности о них узнавали только по остановке маршрута.
     * $sla, $childCalendar — подмена для проверок без базы.
     *
     * @return list<string>
     */
    public static function deadlineProblems(array $s, array $p, ?\Closure $sla = null, ?\Closure $childCalendar = null): array
    {
        $out = [];
        $minutes = (int) ($s['deadline_minutes'] ?? 0);
        if ($minutes > 0 && ($error = Deadline::durationError($minutes, (int) ($p['calendars_id'] ?? 0))) !== null) {
            $out[] = $error;
        }
        if (($s['execution_mode'] ?? '') === Stage::MODE_DELEGATED && (int) ($s['slas_id'] ?? 0) > 0) {
            $sla ??= static fn(int $slas, int $calendar): ?string => Deadline::slaError($slas, $calendar);
            $childCalendar ??= static fn(array $stage, array $process): int => Stage::childCalendar($stage, $process);
            $error = $sla((int) $s['slas_id'], $childCalendar($s, $p));
            if ($error !== null) { $out[] = $error; }
        }
        return $out;
    }

    private static function stageName(array $s): string
    {
        return 'Этап ' . (int) $s['ranking'] . ' «' . $s['name'] . '»';
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n10 = $n % 10;
        $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) {
            return $one;
        }
        return $n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14) ? $few : $many;
    }
}
