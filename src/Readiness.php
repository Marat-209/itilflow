<?php

namespace GlpiPlugin\Itilflow;

use Session;

/**
 * Строка готовности маршрута: только чтение, без блокировок и записи.
 *
 * Проверки — те же, что делает плагин при запуске (DefinitionSnapshot::valid,
 * Stage::validateInput, движок при открытии этапа), плюс три, которых при
 * запуске нет: правило запуска не видит маршрут, группа не видна в организации,
 * маршрут выключен. Строка про этап несёт
 * ссылку на этап. Служебная строка про автоматическое действие — только
 * администратору GLPI (право «Настройка: изменение»), остальным — следствие
 * простыми словами.
 *
 * Каждая проблема: text, url, link, stage (id этапа или null) и snapshot —
 * не пропустит ли её и проверка определения при запуске.
 */
final class Readiness
{
    /**
     * @return array{ready:bool,title:string,problems:list<array>,notes:list<array>,rules:list<string>,stages:int}
     */
    public static function check(Process $process): array
    {
        $p = $process->fields;
        $stages = $process->getStages();
        $structured = ($p['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;

        $problems = [];
        if (!$stages) {
            $problems[] = self::problem('Нет ни одного этапа.', null, $structured);
        }
        if (empty($p['is_active'])) {
            $problems[] = self::problem('Маршрут выключен — новые заявки его не запустят.', null, false);
        }
        $problems = array_merge($problems, self::stageProblems($p, $stages));

        $rules = self::rules($p);
        foreach ($rules as $rule) {
            $problem = self::ruleProblem($p, $rule);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        // Страховка: то, что не пропустит проверка запуска, не может быть «готово».
        if ($structured && $stages && !array_filter($problems, static fn(array $x): bool => $x['snapshot'])
            && !DefinitionSnapshot::valid(['schema' => 1, 'process' => $p, 'stages' => $stages])) {
            $problems[] = self::problem('Маршрут не пройдёт проверку при запуске: откройте этапы и сохраните их заново.', null, true);
        }

        $notes = self::notes($p, $stages, $structured);
        $ready = $problems === [];
        if (!$ready) {
            $title = 'Не готов к запуску — не хватает: ' . count($problems);
        } elseif ($rules) {
            $names = array_map(static fn(array $r): string => '«' . $r['name'] . '»', $rules);
            $title = 'Готов к запуску · ' . count($stages) . ' ' . self::plural(count($stages), 'этап', 'этапа', 'этапов')
                . ' · ' . (count($names) === 1 ? 'запускает правило ' : 'запускают правила ') . implode(', ', $names);
        } else {
            $title = 'Готов к запуску · правила запуска нет: маршрут запускают вручную на вкладке заявки.';
        }
        return ['ready' => $ready, 'title' => $title, 'problems' => $problems, 'notes' => $notes,
            'rules' => array_column($rules, 'name'), 'stages' => count($stages)];
    }

    /**
     * Первая причина, по которой определение не пройдёт проверку запуска.
     * Для сообщения при сохранении маршрута нового порядка.
     */
    public static function firstSnapshotReason(array $process, array $stages): ?string
    {
        if (DefinitionSnapshot::valid(['schema' => 1, 'process' => $process, 'stages' => $stages])) {
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
        return 'Маршрут не пройдёт проверку при запуске: откройте этапы и сохраните их заново.';
    }

    /** Проблемы одного этапа — для формы этапа. */
    public static function forStage(Process $process, int $stages_id): array
    {
        return array_values(array_filter(self::check($process)['problems'],
            static fn(array $x): bool => $x['stage'] === $stages_id));
    }

    /** Проблемы этапов: исполнитель, организация, исходы, точки возврата, порог, порядок. */
    public static function stageProblems(array $p, array $stages): array
    {
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
                $out[] = self::problem('Этап «' . $s['name'] . '» — порядок должен быть больше нуля.', $id, $structured);
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
            if (!$runtime && !$group_ok && !$user_ok) {
                $out[] = self::problem($name . ' — не выбран исполнитель: группа или сотрудник.', $id,
                    $structured && $mode === Stage::MODE_DELEGATED && $gid <= 0 && $uid <= 0);
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
                        $out[] = self::problem($name . ' — не выбрана организация для заявки отдела.', $id, false);
                    }
                } elseif ($strategy !== 'by_group') {
                    $entity = (int) ($p['entities_id'] ?? 0);
                }
                if ($entity !== null && $group_ok) {
                    $stage = new Stage();
                    $stage->fields = $s;
                    if (!$stage->groupFitsEntity($entity)) {
                        $out[] = self::problem(sprintf('%s — группа «%s» не видна в организации «%s»: заявка отдела встанет.',
                            $name, $group->fields['name'], \Dropdown::getDropdownName('glpi_entities', $entity)), $id, false);
                    }
                }
            }

            if ($structured) {
                if ($mode === Stage::MODE_DELEGATED
                    && !in_array($s['department_result_source'] ?? '', ['assignee_action', 'native_validation'], true)) {
                    $out[] = self::problem($name . ' — не выбрано, как отдел сообщает итог.', $id, true);
                }
                if (!in_array($s['negative_policy'] ?? '', [Stage::REJECT_BLOCK, Stage::REJECT_ABORT], true)) {
                    $out[] = self::problem($mode === Stage::MODE_INLINE
                        ? $name . ' — не заполнено, что делать при отказе: откройте этап и сохраните его.'
                        : $name . ' — не выбрано, что делать при отказе.', $id, true);
                }
                $targets = DefinitionSnapshot::targets($s['return_targets'] ?? null);
                if ($targets === null) {
                    $out[] = self::problem($name . ' — точки возврата записаны неверно: отметьте их заново.', $id, true);
                } else {
                    foreach ($targets as $t) {
                        $target = $ids[$t] ?? null;
                        if ($target === null || (int) $target['ranking'] > (int) $s['ranking']) {
                            $label = $target === null ? 'удалённый этап' : $target['ranking'] . '. ' . $target['name'];
                            $out[] = self::problem($name . ' — точка возврата «' . $label . '» стоит позже этапа или удалена.', $id, true);
                        }
                    }
                }
                if (($mode === Stage::MODE_APPROVAL
                        || ($mode === Stage::MODE_DELEGATED && ($s['department_result_source'] ?? '') === 'native_validation'))
                    && ((int) ($s['approval_percent'] ?? 0) < 1 || (int) $s['approval_percent'] > 100)) {
                    $out[] = self::problem($name . ' — сколько виз нужно: укажите от 1 до 100 %.', $id, true);
                }
            } elseif ($mode !== Stage::MODE_INLINE && ($s['on_reject'] ?? '') === Stage::REJECT_BACK
                && !isset($ids[(int) ($s['plugin_itilflow_stages_id_reject'] ?? 0)])) {
                $out[] = self::problem($name . ' — «Вернуться на этап», но этап для возврата не выбран.', $id, false);
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
            return self::problem(sprintf('Правило «%s» (организация «%s») не увидит маршрут: включите «Виден в подчинённых организациях» или перенесите маршрут выше.',
                $rule['name'], \Dropdown::getDropdownName('glpi_entities', $re)), null, false, $url, 'Открыть правило');
        }
        if (!empty($rule['is_recursive']) && !$recursive && count(getSonsOf('glpi_entities', $re)) > 1) {
            return self::problem(sprintf('Правило «%s» работает и в подчинённых организациях «%s», а маршрут там не виден: включите «Виден в подчинённых организациях».',
                $rule['name'], \Dropdown::getDropdownName('glpi_entities', $re)), null, false, $url, 'Открыть правило');
        }
        return null;
    }

    /** Не мешает запуску, но человек должен знать: статус заявки по этапам не меняется. */
    private static function notes(array $p, array $stages, bool $structured): array
    {
        if (!$structured || !array_filter($stages, static fn(array $s): bool => (int) ($s['ticket_status'] ?? 0) > 0)) {
            return [];
        }
        $task = new \CronTask();
        if (!$task->getFromDBbyName(Cron::class, 'itilflow_status_sync')
            || (int) $task->fields['state'] !== \CronTask::STATE_DISABLE) {
            return [];
        }
        return Session::haveRight('config', UPDATE)
            ? [['text' => 'Автоматическое действие itilflow_status_sync выключено — статус заявки по этапам не меняется. Включить: Настройка → Автоматические действия.',
                'service' => true]]
            : [['text' => 'Статус заявки по этапам сейчас не меняется — сообщите администратору GLPI.', 'service' => false]];
    }

    private static function problem(string $text, ?int $stage, bool $snapshot, ?string $url = null, string $link = ''): array
    {
        if ($stage !== null && $url === null) {
            $url = \Plugin::getWebDir('itilflow') . '/front/stage.form.php?id=' . $stage;
            $link = 'Открыть этап';
        }
        return ['text' => $text, 'url' => $url, 'link' => $link, 'stage' => $stage, 'snapshot' => $snapshot];
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
