<?php

namespace GlpiPlugin\Itilflow;

use CommonDBChild;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** Этап маршрута. */
class Stage extends CommonDBChild
{
    use RuntimeRead;

    use DefinitionMutation;
    public static $rightname = 'plugin_itilflow_process';
    public static $itemtype = Process::class;
    public static $items_id = 'plugin_itilflow_processes_id';
    public $dohistory = true;

    public const MODE_INLINE    = 'inline';    // задача в самой заявке
    public const MODE_DELEGATED = 'delegated'; // дочерняя заявка (в т.ч. в другой сущности)
    public const MODE_APPROVAL  = 'approval';  // штатное согласование

    // Откуда берётся согласующий на этапе-согласовании.
    public const APPROVER_FIXED   = 'fixed';    // задан в настройке этапа
    public const APPROVER_RUNTIME = 'runtime';  // указывается при прохождении
    public const APPROVER_FORM    = 'form';     // точный запрос штатной формы
    /** Готовность и импорт: у этапа согласования «Указан в этапе» никого не выбрали. */
    public const CHOOSE_APPROVERS = 'выберите согласующих.';

    public const REJECT_BLOCK = 'block';
    public const REJECT_BACK  = 'back';
    public const REJECT_ABORT = 'abort';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Этапы' : 'Этап';
    }

    public static function getIcon(): string
    {
        return 'ti ti-list-numbers';
    }

    public static function getModeLabels(): array
    {
        return [
            self::MODE_INLINE    => 'Задача',
            self::MODE_DELEGATED => 'Дочерняя заявка',
            self::MODE_APPROVAL  => 'Согласование',
        ];
    }

    public static function getEntityStrategyLabels(): array
    {
        return [
            'inherit'  => 'В организации основной заявки',
            'by_group' => 'В организации группы',
            'fixed'    => 'В указанной организации',
        ];
    }

    public static function getCompletionCommentLabels(): array
    {
        return [
            'none'     => 'Не спрашивать',
            'optional' => 'По желанию',
            'required' => 'Обязательно',
        ];
    }

    /**
     * Статусы, которые этап может выставить заявке при открытии.
     *
     * Список берётся у самого типа объекта, поэтому для заявки, изменения
     * и проблемы он свой. «Решена» и «Закрыта» исключены намеренно: этап
     * не должен закрывать объект в обход остальных этапов — для этого есть
     * настройка маршрута «Запрещать решение до прохождения маршрута»,
     * и два механизма противоречили бы друг другу.
     *
     * @return array<int,string> ключ 0 — не менять статус
     */
    public static function getStatusChoices(string $itemtype): array
    {
        $out = [0 => 'Не менять'];
        if (!is_a($itemtype, \CommonITILObject::class, true)) {
            return $out;
        }
        $terminal = [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED];
        foreach ($itemtype::getAllStatusArray() as $id => $label) {
            if (in_array((int) $id, $terminal, true)) {
                continue;
            }
            $out[(int) $id] = (string) $label;
        }
        return $out;
    }

    /** Тип объекта маршрута, которому принадлежит этап. */
    public function getItemtype(): string
    {
        $process = new Process();
        if ($process->getFromDB((int) ($this->fields['plugin_itilflow_processes_id'] ?? 0))) {
            return (string) $process->fields['itemtype'];
        }
        return \Ticket::class;
    }

    public static function getApproverSourceLabels(): array
    {
        return [
            self::APPROVER_FIXED   => 'Задан в этапе',
            self::APPROVER_RUNTIME => 'Указывает исполнитель заявки',
            self::APPROVER_FORM    => 'Согласование из формы',
        ];
    }

    public static function getRejectLabels(): array
    {
        return [
            self::REJECT_BLOCK => 'Остановить маршрут до решения администратора',
            self::REJECT_BACK  => 'Вернуть на доработку на этап',
            self::REJECT_ABORT => 'Прервать маршрут',
        ];
    }

    /**
     * Согласование «Указан в этапе» с группой и сотрудником сразу. Запрос получает
     * только группа, поэтому такая настройка противоречит сама себе: с 1.10.1 её не
     * сохранить, а у этапов, настроенных так раньше, об этом говорит строка готовности.
     */
    public static function approverConflict(array $s): bool
    {
        return ($s['execution_mode'] ?? '') === self::MODE_APPROVAL
            && (string) ($s['approver_source'] ?? self::APPROVER_FIXED) === self::APPROVER_FIXED
            && (int) ($s['groups_id'] ?? 0) > 0 && (int) ($s['users_id'] ?? 0) > 0;
    }

    /** Кто согласует — подпись и пояснение варианта в форме. */
    public static function getApproverSourceChoices(): array
    {
        return [
            self::APPROVER_FIXED   => ['Задан в этапе', ''],
            self::APPROVER_RUNTIME => ['Указывает исполнитель заявки', 'Он выберет согласующих и напишет причину.'],
            self::APPROVER_FORM => ['Согласование из формы', 'Запрос отправляет форма.'],
        ];
    }

    /** Как отдел сообщает результат (новый порядок исходов). */
    public static function getResultSourceChoices(): array
    {
        return [
            'assignee_action'   => ['Исполнитель отмечает результат', '«Выполнено», «Отказ» или «Вернуть на уточнение» в строке этапа.'],
            'native_validation' => ['Согласованием в дочерней заявке', 'Группе или пользователю отдела уходит запрос на согласование; результат — по их ответам.'],
        ];
    }

    /** Что делать при отказе (новый порядок исходов). */
    public static function getNegativePolicyChoices(): array
    {
        return [
            self::REJECT_ABORT => ['Отказать инициатору и закончить маршрут', 'Заявка получит решение «Отказ на этапе …» с причиной.'],
            self::REJECT_BLOCK => ['Остановить маршрут', 'Дальше решает администратор маршрутов: начать заново с этапа или прервать.'],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Process) {
            $n = countElementsInTable(self::getTable(), ['plugin_itilflow_processes_id' => $item->getID()]);
            return self::createTabEntry(self::getTypeName(2), $n);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Process) {
            self::showForProcess($item);
        }
        return true;
    }

    public static function showForProcess(Process $process): void
    {
        $readiness = Readiness::check($process);
        TemplateRenderer::getInstance()->display('@itilflow/stages.html.twig', [
            'process'    => $process,
            'rows'       => self::tableRows($process, $readiness['problems'], $readiness['notes']),
            'can_edit'   => $process->canUpdateItem(),
            'readiness'  => $readiness,
        ]);
    }

    /**
     * Строки вкладки «Этапы»: то, что действует, словами формы.
     * «Если отказ» — по порядку исходов маршрута; срок — в днях или часах календаря.
     */
    /** Списки из формы: пустой hidden нужен для явного очищения multiple dropdown. */
    public static function normalizeApprovalInput(array $input): ?array
    {
        foreach (['approver_users', 'approver_groups'] as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === null) { continue; }
            $value = $input[$field];
            // Native multiple select присылает пустую строку при снятии всех вариантов.
            if ($value === '') { $value = []; }
            if (is_string($value)) { $value = json_decode($value, true); }
            if (!is_array($value) || !array_is_list($value)) { return null; }
            $ids = [];
            foreach ($value as $id) {
                if ($id === '' || $id === '0' || $id === 0) { continue; }
                if ((!is_int($id) && (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id))) || (int) $id <= 0) { return null; }
                $ids[(int) $id] = (int) $id;
            }
            if (count($ids) > 50) { return null; }
            $input[$field] = json_encode(array_values($ids));
        }
        return $input;
    }

    /** Подпись настройки: все адресаты, а не только прежний первый сотрудник/группа. */
    public static function approvalNames(array $stage): string
    {
        $selection = DefinitionSnapshot::approvers($stage);
        if ($selection === null) { return 'Согласующие записаны неверно'; }
        $names = [];
        foreach ($selection['users'] as $id) { $names[] = (string) getUserName($id); }
        foreach ($selection['groups'] as $id) {
            $group = new \Group();
            $names[] = $group->getFromDB($id) ? (string) $group->fields['name'] : 'Группа недоступна';
        }
        return $names ? implode(', ', $names) : 'Согласующие не указаны';
    }

    /** Предварительный состав, не снимок будущего согласования. */
    public static function approvalPreview(array $stage, int $entity, string $itemtype): string
    {
        if (($stage['execution_mode'] ?? '') !== self::MODE_APPROVAL) { return ''; }
        if (($stage['approver_source'] ?? self::APPROVER_FIXED) === self::APPROVER_FORM) { return 'Согласующие и «нужно одобрений» — из этапа согласования в форме'; }
        if (($stage['approver_source'] ?? self::APPROVER_FIXED) !== self::APPROVER_FIXED) { return 'Согласующих выберет исполнитель заявки'; }
        $plan = ApprovalRoster::previewForEntity($stage, $entity, $itemtype);
        if (!$plan['ok']) { return implode('; ', $plan['errors']); }
        $progress = ApprovalRoster::progress(['total' => $plan['count'], 'accepted' => 0, 'refused' => 0, 'waiting' => $plan['count'], 'dropped' => 0], (int) ($stage['approval_percent'] ?? 100));
        return self::neededLine((int) $progress['needed'], (int) $progress['total']);
    }

    /** Столбец «Кто» на «Этапах»: «Нужно одобрений: 1 из 1.» — словами поля этапа «Нужно одобрений». */
    public static function neededLine(int $needed, int $total): string
    {
        return 'Нужно одобрений: ' . $needed . ' из ' . $total . '.';
    }
    public static function tableRows(Process $process, array $problems = [], array $notes = []): array
    {
        $stages = $process->getStages();
        $own = static fn(array $list, array $s): array => self::rowTexts($list, $s);
        $structured = ($process->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;
        $day = Deadline::dayMinutes((int) ($process->fields['calendars_id'] ?? 0));
        $ranks = array_column($stages, 'ranking', 'id');
        $modes = self::getModeLabels();
        $rows = [];
        foreach ($stages as $s) {
            $mode = (string) $s['execution_mode'];
            $who = '';
            if ($mode === self::MODE_APPROVAL && $s['approver_source'] !== self::APPROVER_FIXED) {
                $who = $s['approver_source'] === self::APPROVER_FORM ? 'согласование из формы' : 'укажет исполнитель заявки';
            } elseif ($mode === self::MODE_APPROVAL) {
                $who = self::approvalNames($s);
            } else {
                $g = new \Group();
                if ((int) $s['groups_id'] > 0 && $g->getFromDB((int) $s['groups_id'])) {
                    $who = (string) $g->fields['name'];
                } elseif ((int) $s['users_id'] > 0) {
                    $who = (string) getUserName((int) $s['users_id']);
                }
            }
            $who_sub = $mode === self::MODE_APPROVAL
                ? self::approvalPreview($s, (int) $process->fields['entities_id'], (string) $process->fields['itemtype']) : '';
            if ($mode === self::MODE_DELEGATED) {
                $who_sub = match ($s['entity_strategy']) {
                    'by_group' => 'в организации группы',
                    'fixed'    => 'в организации «' . \Dropdown::getDropdownName('glpi_entities', (int) $s['entities_id_target']) . '»',
                    default    => 'в организации заявки',
                };
            }
            $reject = '—';
            $reject_sub = '';
            if ($mode !== self::MODE_INLINE && $structured) {
                $reject = match ($s['negative_policy']) {
                    self::REJECT_ABORT => 'отказ инициатору',
                    self::REJECT_BLOCK => 'остановка',
                    default            => 'не выбрано',
                };
                $targets = array_map(static fn(int $t): string => isset($ranks[$t]) ? (string) $ranks[$t] : 'удалённый',
                    DefinitionSnapshot::targets($s['return_targets'] ?? null) ?? []);
                $reject_sub = $targets ? 'на уточнение — на ' . (count($targets) === 1 ? 'этап ' : 'этапы ') . implode(', ', $targets) : 'уточнения нет';
            } elseif ($mode !== self::MODE_INLINE) {
                $reject = match ($s['on_reject']) {
                    self::REJECT_BACK  => 'на доработку — на этап ' . ($ranks[(int) $s['plugin_itilflow_stages_id_reject']] ?? 'удалённый'),
                    self::REJECT_ABORT => 'прервать',
                    default            => 'остановка',
                };
            }
            $condition = BranchConfiguration::decode($s['run_if'] ?? null);
            $rows[] = [
                'id'          => (int) $s['id'],
                'ranking'     => (int) $s['ranking'],
                'name'        => (string) $s['name'],
                'mode'        => $modes[$mode] ?? $mode,
                'when'        => !$structured || $condition === [] ? 'всегда'
                    : ($condition === null ? 'условие повреждено — откройте этап' : ConditionText::short($condition, $stages)),
                'who'         => $who,
                'who_sub'     => $who_sub,
                'deadline'    => Deadline::label((int) $s['deadline_minutes'], $day),
                'reject'      => $reject,
                'reject_sub'  => $reject_sub,
                'report_required' => $mode === self::MODE_INLINE && $s['completion_comment'] === 'required',
                'is_optional' => $mode === self::MODE_INLINE && !empty($s['is_optional']),
                'problems'    => $own($problems, $s),
                'notes'       => $own($notes, $s),
            ];
        }
        return $rows;
    }

    public function showForm($ID, array $options = [])
    {
        if (!isset($options['plugin_itilflow_processes_id']) && $ID > 0) {
            $options['plugin_itilflow_processes_id'] = $this->fields['plugin_itilflow_processes_id'];
        }
        $this->initForm($ID, $options);

        $processes_id = (int) ($options['plugin_itilflow_processes_id'] ?? 0);
        $process = new Process();
        $process->getFromDB($processes_id);
        $structured = ($process->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;
        $itemtype = (string) ($process->fields['itemtype'] ?? \Ticket::class);
        $stages = $process->getStages();
        $statuses = self::getStatusChoices($itemtype);
        $status_by_mode = StageForm::statusByMode($statuses);

        if ($this->isNewItem()) {
            // Новый этап встаёт в конец: следующий десяток после последнего номера.
            $max = 0;
            foreach ($stages as $s) {
                $max = max($max, (int) $s['ranking']);
            }
            $this->fields['ranking'] = (intdiv($max, 10) + 1) * 10;
            $this->fields['ticket_status'] = $status_by_mode[$this->fields['execution_mode']] ?? 0;
        }
        $rank = (int) $this->fields['ranking'];

        // Точки возврата — только этот этап и предыдущие, с номерами.
        $targets = [];
        $siblings = [0 => '-----'];
        $position = 0;
        foreach ($stages as $i => $s) {
            $own = (int) $s['id'] === (int) $this->getID();
            if ($own) {
                $position = $i + 1;
            } else {
                $siblings[$s['id']] = sprintf('%d. %s', $s['ranking'], $s['name']);
            }
            if ($own || (int) $s['ranking'] <= $rank) {
                $targets[] = ['value' => (int) $s['id'],
                    'label' => sprintf('%d. %s', $s['ranking'], $s['name']) . ($own ? ' — этот этап' : '')];
            }
        }
        $targets_selected = DefinitionSnapshot::targets($this->fields['return_targets'] ?? '[]') ?? [];
        if ($this->isNewItem()) {
            // «Этот этап» можно отметить до первого сохранения: номер подставит сервер.
            $targets[] = ['value' => 'self', 'label' => sprintf('%d. этот этап (новый)', $rank)];
            $targets_selected = ['self'];
        }
        // «← Предыдущий / Следующий →» — по порядку этапов маршрута.
        $neighbours = ['prev' => null, 'next' => null];
        foreach ($stages as $i => $s) {
            if ((int) $s['id'] !== (int) $this->getID()) { continue; }
            foreach (['prev' => $i - 1, 'next' => $i + 1] as $key => $j) {
                if (isset($stages[$j])) {
                    $neighbours[$key] = ['url' => self::getFormURLWithID((int) $stages[$j]['id']),
                        'label' => (int) $stages[$j]['ranking'] . '. ' . $stages[$j]['name']];
                }
            }
        }

        $calendars_id = (int) ($process->fields['calendars_id'] ?? 0);
        $day = Deadline::dayMinutes($calendars_id);
        [$dl_value, $dl_unit] = Deadline::split((int) ($this->fields['deadline_minutes'] ?? 0), $day);

        TemplateRenderer::getInstance()->display('@itilflow/stage.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'buttons'      => Process::formButtons($this),
            'processes_id' => $processes_id,
            'process'      => $process,
            'structured'   => $structured,
            'branch'       => $structured ? BranchConfiguration::editor($process->fields, $this->fields, $stages) : null,
            'what_next'    => $structured ? StageForm::whatNext($this->fields, $stages) : null,
            'what_before'  => $structured ? StageForm::reachedFrom($this->fields, $stages) : null,
            'layout'       => StageForm::layout($this->fields, $structured, $itemtype),
            'glpi_admin'   => Session::haveRight('config', UPDATE),
            'modes'        => self::getModeLabels(),
            'strategies'   => self::getEntityStrategyLabels(),
            'rejects'      => self::getRejectLabels(),
            'completions'  => self::getCompletionCommentLabels(),
            'approval_selection' => DefinitionSnapshot::approvers($this->fields) ?? ['users' => [], 'groups' => [], 'mode' => 'any'],
            'approval_preview' => self::approvalPreview($this->fields, (int) ($process->fields['entities_id'] ?? 0), $itemtype),
            'approver_choices' => array_filter(self::getApproverSourceChoices(), static fn($key): bool => $key !== self::APPROVER_FORM || ($structured && $itemtype === 'Ticket'), ARRAY_FILTER_USE_KEY),
            'form_steps' => FormConfiguration::stepOptions($process->fields, true),
            'result_choices'   => self::getResultSourceChoices(),
            'negative_choices' => self::getNegativePolicyChoices(),
            'statuses'     => $statuses,
            'status_by_mode' => $this->isNewItem() ? $status_by_mode : null,
            'siblings'     => $siblings,
            'targets'      => $targets,
            'targets_selected' => $targets_selected,
            'position'     => $position,
            'total'        => count($stages),
            'neighbours'   => $neighbours,
            'draft_url'    => $structured ? \Plugin::getWebDir('itilflow') . '/front/draft.php' : '',
            'field_errors' => self::takeFieldErrors((int) $this->getID()),
            'readiness'    => $this->isNewItem() || $processes_id <= 0 ? null
                : Readiness::forStageSplit($process, (int) $this->getID()),
            'deadline'     => [
                'value'    => $dl_value,
                'unit'     => $dl_unit,
                'day'      => $day,
                'hours'    => str_replace('.', ',', rtrim(rtrim(number_format($day / 60, 1, '.', ''), '0'), '.')),
                'calendar' => $calendars_id > 0 ? \Dropdown::getDropdownName('glpi_calendars', $calendars_id) : '',
            ],
        ]);
        return true;
    }

    /**
     * Значения по умолчанию для формы нового этапа.
     *
     * Без этого форма подставляет пустые строки: порог согласования уезжал
     * в ноль вместо ста, а режим исполнения и реакция на отказ приходили
     * пустыми. Значения совпадают со значениями по умолчанию в схеме.
     */
    public function post_getEmpty()
    {
        $this->fields['execution_mode']     = self::MODE_INLINE;
        $this->fields['entity_strategy']    = 'inherit';
        $this->fields['approval_percent']   = 100;
        $this->fields['approver_source']    = self::APPROVER_FIXED;
        $this->fields['form_validationsteps_id'] = 0;
        $this->fields['run_if'] = null;
        $this->fields['decision_items'] = null;
        $this->fields['unknown_policy'] = 'run';
        $this->fields['on_reject']          = self::REJECT_BLOCK;
        $this->fields['completion_comment'] = 'optional';
        $this->fields['is_optional']        = 0;
        $this->fields['deadline_minutes']   = 0;
        $this->fields['ticket_status']      = 0;
        // Новый этап: при отказе — ответить заявителю отказом и завершить
        // маршрут, отдел сообщает итог отметкой исполнителя, точка возврата — этот этап
        // (её форма отмечает сама, номер подставляет post_addItem). Поля нового порядка
        // у маршрута прежнего порядка не выводятся и не отправляются.
        $this->fields['department_result_source'] = 'assignee_action';
        $this->fields['negative_policy'] = self::REJECT_ABORT;
        $this->fields['return_targets'] = '[]';
        $this->fields['duration']           = 0;
        // Целочисленные ссылки: пустая строка не проходит в строгом режиме MySQL.
        $this->fields['ranking']                          = 0;
        $this->fields['groups_id']                        = 0;
        $this->fields['users_id']                         = 0;
        $this->fields['entities_id_target']               = 0;
        $this->fields['itilcategories_id']                = 0;
        $this->fields['slas_id']                          = 0;
        $this->fields['plugin_itilflow_stages_id_reject'] = 0;
    }

    public function prepareInputForAdd($input)
    {
        // Форма перед add() проверяет право через check(-1), и та заполняет поля объекта
        // умолчаниями формы (getEmpty). В базу же идёт только ввод, поэтому проверка
        // нового этапа не должна видеть эти умолчания как сохранённые значения.
        $this->fields = [];
        if (!isset($input['ranking']) || (int) $input['ranking'] <= 0) {
            $max = 0;
            foreach ((new self())->find(
                ['plugin_itilflow_processes_id' => (int) $input['plugin_itilflow_processes_id']]
            ) as $r) {
                $max = max($max, (int) $r['ranking']);
            }
            $input['ranking'] = $max + 1;
        }
        return $this->validateInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->validateInput($input);
    }

    private function validateInput(array $input): array
    {
        // Срок из формы — число и единица; в базе по-прежнему минуты. Рабочий день —
        // по календарю маршрута. Старый ввод deadline_minutes (API) работает как раньше.
        if (array_key_exists('_deadline_value', $input)) {
            $owner = new Process();
            $owner->getFromDB((int) ($input['plugin_itilflow_processes_id'] ?? $this->fields['plugin_itilflow_processes_id'] ?? 0));
            $minutes = Deadline::toMinutes($input['_deadline_value'], (string) ($input['_deadline_unit'] ?? Deadline::DAY),
                Deadline::dayMinutes((int) ($owner->fields['calendars_id'] ?? 0)));
            if ($minutes === null) {
                $this->fieldError('deadline', 'Срок этапа: укажите число рабочих дней или часов, например 3 или 1,5.');
                return [];
            }
            $input['deadline_minutes'] = $minutes;
        }
        unset($input['_deadline_value'], $input['_deadline_unit']);
        // Срок дальше 19.01.2038 база не хранит: отказ при сохранении, а не при запуске.
        if (array_key_exists('deadline_minutes', $input) && (int) $input['deadline_minutes'] > 0) {
            $error = Deadline::durationError((int) $input['deadline_minutes'], self::routeCalendar($input, $this->fields));
            if ($error !== null) { $this->fieldError('deadline', $error); return []; }
        }

        // Точки возврата из формы — галочки «этот этап и предыдущие».
        if (isset($input['_structured_return_targets_present']) && !isset($input['_structured_return_targets'])) { $input['_structured_return_targets'] = []; }
        unset($input['_structured_return_targets_present']);
        if (array_key_exists('_structured_return_targets', $input)) {
            $values = $input['_structured_return_targets'];
            $targets = [];
            foreach (is_array($values) ? $values : [null] as $value) {
                // «Этот этап» у нового этапа: номера ещё нет, его подставит post_addItem.
                if ($value === 'self') {
                    if ($this->isNewItem()) {
                        $input['_itilflow_self_target'] = 1;
                        continue;
                    }
                    $value = (string) $this->getID();
                }
                if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
                    || (string) (int) $value !== (string) $value) {
                    Session::addMessageAfterRedirect('Этапы для возврата повреждены — отметьте заново.', false, ERROR);
                    return [];
                }
                $targets[] = (int) $value;
            }
            $targets = array_values(array_unique($targets));
            // Тот же набор, что в базе, — прежний порядок записи: форма перечисляет этапы по номеру.
            $stored = DefinitionSnapshot::targets($this->fields['return_targets'] ?? null);
            if ($stored !== null && count($stored) === count($targets) && !array_diff($stored, $targets)) {
                $targets = $stored;
            }
            $input['return_targets'] = DefinitionSnapshot::encode($targets);
            unset($input['_structured_return_targets']);
        }
        $process = new Process();
        $pid = (int) ($input['plugin_itilflow_processes_id'] ?? $this->fields['plugin_itilflow_processes_id'] ?? 0);
        if ($process->getFromDB($pid) && ($process->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE) {
            $siblings = $process->getStages();
            if (isset($input['_branch_present'])) {
                try {
                    $input = array_replace($input, BranchConfiguration::fromEditor($input, $process->fields, array_replace($this->fields, $input), $siblings));
                } catch (\InvalidArgumentException $e) {
                    Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR); return [];
                }
            }
            foreach (['_branch_present', BranchConfiguration::EDITOR, '_branch_unknown', '_decision_rows'] as $field) { unset($input[$field]); }
            if (array_key_exists('run_if', $input) && $input['run_if'] !== ($this->fields['run_if'] ?? null)) {
                try {
                    $condition = BranchConfiguration::decode($input['run_if']);
                    if ($condition === null) { throw new \InvalidArgumentException('Условие повреждено — задайте заново.'); }
                    if (BranchConfiguration::newTicketTypeClauses($condition, BranchConfiguration::decode($this->fields['run_if'] ?? null) ?? [])) {
                        throw new \InvalidArgumentException('Условия по типу заявки больше не задаются — выберите категорию заявки.');
                    }
                    $condition = BranchConfiguration::freezeForSave($condition, $process->fields);
                    $input['run_if'] = $condition ? DefinitionSnapshot::encode($condition) : null;
                } catch (\InvalidArgumentException $e) { Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR); return []; }
            }
            $candidate = array_replace($this->fields, $input);
            $candidate += ['id' => PHP_INT_MAX, 'run_if' => null, 'decision_items' => null, 'unknown_policy' => 'run'];
            $all = array_values(array_filter($siblings, fn(array $s): bool => (int) $s['id'] !== (int) $this->getID()));
            $all[] = $candidate;
            $errors = BranchConfiguration::validateRoute($process->fields, $all);
            if ($errors) { Session::addMessageAfterRedirect(htmlescape($errors[0]), false, ERROR); return []; }
            $merged = array_replace($this->fields, $input);
            $targets = DefinitionSnapshot::targets($merged['return_targets'] ?? '[]');
            // Три поля — три сообщения, словами формы.
            if ($targets === null) {
                Session::addMessageAfterRedirect('Этапы для возврата повреждены — отметьте заново.', false, ERROR);
                return [];
            }
            if (($merged['execution_mode'] ?? self::MODE_INLINE) === self::MODE_DELEGATED
                && !in_array($merged['department_result_source'] ?? '', ['assignee_action', 'native_validation'], true)) {
                Session::addMessageAfterRedirect('Выберите, как отдел сообщает результат, — без этого маршрут не запустится.', false, ERROR);
                return [];
            }
            // У задачи отказа не бывает, движок это поле не читает, а проверка определения
            // требует значение у каждого этапа: пустое становится «остановить».
            if (($merged['execution_mode'] ?? self::MODE_INLINE) === self::MODE_INLINE
                && !in_array($merged['negative_policy'] ?? '', ['block', 'abort'], true)) {
                $input['negative_policy'] = $merged['negative_policy'] = self::REJECT_BLOCK;
            }
            if (!in_array($merged['negative_policy'] ?? '', ['block', 'abort'], true)) {
                Session::addMessageAfterRedirect('Выберите, что делать при отказе, — без этого маршрут не запустится.', false, ERROR);
                return [];
            }
            foreach ($targets as $target) {
                if ($target === (int) $this->getID()) {
                    continue; // этот же этап — всегда «не позже себя»
                }
                $stage = new self();
                if (!$stage->getFromDB($target) || (int) $stage->fields['plugin_itilflow_processes_id'] !== $pid) {
                    Session::addMessageAfterRedirect('Этап для возврата удалён. Обновите страницу.', false, ERROR);
                    return [];
                }
                if ((int) $stage->fields['ranking'] > (int) ($merged['ranking'] ?? 0)) {
                    // Сообщения после перехода GLPI выводит как HTML: название — текстом.
                    Session::addMessageAfterRedirect(sprintf(
                        'Вернуть можно только на этот этап или раньше, а «%d. %s» стоит позже.',
                        $stage->fields['ranking'], htmlescape($stage->fields['name'])
                    ), false, ERROR);
                    return [];
                }
            }
            $input['return_targets'] = DefinitionSnapshot::encode($targets);
        }
        if (isset($input['name']) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect('Укажите название этапа.', false, ERROR);
            return [];
        }
        $mode = $input['execution_mode'] ?? $this->fields['execution_mode'] ?? self::MODE_INLINE;
        if (!array_key_exists($mode, self::getModeLabels())) {
            Session::addMessageAfterRedirect('Неизвестное значение «Как выполняется».', false, ERROR);
            return [];
        }

        $groups_id = (int) ($input['groups_id'] ?? $this->fields['groups_id'] ?? 0);
        $users_id  = (int) ($input['users_id'] ?? $this->fields['users_id'] ?? 0);
        $src = (string) ($input['approver_source'] ?? $this->fields['approver_source']
            ?? self::APPROVER_FIXED);

        if (!array_key_exists($src, self::getApproverSourceLabels())) {
            Session::addMessageAfterRedirect('Неизвестное значение «Кто согласует».', false, ERROR);
            return [];
        }
        if ($src === self::APPROVER_FORM && (($process->fields['outcome_contract'] ?? '') !== DefinitionSnapshot::MODE
            || ($process->fields['itemtype'] ?? '') !== 'Ticket')) {
            Session::addMessageAfterRedirect('Согласование из формы — только для маршрутов заявок в новом порядке.', false, ERROR);
            return [];
        }
        if (array_key_exists('form_validationsteps_id', $input)) {
            $value = filter_var($input['form_validationsteps_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false) { Session::addMessageAfterRedirect('Выберите этап согласования в форме.', false, ERROR); return []; }
            $input['form_validationsteps_id'] = $value;
            if ($src === self::APPROVER_FORM && $value > 0 && !isset(FormConfiguration::stepOptions($process->fields, true)[$value])) {
                Session::addMessageAfterRedirect('Этого этапа согласования нет в назначении формы.', false, ERROR); return [];
            }
        }
        $normalized = self::normalizeApprovalInput($input);
        if ($normalized === null) {
            Session::addMessageAfterRedirect('Не больше 50 согласующих.', false, ERROR);
            return [];
        }
        $input = $normalized;
        if ($mode === self::MODE_APPROVAL && (isset($input['approver_users']) || isset($input['approver_groups']))) {
            $input['groups_id'] = $groups_id = 0;
            $input['users_id'] = $users_id = 0;
        }
        // Списки согласующих (1.11): JSON-список номеров или NULL — «не заполнено», тогда действуют
        // прежние «группа / сотрудник». Здесь — только запись; смысл списков проверяет форма этапа.
        foreach (['approver_users', 'approver_groups'] as $field) {
            if (array_key_exists($field, $input) && DefinitionSnapshot::idList($input[$field]) === false) {
                Session::addMessageAfterRedirect('Список согласующих повреждён — выберите заново.', false, ERROR);
                return [];
            }
        }
        if (array_key_exists('approver_group_mode', $input)
            && !in_array($input['approver_group_mode'], [DefinitionSnapshot::GROUP_ANY, DefinitionSnapshot::GROUP_EACH], true)) {
            Session::addMessageAfterRedirect('Неизвестное значение «Как согласует группа».', false, ERROR);
            return [];
        }

        if (($input['approver_group_mode'] ?? $this->fields['approver_group_mode'] ?? 'any') === 'each'
            && ($process->fields['outcome_contract'] ?? 'legacy') !== DefinitionSnapshot::MODE) {
            Session::addMessageAfterRedirect('«Каждый в группе» — только в новом порядке.', false, ERROR);
            return [];
        }
        // Статус, выставляемый при открытии этапа. 0 — не менять.
        if (array_key_exists('ticket_status', $input)) {
            $status = (int) $input['ticket_status'];
            // Тип объекта — у маршрута из ввода: у нового этапа поля объекта ещё пусты
            // (prepareInputForAdd), и getItemtype() не видит маршрут.
            $itemtype = (string) ($input['_itemtype']
                ?? ($process->isNewItem() ? $this->getItemtype() : $process->fields['itemtype']));
            if (!array_key_exists($status, self::getStatusChoices($itemtype))) {
                Session::addMessageAfterRedirect(
                    'Этот статус нельзя выбрать для этапа: «Решена» и «Закрыто» ставит маршрут целиком.',
                    false,
                    ERROR
                );
                return [];
            }
            $input['ticket_status'] = $status;
        }
        // Источник согласующего осмыслен только для этапа-согласования.
        if ($mode !== self::MODE_APPROVAL) {
            $src = self::APPROVER_FIXED;
            $input['approver_source'] = self::APPROVER_FIXED;
        }
        // При двух новых источниках согласующий заранее неизвестен, и требовать
        // его в настройке этапа нельзя: он появится при прохождении заявки.
        $approver_known_later = ($mode === self::MODE_APPROVAL && $src !== self::APPROVER_FIXED);
        // Согласование «Указан в этапе» со списками согласующих (1.11): исполнитель — списки.
        $lists = $mode === self::MODE_APPROVAL && !$approver_known_later
            ? DefinitionSnapshot::approvers(array_replace($this->fields, $input)) : null;
        if ($lists !== null && $lists['users'] && $lists['groups']) {
            Session::addMessageAfterRedirect('Выберите пользователей или группы — что-то одно.', false, ERROR);
            return [];
        }
        $by_lists = $lists !== null && !$lists['legacy'] && ($lists['users'] !== [] || $lists['groups'] !== []);

        if ($groups_id === 0 && $users_id === 0 && !$approver_known_later && !$by_lists) {
            Session::addMessageAfterRedirect(
                'Выберите исполнителя этапа: группу или пользователя.',
                false,
                ERROR
            );
            return [];
        }
        if ($approver_known_later && ($groups_id > 0 || $users_id > 0)) {
            Session::addMessageAfterRedirect(
                'Согласующих укажет исполнитель заявки — группу и пользователя в этапе очистили.',
                false,
                WARNING
            );
            $input['groups_id'] = 0;
            $input['users_id']  = 0;
        }
        // «Указан в этапе» — группа или сотрудник: запрос на согласование получает кто-то
        // один (Engine::createApproval). При обоих полях сотрудник молча оставался без запроса.
        if (!$by_lists && self::approverConflict(['execution_mode' => $mode, 'approver_source' => $src,
            'groups_id' => $groups_id, 'users_id' => $users_id])) {
            Session::addMessageAfterRedirect(
                'Выберите что-то одно: группу или пользователя. Нужно несколько — выберите их в списках «Согласующие».',
                false,
                ERROR
            );
            return [];
        }

        // Два этапа с одинаковым номером делают порядок неопределённым,
        // а весь смысл маршрута — в порядке. Сдвигаем и говорим об этом прямо.
        $processes_id = (int) ($input['plugin_itilflow_processes_id']
            ?? $this->fields['plugin_itilflow_processes_id'] ?? 0);
        $ranking = (int) ($input['ranking'] ?? $this->fields['ranking'] ?? 0);
        if ($processes_id > 0 && $ranking > 0) {
            $crit = ['plugin_itilflow_processes_id' => $processes_id, 'ranking' => $ranking];
            // Внутри scope find выполняет current read; свой ID исключается без сложного SQL-критерия.
            $conflict = false;
            // Перестановка стрелками (Stage::move) меняет номер с соседним этапом: его номер не конфликт.
            $swap = (int) ($input['_itilflow_swap_with'] ?? 0);
            foreach ((new self())->find($crit) as $other) {
                if ((int) $other['id'] !== (int) $this->getID() && (int) $other['id'] !== $swap) { $conflict = true; break; }
            }
            if ($conflict) {
                $max = 0;
                foreach ((new self())->find(['plugin_itilflow_processes_id' => $processes_id]) as $r) {
                    $max = max($max, (int) $r['ranking']);
                }
                $input['ranking'] = $max + 1;
                Session::addMessageAfterRedirect(
                    sprintf(
                        'Этап с номером %d уже есть, этому дан номер %d. Поправьте, если нужно другое место.',
                        $ranking,
                        $input['ranking']
                    ),
                    false,
                    WARNING
                );
            }
        }

        $strategy = $input['entity_strategy'] ?? $this->fields['entity_strategy'] ?? 'inherit';
        if ($strategy === 'fixed' && (int) ($input['entities_id_target'] ?? $this->fields['entities_id_target'] ?? 0) < 0) {
            Session::addMessageAfterRedirect('Где создать дочернюю заявку — «В указанной организации»: выберите организацию.', false, ERROR);
            return [];
        }
        if ($mode === self::MODE_DELEGATED && $strategy === 'by_group' && $groups_id === 0) {
            Session::addMessageAfterRedirect(
                'Где создать дочернюю заявку — «В организации группы»: выберите группу.',
                false,
                ERROR
            );
            return [];
        }
        // SLA дочерней заявки: время решения должно поместиться до 19.01.2038. Календарь —
        // организации дочерней заявки, как у движка и у Ticket::getDatasToAddSLA.
        $slas = (int) ($input['slas_id'] ?? $this->fields['slas_id'] ?? 0);
        if ($mode === self::MODE_DELEGATED && $slas > 0 && array_key_exists('slas_id', $input)) {
            $error = Deadline::slaError($slas, self::childCalendar(array_replace($this->fields, $input), $process->fields));
            if ($error !== null) { $this->fieldError('slas_id', $error); return []; }
        }
        unset($input['_itilflow_swap_with']);

        // Проверки права «Согласовать запрос / инцидент» здесь нет намеренно.
        // В GLPI 11 ответить на запрос может любой, кто назначен согласующим:
        // форма ответа гасится по CommonITILValidation::canAnswer(), а он смотрит
        // только на itemtype_target / items_id_target и права профиля не учитывает.
        // Право же фильтрует, кого предлагать в штатном выборе согласующего
        // (dropdownValidator) — а плагин назначает согласующего программно и этот
        // выбор не использует. Прежняя проверка выдавала предупреждение на
        // работоспособной настройке и вводила администраторов в заблуждение.

        return $input;
    }

    /**
     * «Этот этап» в точках возврата нового этапа: номер появляется только при записи,
     * поэтому подставляется здесь — в той же транзакции, что и добавление.
     */
    public function post_addItem()
    {
        global $DB;
        parent::post_addItem();
        if (empty($this->input['_itilflow_self_target'])) {
            return;
        }
        $targets = DefinitionSnapshot::targets($this->fields['return_targets'] ?? '[]') ?? [];
        $targets[] = (int) $this->getID();
        $this->fields['return_targets'] = DefinitionSnapshot::encode(array_values(array_unique($targets)));
        $DB->update(self::getTable(), ['return_targets' => $this->fields['return_targets']], ['id' => (int) $this->getID()]);
    }

    /** Что убрано из точек возврата других этапов при удалении этого. */
    private ?array $unlinkedTargets = null;

    /**
     * Удаляемый этап убирается из точек возврата других этапов маршрута — в той же
     * транзакции, что и удаление. Раньше удаление такого этапа откатывалось
     * с «Изменение определения не подтверждено. Обновите страницу».
     */
    public function pre_deleteItem()
    {
        global $DB;
        $this->unlinkedTargets = null;
        $id = (int) $this->getID();
        foreach ((new self())->find(['plugin_itilflow_processes_id' => (int) $this->fields['plugin_itilflow_processes_id']]) as $other) {
            if ((int) $other['id'] === $id) { continue; }
            foreach ((BranchConfiguration::decode($other['run_if'] ?? null) ?? [])['clauses'] ?? [] as $clause) {
                if ((int) ($clause['stage_id'] ?? 0) === $id) {
                    Session::addMessageAfterRedirect(htmlescape(sprintf('Этап нельзя убрать: на него ссылается условие этапа %d «%s». Сначала измените это условие.',
                        (int) $other['ranking'], $other['name'])), false, ERROR);
                    return false;
                }
            }
        }
        $ranks = [];
        foreach ((new self())->find(['plugin_itilflow_processes_id' => (int) $this->fields['plugin_itilflow_processes_id']],
            ['ranking', 'id']) as $sid => $row) {
            $targets = DefinitionSnapshot::targets($row['return_targets'] ?? null);
            if ((int) $sid === $id || $targets === null || !in_array($id, $targets, true)) {
                continue;
            }
            $DB->update(self::getTable(), ['return_targets' => DefinitionSnapshot::encode(array_values(array_diff($targets, [$id])))],
                ['id' => (int) $sid]);
            $ranks[] = (int) $row['ranking'];
        }
        if ($ranks) {
            $this->unlinkedTargets = ['ranking' => (int) $this->fields['ranking'], 'name' => (string) $this->fields['name'], 'ranks' => $ranks];
        }
        return parent::pre_deleteItem();
    }

    public function post_purgeItem()
    {
        parent::post_purgeItem();
        if ($this->unlinkedTargets !== null) {
            $u = $this->unlinkedTargets;
            Session::addMessageAfterRedirect(sprintf('Этап %d «%s» удалён и убран из возвратов %s %s.',
                $u['ranking'], htmlescape($u['name']), count($u['ranks']) === 1 ? 'этапа' : 'этапов', implode(', ', $u['ranks'])), false, INFO);
        }
    }

    /**
     * Строки готовности одного этапа для его строки в списке этапов: без «Этап N «…»:» в начале —
     * строка и так об этом этапе. $list — problems или notes Readiness::check.
     *
     * @return list<string>
     */
    public static function rowTexts(array $list, array $stage): array
    {
        $prefix = 'Этап ' . (int) $stage['ranking'] . ' «' . $stage['name'] . '»';
        $out = [];
        foreach ($list as $x) {
            if (($x['stage'] ?? null) !== (int) $stage['id']) { continue; }
            $text = (string) $x['text'];
            foreach ([$prefix . ': ', $prefix . ' — '] as $p) {
                if (str_starts_with($text, $p)) {
                    $text = mb_substr($text, mb_strlen($p));
                    break;
                }
            }
            $out[] = $text;
        }
        return $out;
    }

    /** Ошибка поля формы этапа: сообщение GLPI и строка под полем при следующем показе формы. */
    private function fieldError(string $field, string $text): void
    {
        Session::addMessageAfterRedirect(htmlescape($text), false, ERROR);
        // Новый этап: при добавлении номера ещё нет (getID() — -1), а форма нового этапа читает 0.
        $_SESSION['itilflow_stage_errors'][max(0, (int) $this->getID())][$field] = $text;
    }

    /** Ошибки полей с прошлой попытки сохранения этого этапа — один раз; у нового этапа — 0. */
    public static function takeFieldErrors(int $id): array
    {
        $id = max(0, $id);
        $errors = $_SESSION['itilflow_stage_errors'][$id] ?? [];
        unset($_SESSION['itilflow_stage_errors'][$id]);
        return is_array($errors) ? $errors : [];
    }

    /** Календарь маршрута этапа: по нему считается срок этапа. */
    private static function routeCalendar(array $input, array $fields): int
    {
        $process = new Process();
        $process->getFromDB((int) ($input['plugin_itilflow_processes_id'] ?? $fields['plugin_itilflow_processes_id'] ?? 0));
        return (int) ($process->fields['calendars_id'] ?? 0);
    }

    /**
     * Календарь организации дочерней заявки — так считает срок SLA движок и
     * Ticket::getDatasToAddSLA. «В указанной организации» — она; «В организации группы» —
     * организация группы; иначе — организация маршрута (основная заявка бывает и в подчинённой
     * организации — тогда срок ещё раз проверит запуск этапа).
     */
    public static function childCalendar(array $stage, array $process, ?\Closure $entityCalendar = null, ?\Closure $groupEntity = null): int
    {
        // Чтение базы — по умолчанию; проверки подставляют свои организации и календари.
        $entityCalendar ??= static fn(int $entity): int => (int) \Entity::getUsedConfig('calendars_strategy', $entity, 'calendars_id', 0);
        $groupEntity ??= static function (int $group): ?int {
            $g = new \Group();
            return $g->getFromDB($group) ? (int) $g->fields['entities_id'] : null;
        };
        $entity = (int) ($process['entities_id'] ?? 0);
        $strategy = (string) ($stage['entity_strategy'] ?? 'inherit');
        if ($strategy === 'fixed') {
            $entity = (int) ($stage['entities_id_target'] ?? 0);
        } elseif ($strategy === 'by_group' && (int) ($stage['groups_id'] ?? 0) > 0) {
            $entity = $groupEntity((int) $stage['groups_id']) ?? $entity;
        }
        return (int) $entityCalendar($entity);
    }

    /**
     * Что сломает новый порядок этапов: условия на этапы ниже и возвраты на этапы ниже.
     * null — порядок допустим. Тексты — названиями этапов.
     */
    public static function orderProblem(array $process, array $stages): ?string
    {
        $errors = BranchConfiguration::validateRoute($process, $stages);
        if ($errors) { return $errors[0]; }
        if (($process['outcome_contract'] ?? 'legacy') !== DefinitionSnapshot::MODE) { return null; }
        $byId = [];
        foreach ($stages as $s) { $byId[(int) $s['id']] = $s; }
        foreach ($stages as $s) {
            foreach (DefinitionSnapshot::targets($s['return_targets'] ?? null) ?? [] as $t) {
                if ($t !== (int) $s['id'] && isset($byId[$t]) && (int) $byId[$t]['ranking'] > (int) $s['ranking']) {
                    return sprintf('этап %d «%s» возвращает на уточнение на этап «%s», а он окажется ниже.',
                        (int) $s['ranking'], $s['name'], $byId[$t]['name']);
                }
            }
        }
        return null;
    }

    /** Первый этап пары, которую сейчас переставляет Stage::move; задаётся только внутри move. */
    private static ?int $swapFirst = null;

    /**
     * Сохранение этапа — первая половина перестановки стрелками: версию маршрута поднимет
     * и полноту проверит второе сохранение пары. Из ввода формы не задаётся.
     */
    public static function versionDeferred(int $id): bool
    {
        return self::$swapFirst !== null && $id > 0 && self::$swapFirst === $id;
    }

    /**
     * Стрелки ↑ ↓ списка этапов: номер меняется с соседним этапом обычным сохранением обоих
     * в одной транзакции. Сначала проверяется итог: условия и возвраты не должны ссылаться на
     * этапы ниже. Не прошло — причина словами, порядок прежний.
     */
    public static function move(int $id, string $direction): bool
    {
        $stage = new self();
        $process = new Process();
        if (!$stage->getFromDB($id) || !$process->getFromDB((int) $stage->fields['plugin_itilflow_processes_id'])) {
            Session::addMessageAfterRedirect('Этап не найден. Обновите страницу.', false, ERROR);
            return false;
        }
        $stages = array_values($process->getStages());
        $index = null;
        foreach ($stages as $i => $s) { if ((int) $s['id'] === $id) { $index = $i; } }
        $j = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === null || !isset($stages[$j])) {
            Session::addMessageAfterRedirect($direction === 'up' ? 'Этот этап уже первый.' : 'Этот этап уже последний.', false, INFO);
            return false;
        }
        $other = $stages[$j];
        $a = (int) $stages[$index]['ranking'];
        $b = (int) $other['ranking'];
        if ($a === $b) {
            Session::addMessageAfterRedirect('У этих этапов одинаковый номер — задайте «Порядок» в одном из них.', false, ERROR);
            return false;
        }
        $swapped = $stages;
        $swapped[$index]['ranking'] = $b;
        $swapped[$j]['ranking'] = $a;
        $reason = self::orderProblem($process->fields, $swapped);
        if ($reason !== null) {
            Session::addMessageAfterRedirect(htmlescape('Порядок не изменён: ' . $reason), false, ERROR);
            return false;
        }
        $scope = new TransactionScope();
        try {
            // Между двумя сохранениями у пары на миг один номер: версию маршрута и его полноту
            // проверяет второе сохранение — уже по итоговому порядку (DefinitionMutation).
            self::$swapFirst = $id;
            try {
                $ok = (new self())->update(['id' => $id, 'ranking' => $b, '_itilflow_swap_with' => (int) $other['id']]);
            } finally {
                self::$swapFirst = null;
            }
            $ok = $ok && (new self())->update(['id' => (int) $other['id'], 'ranking' => $a, '_itilflow_swap_with' => $id]);
            if (!$ok) {
                $scope->rollback();
                return false;
            }
            $scope->commit();
        } catch (\Throwable $e) {
            try { $scope->rollback(); } catch (\Throwable) {}
            TransactionScope::assertHealthy();
            Session::addMessageAfterRedirect('Порядок не изменён. Обновите страницу и попробуйте ещё раз.', false, ERROR);
            return false;
        }
        Session::addMessageAfterRedirect(htmlescape(sprintf('Этап «%s» теперь %d.', $stage->fields['name'], $b)), false, INFO);
        return true;
    }

    /** Поля формы этапа, которые черновик берёт как есть. */
    private const DRAFT_FIELDS = ['name', 'execution_mode', 'approver_source', 'groups_id', 'users_id', 'approver_group_mode',
        'entity_strategy', 'entities_id_target', 'department_result_source', 'negative_policy', 'form_validationsteps_id',
        'approval_percent', 'ranking', 'slas_id', 'itilcategories_id', 'completion_comment', 'is_optional', 'ticket_status', 'on_reject',
        'plugin_itilflow_stages_id_reject'];

    /**
     * Черновик этапа для пересчёта без сохранения (front/draft.php): поля формы поверх
     * сохранённого этапа, те же правила разбора, что при сохранении, без записи в базу.
     *
     * @return array{stage:array,stages:list<array>,errors:list<string>,fields:array<string,string>,parsed:array}
     *   stages — этапы маршрута с черновиком вместо сохранённого (новый этап — id 0);
     *   errors — что не сохранится; fields — ошибки под полями (deadline, slas_id);
     *   parsed — ConditionEditor::parseRows редактора «Когда выполнять».
     */
    public static function draft(array $post, Process $process): array
    {
        $id = (int) ($post['id'] ?? 0);
        $stored = new self();
        $base = $id > 0 && $stored->getFromDB($id) && (int) $stored->fields['plugin_itilflow_processes_id'] === (int) $process->getID()
            ? $stored->fields : null;
        if ($base === null) {
            $stored = new self();
            $stored->post_getEmpty();
            $base = $stored->fields + ['id' => 0, 'plugin_itilflow_processes_id' => (int) $process->getID(), 'name' => ''];
            $id = 0;
        }
        $stage = $base;
        // Номер этапа в черновике: у нового этапа номера ещё нет, а «этот этап» среди этапов для
        // возврата должен быть настоящим номером — иначе готовность видит «список повреждён».
        $self = self::draftSelfId($id, array_merge($process->getStages(), [['id' => self::largestId()]]));
        foreach (self::DRAFT_FIELDS as $field) {
            if (array_key_exists($field, $post) && is_scalar($post[$field])) { $stage[$field] = $post[$field]; }
        }
        $errors = []; $fields = [];
        $lists = self::normalizeApprovalInput(array_intersect_key($post, ['approver_users' => 1, 'approver_groups' => 1]));
        if ($lists === null) { $errors[] = 'Не больше 50 согласующих.'; } else { $stage = array_replace($stage, $lists); }
        if (isset($post['_structured_return_targets_present'])) {
            $stage['return_targets'] = DefinitionSnapshot::encode(self::draftTargets($post['_structured_return_targets'] ?? null, $self));
        }
        if (array_key_exists('_deadline_value', $post)) {
            $minutes = Deadline::toMinutes($post['_deadline_value'], (string) ($post['_deadline_unit'] ?? Deadline::DAY),
                Deadline::dayMinutes((int) ($process->fields['calendars_id'] ?? 0)));
            if ($minutes === null) {
                $fields['deadline'] = 'Срок этапа: укажите число рабочих дней или часов, например 3 или 1,5.';
            } else {
                $stage['deadline_minutes'] = $minutes;
                $error = $minutes > 0 ? Deadline::durationError($minutes, (int) ($process->fields['calendars_id'] ?? 0)) : null;
                if ($error !== null) { $fields['deadline'] = $error; }
            }
        }
        if (($stage['execution_mode'] ?? '') === self::MODE_DELEGATED && (int) ($stage['slas_id'] ?? 0) > 0) {
            $error = Deadline::slaError((int) $stage['slas_id'], self::childCalendar($stage, $process->fields));
            if ($error !== null) { $fields['slas_id'] = $error; }
        }
        $others = array_values(array_filter($process->getStages(), static fn(array $s): bool => (int) $s['id'] !== $id));
        $parsed = ['mode' => ConditionEditor::MODE_ALWAYS, 'join' => 'all', 'rule' => [], 'rows' => [], 'errors' => []];
        if (isset($post['_branch_present']) && !BranchConfiguration::formApproval($stage)) {
            $parsed = ConditionEditor::parseRows(is_array($post[BranchConfiguration::EDITOR] ?? null) ? $post[BranchConfiguration::EDITOR] : [],
                ConditionEditor::SCOPE_STAGE, $process->fields, $stage, $others);
            $errors = array_merge($errors, $parsed['errors']);
            $stage['run_if'] = $parsed['rule'] ? DefinitionSnapshot::encode($parsed['rule']) : null;
            try {
                $items = BranchConfiguration::decisionRows($post['_decision_rows'] ?? []);
                $stage['decision_items'] = $items ? DefinitionSnapshot::encode($items) : null;
            } catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
            if (in_array($post['_branch_unknown'] ?? null, ['run', 'ask'], true)) { $stage['unknown_policy'] = $post['_branch_unknown']; }
        } elseif (isset($post['_branch_present'])) {
            $stage['run_if'] = null;
        }
        $stage['id'] = $self;
        $all = $others;
        $all[] = $stage;
        usort($all, static fn(array $x, array $y): int => [(int) $x['ranking'], (int) $x['id']] <=> [(int) $y['ranking'], (int) $y['id']]);
        return ['stage' => $stage, 'stages' => $all, 'errors' => array_values(array_unique($errors)), 'fields' => $fields, 'parsed' => $parsed];
    }

    /**
     * Номер этапа в черновике: сохранённый — свой; новый — следующий за наибольшим номером этапов
     * маршрута. В базу не пишется: только чтобы «этот этап» был настоящим номером для готовности
     * и «Что дальше».
     */
    public static function draftSelfId(int $id, array $stages): int
    {
        if ($id > 0) { return $id; }
        $max = 0;
        foreach ($stages as $s) { $max = max($max, (int) ($s['id'] ?? 0)); }
        return $max + 1;
    }

    /** Наибольший номер этапа в базе: номер черновика нового этапа не совпадёт с этапом другого маршрута. */
    private static function largestId(): int
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => self::getTable()])->current();
        return (int) ($row['m'] ?? 0);
    }

    /** Отмеченные в форме этапы для возврата: «self» — сам этап ($self), остальные — номера этапов. */
    public static function draftTargets(mixed $raw, int $self): array
    {
        $targets = [];
        foreach (is_array($raw) ? $raw : [] as $t) {
            if ($t === 'self') { $targets[] = $self; continue; }
            if (is_scalar($t) && preg_match('/^[1-9][0-9]*$/D', (string) $t)) { $targets[] = (int) $t; }
        }
        return array_values(array_unique($targets));
    }

    /** Сущность, в которой должен исполняться этап для конкретной заявки. */
    public function resolveEntity(\CommonITILObject $item): int
    {
        switch ($this->fields['entity_strategy']) {
            case 'fixed':
                return (int) $this->fields['entities_id_target'];
            case 'by_group':
                $g = new \Group();
                if ((int) $this->fields['groups_id'] > 0 && $g->getFromDB((int) $this->fields['groups_id'])) {
                    return (int) $g->fields['entities_id'];
                }
                return (int) $item->fields['entities_id'];
            case 'inherit':
            default:
                return (int) $item->fields['entities_id'];
        }
    }

    /**
     * Проверка, которую GLPI не делает сама: модель принимает группу чужой
     * сущности без ошибки, фильтрует только выпадающий список в интерфейсе.
     */
    public function groupFitsEntity(int $entities_id): bool
    {
        $gid = (int) $this->fields['groups_id'];
        if ($gid === 0) {
            return true;
        }
        $g = new \Group();
        if (!$g->getFromDB($gid)) {
            return false;
        }
        $ge = (int) $g->fields['entities_id'];
        if ($ge === $entities_id) {
            return true;
        }
        if (!$g->fields['is_recursive']) {
            return false;
        }
        return in_array($ge, getAncestorsOf('glpi_entities', $entities_id), true);
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $tab[] = [
            'id' => '1', 'table' => self::getTable(), 'field' => 'name',
            'name' => __('Name'), 'datatype' => 'itemlink',
        ];
        $tab[] = [
            'id' => '2', 'table' => self::getTable(), 'field' => 'ranking',
            'name' => 'Порядок', 'datatype' => 'number',
        ];
        $tab[] = [
            'id' => '3', 'table' => self::getTable(), 'field' => 'execution_mode',
            'name' => 'Режим', 'datatype' => 'string',
        ];
        return $tab;
    }
}
