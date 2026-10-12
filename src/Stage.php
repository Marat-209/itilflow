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
            self::MODE_INLINE    => 'Задача в заявке',
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
            self::APPROVER_FIXED   => 'Указан в этапе',
            self::APPROVER_RUNTIME => 'Выбирают при прохождении',
        ];
    }

    public static function getRejectLabels(): array
    {
        return [
            self::REJECT_BLOCK => 'Остановить маршрут и ждать решения',
            self::REJECT_BACK  => 'Вернуться на этап',
            self::REJECT_ABORT => 'Прервать маршрут',
        ];
    }

    /** Кто согласует — подпись и пояснение варианта в форме. */
    public static function getApproverSourceChoices(): array
    {
        return [
            self::APPROVER_FIXED   => ['Указан в этапе', 'Группа или сотрудник ниже.'],
            self::APPROVER_RUNTIME => ['Выбирают при прохождении', 'Исполнитель заявки укажет согласующих и напишет, почему они.'],
        ];
    }

    /** Как отдел сообщает итог (новый порядок исходов). */
    public static function getResultSourceChoices(): array
    {
        return [
            'assignee_action'   => ['Исполнитель отмечает результат', '«Выполнено», «Отказ» или «Вернуть на уточнение» в строке этапа.'],
            'native_validation' => ['Визой в заявке отдела', 'Группе или сотруднику отдела уходят визы; итог — по их ответам.'],
        ];
    }

    /** Что делать при отказе (новый порядок исходов). */
    public static function getNegativePolicyChoices(): array
    {
        return [
            self::REJECT_ABORT => ['Ответить заявителю отказом и завершить маршрут', 'Заявка решится с текстом «Отказ на этапе …» и причиной.'],
            self::REJECT_BLOCK => ['Остановить маршрут', 'Решит администратор процессов: новая попытка или прерывание.'],
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
            'rows'       => self::tableRows($process, $readiness['problems']),
            'can_edit'   => $process->canUpdateItem(),
            'readiness'  => $readiness,
        ]);
    }

    /**
     * Строки вкладки «Этапы»: то, что действует, словами формы.
     * «Если отказ» — по порядку исходов маршрута; срок — в днях или часах календаря.
     */
    public static function tableRows(Process $process, array $problems = []): array
    {
        $stages = $process->getStages();
        $structured = ($process->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;
        $day = Deadline::dayMinutes((int) ($process->fields['calendars_id'] ?? 0));
        $ranks = array_column($stages, 'ranking', 'id');
        $modes = self::getModeLabels();
        $rows = [];
        foreach ($stages as $s) {
            $mode = (string) $s['execution_mode'];
            $who = '';
            if ($mode === self::MODE_APPROVAL && $s['approver_source'] !== self::APPROVER_FIXED) {
                $who = 'согласующий — при прохождении';
            } else {
                $g = new \Group();
                if ((int) $s['groups_id'] > 0 && $g->getFromDB((int) $s['groups_id'])) {
                    $who = (string) $g->fields['name'];
                } elseif ((int) $s['users_id'] > 0) {
                    $who = (string) getUserName((int) $s['users_id']);
                }
            }
            $who_sub = '';
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
                    self::REJECT_ABORT => 'отказ заявителю',
                    self::REJECT_BLOCK => 'остановить',
                    default            => 'не выбрано',
                };
                $targets = array_map(static fn(int $t): string => isset($ranks[$t]) ? (string) $ranks[$t] : '?',
                    DefinitionSnapshot::targets($s['return_targets'] ?? null) ?? []);
                $reject_sub = 'возврат: ' . ($targets ? implode(', ', $targets) : 'нет');
            } elseif ($mode !== self::MODE_INLINE) {
                $reject = match ($s['on_reject']) {
                    self::REJECT_BACK  => 'вернуть на ' . ($ranks[(int) $s['plugin_itilflow_stages_id_reject']] ?? '?'),
                    self::REJECT_ABORT => 'прервать маршрут',
                    default            => 'остановить маршрут',
                };
            }
            $rows[] = [
                'id'          => (int) $s['id'],
                'ranking'     => (int) $s['ranking'],
                'name'        => (string) $s['name'],
                'mode'        => $modes[$mode] ?? $mode,
                'who'         => $who,
                'who_sub'     => $who_sub,
                'deadline'    => Deadline::label((int) $s['deadline_minutes'], $day),
                'reject'      => $reject,
                'reject_sub'  => $reject_sub,
                'report_required' => $mode === self::MODE_INLINE && $s['completion_comment'] === 'required',
                'is_optional' => $mode === self::MODE_INLINE && !empty($s['is_optional']),
                'problems'    => array_column(array_filter($problems,
                    static fn(array $x): bool => $x['stage'] === (int) $s['id']), 'text'),
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
            $targets[] = ['value' => 'self', 'label' => sprintf('%d. новый этап — этот этап', $rank)];
            $targets_selected = ['self'];
        }

        $calendars_id = (int) ($process->fields['calendars_id'] ?? 0);
        $day = Deadline::dayMinutes($calendars_id);
        [$dl_value, $dl_unit] = Deadline::split((int) ($this->fields['deadline_minutes'] ?? 0), $day);

        TemplateRenderer::getInstance()->display('@itilflow/stage.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'processes_id' => $processes_id,
            'process'      => $process,
            'structured'   => $structured,
            'layout'       => StageForm::layout($this->fields, $structured, $itemtype),
            'glpi_admin'   => Session::haveRight('config', UPDATE),
            'modes'        => self::getModeLabels(),
            'strategies'   => self::getEntityStrategyLabels(),
            'rejects'      => self::getRejectLabels(),
            'completions'  => self::getCompletionCommentLabels(),
            'approver_choices' => self::getApproverSourceChoices(),
            'result_choices'   => self::getResultSourceChoices(),
            'negative_choices' => self::getNegativePolicyChoices(),
            'statuses'     => $statuses,
            'status_by_mode' => $this->isNewItem() ? $status_by_mode : null,
            'siblings'     => $siblings,
            'targets'      => $targets,
            'targets_selected' => $targets_selected,
            'position'     => $position,
            'total'        => count($stages),
            'readiness'    => $this->isNewItem() || $processes_id <= 0 ? null
                : ['problems' => Readiness::forStage($process, (int) $this->getID())],
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
        $this->fields['on_reject']          = self::REJECT_BLOCK;
        $this->fields['completion_comment'] = 'optional';
        $this->fields['is_optional']        = 0;
        $this->fields['deadline_minutes']   = 0;
        $this->fields['ticket_status']      = 0;
        // Новый этап (решение 12.10): при отказе — ответить заявителю отказом и завершить
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
                Session::addMessageAfterRedirect('Срок этапа: укажите число рабочих дней или часов, например 3 или 1.5.', false, ERROR);
                return [];
            }
            $input['deadline_minutes'] = $minutes;
        }
        unset($input['_deadline_value'], $input['_deadline_unit']);

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
                    Session::addMessageAfterRedirect('Точки возврата заданы неверно — отметьте их заново.', false, ERROR);
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
            $merged = array_replace($this->fields, $input);
            $targets = DefinitionSnapshot::targets($merged['return_targets'] ?? '[]');
            // Три поля — три сообщения, словами формы.
            if ($targets === null) {
                Session::addMessageAfterRedirect('Точки возврата заданы неверно — отметьте их заново.', false, ERROR);
                return [];
            }
            if (($merged['execution_mode'] ?? self::MODE_INLINE) === self::MODE_DELEGATED
                && !in_array($merged['department_result_source'] ?? '', ['assignee_action', 'native_validation'], true)) {
                Session::addMessageAfterRedirect('Выберите, как отдел сообщает итог: без этого маршрут не запустится.', false, ERROR);
                return [];
            }
            // У задачи отказа не бывает, движок это поле не читает, а проверка определения
            // требует значение у каждого этапа: пустое становится «остановить».
            if (($merged['execution_mode'] ?? self::MODE_INLINE) === self::MODE_INLINE
                && !in_array($merged['negative_policy'] ?? '', ['block', 'abort'], true)) {
                $input['negative_policy'] = $merged['negative_policy'] = self::REJECT_BLOCK;
            }
            if (!in_array($merged['negative_policy'] ?? '', ['block', 'abort'], true)) {
                Session::addMessageAfterRedirect('Выберите, что делать при отказе: без этого маршрут не запустится.', false, ERROR);
                return [];
            }
            foreach ($targets as $target) {
                if ($target === (int) $this->getID()) {
                    continue; // этот же этап — всегда «не позже себя»
                }
                $stage = new self();
                if (!$stage->getFromDB($target) || (int) $stage->fields['plugin_itilflow_processes_id'] !== $pid) {
                    Session::addMessageAfterRedirect('Точка возврата не найдена: этап удалён или из другого маршрута. Обновите форму.', false, ERROR);
                    return [];
                }
                if ((int) $stage->fields['ranking'] > (int) ($merged['ranking'] ?? 0)) {
                    Session::addMessageAfterRedirect(sprintf(
                        'Точка возврата «%d. %s» стоит позже этого этапа (%d): отметьте этот этап или предыдущие.',
                        $stage->fields['ranking'], $stage->fields['name'], (int) ($merged['ranking'] ?? 0)
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
            Session::addMessageAfterRedirect('Неизвестный режим исполнения этапа.', false, ERROR);
            return [];
        }

        $groups_id = (int) ($input['groups_id'] ?? $this->fields['groups_id'] ?? 0);
        $users_id  = (int) ($input['users_id'] ?? $this->fields['users_id'] ?? 0);
        $src = (string) ($input['approver_source'] ?? $this->fields['approver_source']
            ?? self::APPROVER_FIXED);

        if (!array_key_exists($src, self::getApproverSourceLabels())) {
            Session::addMessageAfterRedirect('Неизвестный источник согласующего.', false, ERROR);
            return [];
        }

        // Статус, выставляемый при открытии этапа. 0 — не менять.
        if (array_key_exists('ticket_status', $input)) {
            $status = (int) $input['ticket_status'];
            $itemtype = (string) ($input['_itemtype'] ?? $this->getItemtype());
            if (!array_key_exists($status, self::getStatusChoices($itemtype))) {
                Session::addMessageAfterRedirect(
                    'Этот статус нельзя выставить на этапе: он либо не подходит '
                    . 'типу объекта маршрута, либо закрывает объект. Завершение '
                    . 'настраивается у маршрута, а не у этапа.',
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

        if ($groups_id === 0 && $users_id === 0 && !$approver_known_later) {
            Session::addMessageAfterRedirect(
                'У этапа должен быть ответственный: группа или пользователь.',
                false,
                ERROR
            );
            return [];
        }
        if ($approver_known_later && ($groups_id > 0 || $users_id > 0)) {
            Session::addMessageAfterRedirect(
                'Для выбранного источника согласующего группа и сотрудник в этапе не нужны — '
                . 'они будут определены при прохождении заявки. Поля очищены.',
                false,
                WARNING
            );
            $input['groups_id'] = 0;
            $input['users_id']  = 0;
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
            foreach ((new self())->find($crit) as $other) {
                if ((int) $other['id'] !== (int) $this->getID()) { $conflict = true; break; }
            }
            if ($conflict) {
                $max = 0;
                foreach ((new self())->find(['plugin_itilflow_processes_id' => $processes_id]) as $r) {
                    $max = max($max, (int) $r['ranking']);
                }
                $input['ranking'] = $max + 1;
                Session::addMessageAfterRedirect(
                    sprintf(
                        'Этап с порядком %d в этом маршруте уже есть. '
                        . 'Чтобы порядок не стал неопределённым, этапу присвоен номер %d — '
                        . 'поправьте его вручную, если нужно другое место.',
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
            Session::addMessageAfterRedirect('Укажите целевую сущность.', false, ERROR);
            return [];
        }
        if ($mode === self::MODE_DELEGATED && $strategy === 'by_group' && $groups_id === 0) {
            Session::addMessageAfterRedirect(
                'Стратегия «сущность группы» требует указать группу.',
                false,
                ERROR
            );
            return [];
        }

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
            Session::addMessageAfterRedirect(sprintf('Этап %d «%s» удалён и убран из точек возврата %s %s.',
                $u['ranking'], $u['name'], count($u['ranks']) === 1 ? 'этапа' : 'этапов', implode(', ', $u['ranks'])), false, INFO);
        }
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
