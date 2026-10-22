<?php

namespace GlpiPlugin\Itilflow;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** Маршрут: определение последовательности этапов. */
class Process extends CommonDBTM
{
    use RuntimeRead;

    use DefinitionMutation;
    public static $rightname = 'plugin_itilflow_process';
    public $dohistory = true;
    public static $tag_descriptions = [];

    public const ENFORCE_AUDIT  = 'audit';
    public const ENFORCE_WARN   = 'warn';
    public const ENFORCE_STRICT = 'strict';

    /** Имя редактора «Запускать, если» в форме маршрута: поля start_if_editor[…], ConditionEditor::parse. */
    public const START_EDITOR = 'start_if_editor';

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Маршруты этапов' : 'Маршрут этапов';
    }

    public static function getIcon(): string
    {
        return 'ti ti-route';
    }

    public static function getMenuName()
    {
        return 'Маршруты этапов';
    }

    /**
     * Кнопки над списком маршрутов — как «Импорт форм» у форм GLPI 11: импорт файла
     * и экспорт всех видимых маршрутов. Выбранные выгружает массовое действие «Экспорт».
     */
    public static function getAdditionalMenuLinks()
    {
        $links = [];
        if (self::canView()) {
            $links['<i class="ti ti-file-arrow-right me-1"></i>Экспорт всех'] = '/plugins/itilflow/front/export.php';
            $links['<i class="ti ti-chart-bar me-1"></i>Узкие места маршрутов'] = '/plugins/itilflow/front/report.php';
        }
        if (self::canCreate() && self::canUpdate()) {
            $links['<i class="ti ti-file-arrow-left me-1"></i>Импорт маршрутов'] = '/plugins/itilflow/front/import.php';
        }
        return $links ?: false;
    }

    public function getSpecificMassiveActions($checkitem = null)
    {
        $actions = parent::getSpecificMassiveActions($checkitem);
        if (self::canView()) {
            $actions[self::class . \MassiveAction::CLASS_ACTION_SEPARATOR . 'export']
                = '<i class="ti ti-file-arrow-right"></i>' . htmlescape('Экспорт');
        }
        return $actions;
    }

    /** «Экспорт» выбранных — как у форм GLPI 11: ссылка на файл и сразу переход к ней. */
    public static function showMassiveActionsSubForm(\MassiveAction $ma)
    {
        global $CFG_GLPI;
        if ($ma->getAction() !== 'export') {
            return parent::showMassiveActionsSubForm($ma);
        }
        $ids = array_values(array_map('intval', $ma->getItems()[self::class] ?? []));
        $url = $CFG_GLPI['root_doc'] . '/plugins/itilflow/front/export.php?' . http_build_query(['ids' => $ids]);
        echo '<a href="' . htmlescape($url) . '">' . htmlescape('Нажмите здесь, чтобы загрузить экспортированные маршруты...') . '</a>';
        echo \Html::scriptBlock("window.location.href = '" . \jsescape($url) . "';");
        return true;
    }

    public function isEntityAssign()
    {
        return true;
    }

    public function maybeRecursive()
    {
        return true;
    }

    /**
     * Кнопки формы маршрута и этапа — только те, что сервер разрешит: с одним «Чтением» нет ни
     * «Сохранить», ни «Удалить навсегда» (раньше они вели на «Доступ запрещен»).
     *
     * @return array{add:bool,update:bool,purge:bool}
     */
    public static function formButtons(\CommonDBTM $item): array
    {
        if ($item->isNewItem()) {
            return ['add' => (bool) $item::canCreate(), 'update' => false, 'purge' => false];
        }
        $id = (int) $item->getID();
        return ['add' => false, 'update' => (bool) $item->can($id, UPDATE), 'purge' => (bool) $item->can($id, PURGE)];
    }

    public static function getEnforcementLabels(): array
    {
        return [
            self::ENFORCE_AUDIT  => 'Только записать',
            self::ENFORCE_WARN   => 'Предупредить',
            self::ENFORCE_STRICT => 'Запретить',
        ];
    }

    public static function getSupportedItemtypes(): array
    {
        return [
            'Ticket'  => \Ticket::getTypeName(1),
            'Change'  => \Change::getTypeName(1),
            'Problem' => \Problem::getTypeName(1),
        ];
    }

    /** «Для чего маршрут» — подписи типов объекта в форме маршрута. */
    public static function getItemtypeFormLabels(): array
    {
        return [
            'Ticket'  => 'Заявки',
            'Change'  => 'Изменения',
            'Problem' => 'Проблемы',
        ];
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Stage::class, $tabs, $options);
        $this->addStandardTab(Scheme::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $structured = ($this->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE;
        TemplateRenderer::getInstance()->display('@itilflow/process.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'buttons'      => self::formButtons($this),
            'enforcements' => self::getEnforcementLabels(),
            'itemtypes'    => self::getItemtypeFormLabels(),
            'structured'   => $structured,
            'form_sources' => $structured ? FormConfiguration::formOptions() : [],
            'form_destinations' => $structured ? FormConfiguration::destinationOptions($this->fields) : [],
            'start'        => $structured && ($this->fields['itemtype'] ?? '') === 'Ticket' ? $this->startView() : null,
            'readiness'    => $this->isNewItem() ? null : Readiness::check($this),
            // Пересчёт строки готовности и «Назначения формы» без сохранения (front/draft.php).
            'draft_url'    => $this->isNewItem() ? '' : \Plugin::getWebDir('itilflow') . '/front/draft.php',
            'new_contract' => $this->isNewItem() ? (string) $this->fields['outcome_contract'] : '',
            'legacy'       => $this->isNewItem() || $structured ? null : [
                'lines'       => self::conversionLines(self::conversionPlan($this->getStages()), $this->runningCount()),
                'can_convert' => $this->canUpdateItem(),
            ],
        ]);
        return true;
    }

    /**
     * Блок «Запуск маршрута» формы маршрута: сводка, строки о согласовании
     * назначения и вопросах формы, редактор «Запускать, если» с пересчётом без
     * сохранения и «Другие маршруты этой формы» с их условиями.
     *
     * @return array{summary:string,info:array,editor:?array,siblings:list<array>}
     */
    public function startView(): array
    {
        $p = $this->fields;
        [$mode, $rule] = self::startMode($p['start_if'] ?? null);
        $formId = (int) ($p['forms_forms_id'] ?? 0);
        $form = new \Glpi\Form\Form();
        $formName = $formId > 0 && $form->getFromDB($formId) ? (string) ($form->fields['name'] ?? '') : '';
        $summary = ConditionEditor::summary(ConditionEditor::SCOPE_START, $mode, $rule);
        $editor = null; $siblings = [];
        if ($formId > 0 && !$this->isNewItem()) {
            $editor = ConditionEditor::view(ConditionEditor::SCOPE_START, self::START_EDITOR, $mode, $rule, $p)
                + ['draft_url' => \Plugin::getWebDir('itilflow') . '/front/draft.php'];
            foreach (StartReadiness::siblings($p) as $row) {
                $siblings[] = $row + ['url' => self::getFormURLWithID((int) $row['id'])];
            }
        }
        return [
            'summary' => $formId > 0 ? ($formName !== '' ? $formName . ' · ' : '') . $summary : 'форма не выбрана',
            'info' => FormConfiguration::informational($p, $this->isNewItem() ? [] : $this->getStages()),
            'editor' => $editor,
            'siblings' => $siblings,
        ];
    }

    /**
     * Режим редактора по processes.start_if: NULL — «Не запускать по форме», «Всегда»,
     * «Только если» с правилом. Повреждённая запись — «Только если» без условий: сохранить её
     * так нельзя, редактор просит задать условия заново.
     *
     * @return array{0:string,1:array}
     */
    public static function startMode(mixed $startIf): array
    {
        $start = StartRule::decode($startIf);
        if ($start === null) { return [ConditionEditor::MODE_OFF, []]; }
        if ($start['mode'] === StartRule::MODE_ALWAYS) { return [ConditionEditor::MODE_ALWAYS, []]; }
        return [ConditionEditor::MODE_IF, $start['mode'] === StartRule::MODE_CONDITIONS ? $start['rule'] : []];
    }

    /** Поля формы маршрута, от которых зависит строка готовности, — их берёт пересчёт без сохранения. */
    private const DRAFT_FIELDS = ['is_active', 'entities_id', 'is_recursive', 'calendars_id', 'forms_forms_id', 'forms_destinations_id'];

    /**
     * Маршрут «как в форме сейчас» для пересчёта без сохранения (front/draft.php): сохранённые
     * поля, поверх — введённые числа полей готовности и «Запускать, если» из редактора, если он
     * разбирается без ошибок. В базу не пишется.
     */
    public static function draftFields(array $fields, array $post): array
    {
        $saved = $fields;
        foreach (self::DRAFT_FIELDS as $field) {
            $value = $post[$field] ?? null;
            if ((is_string($value) || is_int($value)) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string) $value)) {
                $fields[$field] = (int) $value;
            }
        }
        if (is_array($post[self::START_EDITOR] ?? null)) {
            $start = self::startFromEditor($post, $saved);
            if ($start['error'] === null) { $fields['start_if'] = $start['start_if']; }
        }
        return $fields;
    }

    /**
     * «Запускать, если» из редактора формы маршрута — значение processes.start_if.
     * $fields — сохранённый маршрут; форма — из ввода, если её меняют в этой же отправке.
     * Сменили форму, а условия заданы по вопросам прежней — условия сбрасываются, запуск по
     * форме выключается (notice): у новой формы этих вопросов нет.
     *
     * @return array{start_if:?string,error:?string,notice:?string}
     */
    public static function startFromEditor(array $input, array $fields, bool $isNew = false): array
    {
        $editor = is_array($input[self::START_EDITOR] ?? null) ? $input[self::START_EDITOR] : [];
        $target = $fields;
        if (array_key_exists('forms_forms_id', $input)) { $target['forms_forms_id'] = (int) $input['forms_forms_id']; }
        $formChanged = !$isNew && array_key_exists('forms_forms_id', $input)
            && (int) $input['forms_forms_id'] !== (int) ($fields['forms_forms_id'] ?? 0);
        if ($formChanged && ($editor['mode'] ?? null) === ConditionEditor::MODE_IF) {
            return ['start_if' => null, 'error' => null,
                'notice' => 'Форма изменилась: «Запускать, если» сброшено — задайте заново.'];
        }
        $parsed = ConditionEditor::parse($editor, ConditionEditor::SCOPE_START, $target);
        if ($parsed['errors']) {
            return ['start_if' => null, 'error' => '«Запускать, если» — ' . $parsed['errors'][0], 'notice' => null];
        }
        $start = match ($parsed['mode']) {
            ConditionEditor::MODE_ALWAYS => ['mode' => StartRule::MODE_ALWAYS],
            ConditionEditor::MODE_IF => ['mode' => StartRule::MODE_CONDITIONS, 'rule' => $parsed['rule']],
            default => null,
        };
        return ['start_if' => StartRule::encode($start), 'error' => null, 'notice' => null];
    }

    /**
     * Значения по умолчанию для формы нового маршрута.
     *
     * Без этого GLPI подставляет в новую запись пустые строки, а
     * dropdownYesNo рисует пустое значение как «Нет». В результате маршрут,
     * созданный через интерфейс, получался неактивным — и Engine::start()
     * молча отказывался его запускать, из-за чего бизнес-правило выглядело
     * неработающим. Значения совпадают со значениями по умолчанию в схеме,
     * кроме режима контроля: для первого внедрения руководство советует
     * «Только записать», поэтому форма предлагает его, а не «Запретить».
     *
     * С 1.9.0 новый маршрут из формы сразу в новом порядке исходов. Маршрут,
     * созданный через API без outcome_contract, по умолчанию схемы остаётся
     * в прежнем порядке.
     */
    public function post_getEmpty()
    {
        $this->fields['itemtype']         = 'Ticket';
        $this->fields['enforcement']      = self::ENFORCE_AUDIT;
        $this->fields['is_active']        = 1;
        $this->fields['block_resolution'] = 1;
        $this->fields['public_log']       = 1;
        $this->fields['helpdesk_tab']     = 1;
        $this->fields['allow_withdraw']   = 1;
        $this->fields['is_recursive']     = 0;
        $this->fields['calendars_id']     = 0;
        $this->fields['version']          = 1;
        $this->fields['outcome_contract'] = DefinitionSnapshot::MODE;
        $this->fields['forms_forms_id'] = 0;
        $this->fields['forms_destinations_id'] = 0;
    }

    /**
     * Что заполнит «Перевести на новый порядок» — только пустые поля этапов.
     *
     * «прервать» → «ответить заявителю отказом»; «остановить» → «остановить»;
     * «вернуться на N» → «остановить» + точка возврата N; дочерняя → «исполнитель
     * отмечает результат»; задача → «остановить», без точек. Подготовленное заранее
     * не меняется. Возврат на более поздний или удалённый этап не переносится.
     *
     * Пустой список точек возврата («[]») — «не задано», как NULL: форма 1.8.0
     * отправляла этот список у этапа любого маршрута, и у старого маршрута, хоть раз
     * сохранённого ею, он пустой, хотя точку возврата никто не выбирал.
     *
     * policy и to — то, что будет в этапе после перевода: заполненное плюс
     * сохранённое. По ним строятся диалог и сообщение после перевода.
     *
     * @param list<array> $stages строки этапов по порядку
     * @return list<array{id:int,ranking:int,name:string,mode:string,kind:string,from:?int,set:array<string,string>,policy:string,to:list<int>}>
     *   kind: task, abort, block, back (есть точки возврата), back_lost (возврат не перенесён),
     *   kept (ничего не меняется); from — номер этапа из «Вернуться на этап», to — номера точек возврата
     */
    public static function conversionPlan(array $stages): array
    {
        $by_id = [];
        foreach ($stages as $s) {
            $by_id[(int) $s['id']] = $s;
        }
        $plan = [];
        foreach ($stages as $s) {
            $mode = (string) $s['execution_mode'];
            $from = null;
            $lost = false;
            $policy = Stage::REJECT_BLOCK;
            $targets = [];
            if ($mode !== Stage::MODE_INLINE) {
                $policy = $s['on_reject'] === Stage::REJECT_ABORT ? Stage::REJECT_ABORT : Stage::REJECT_BLOCK;
                if ($s['on_reject'] === Stage::REJECT_BACK) {
                    $to = $by_id[(int) $s['plugin_itilflow_stages_id_reject']] ?? null;
                    $from = $to !== null ? (int) $to['ranking'] : null;
                    if ($to !== null && (int) $to['ranking'] <= (int) $s['ranking']) {
                        $targets = [(int) $to['id']];
                    } else {
                        $lost = true;
                    }
                }
            }
            $set = [];
            $stored_policy = (string) ($s['negative_policy'] ?? '');
            if (!in_array($stored_policy, [Stage::REJECT_BLOCK, Stage::REJECT_ABORT], true)) {
                $set['negative_policy'] = $policy;
            }
            $stored_targets = DefinitionSnapshot::targets($s['return_targets'] ?? null);
            if ($stored_targets === null || ($stored_targets === [] && $targets !== [])) {
                $set['return_targets'] = DefinitionSnapshot::encode($targets);
            }
            if ($mode === Stage::MODE_DELEGATED
                && !in_array($s['department_result_source'] ?? '', ['assignee_action', 'native_validation'], true)) {
                $set['department_result_source'] = 'assignee_action';
            }
            $final_policy = $set['negative_policy'] ?? $stored_policy;
            $final_targets = isset($set['return_targets']) ? $targets : $stored_targets;
            $to_ranks = array_map(static fn(int $t): int => (int) ($by_id[$t]['ranking'] ?? 0), $final_targets);
            if ($mode === Stage::MODE_INLINE) {
                $kind = 'task';
            } elseif (!$set) {
                $kind = 'kept';
            } elseif ($to_ranks) {
                $kind = 'back';
            } elseif ($lost) {
                $kind = 'back_lost';
            } else {
                $kind = $final_policy === Stage::REJECT_ABORT ? 'abort' : 'block';
            }
            $plan[] = ['id' => (int) $s['id'], 'ranking' => (int) $s['ranking'], 'name' => (string) $s['name'],
                'mode' => $mode, 'kind' => $kind, 'from' => $from, 'set' => $set,
                'policy' => $final_policy, 'to' => $to_ranks];
        }
        return $plan;
    }

    /**
     * Строки диалога «Перевести на новый порядок» и сообщения после него: что будет
     * в этапах — заполненное плюс сохранённое (policy и to плана), а не прежний исход.
     *
     * @return list<string>
     */
    public static function conversionLines(array $plan, int $running): array
    {
        $groups = ['back' => [], 'back_lost' => [], 'abort' => [], 'block' => [], 'task' => [], 'kept' => []];
        $delegated = [];
        foreach ($plan as $row) {
            $groups[$row['kind']][] = $row;
            if (isset($row['set']['department_result_source'])) {
                $delegated[] = $row['ranking'];
            }
        }
        $ranks = static fn(array $rows): string => self::andList(array_map(static fn(array $r): string => (string) $r['ranking'], $rows));
        $labels = array_map(static fn(array $c): string => $c[0], Stage::getNegativePolicyChoices());
        $policy = static fn(array $r): string => $labels[$r['policy']] ?? $labels[Stage::REJECT_BLOCK];
        $lines = [$running > 0
            ? sprintf('Новые заявки пойдут по новому порядку. %d %s по старому.', $running,
                self::plural($running, 'начатая дойдёт', 'начатые дойдут', 'начатых дойдут'))
            : 'Новые заявки пойдут по новому порядку. Начатых заявок нет.'];
        // Этапы с точками возврата: одной строкой те, у кого одинаковые «при отказе» и точки.
        $back = [];
        foreach ($groups['back'] as $r) {
            $back[$r['policy'] . '|' . implode(',', $r['to'])][] = $r;
        }
        foreach ($back as $rows) {
            $lines[] = sprintf('%s %s: при отказе — «%s», вернуть можно на %s %s.',
                count($rows) === 1 ? 'Этап' : 'Этапы',
                self::andList(array_map(static fn(array $r): string => $r['ranking']
                    . ($r['from'] !== null ? ' («Вернуться на этап ' . $r['from'] . '»)' : ''), $rows)),
                $policy($rows[0]),
                count($rows[0]['to']) === 1 ? 'этап' : 'этапы',
                self::andList(array_map('strval', $rows[0]['to'])));
        }
        foreach ($groups['back_lost'] as $r) {
            $lines[] = sprintf('Этап %d: этап для возврата стоит позже или удалён — при отказе «%s», выберите этап для возврата в этапе.',
                $r['ranking'], $policy($r));
        }
        if ($groups['abort']) {
            $lines[] = sprintf('%s %s: при отказе — «%s».',
                count($groups['abort']) === 1 ? 'Этап' : 'Этапы', $ranks($groups['abort']), $labels[Stage::REJECT_ABORT]);
        }
        if ($groups['block']) {
            $lines[] = sprintf('%s %s: при отказе — «%s».', count($groups['block']) === 1 ? 'Этап' : 'Этапы', $ranks($groups['block']),
                $labels[Stage::REJECT_BLOCK]);
        }
        if ($delegated) {
            $lines[] = sprintf('Дочерние заявки на %s %s: отдел сообщает результат кнопками «Выполнено», «Отказ», «Вернуть на уточнение».',
                count($delegated) === 1 ? 'этапе' : 'этапах', self::andList(array_map('strval', $delegated)));
        }
        if ($groups['task']) {
            $lines[] = sprintf('%s %s: выбирать ничего не нужно.', count($groups['task']) === 1 ? 'Задача' : 'Задачи', $ranks($groups['task']));
        }
        if ($groups['kept']) {
            $lines[] = sprintf('%s %s уже %s заранее и не меняются.', count($groups['kept']) === 1 ? 'Этап' : 'Этапы', $ranks($groups['kept']),
                count($groups['kept']) === 1 ? 'настроен' : 'настроены');
        }
        $lines[] = 'Проверить и поправить можно в каждом этапе.';
        return $lines;
    }

    /** Начатые заявки маршрута, которые доходят по прежнему порядку. */
    public function runningCount(): int
    {
        return countElementsInTable(Instance::getTable(), ['plugin_itilflow_processes_id' => $this->getID(),
            'state' => [Instance::RUNNING, Instance::HALTED]]);
    }

    /**
     * «Перевести на новый порядок»: одна транзакция определения, версия +1, затем
     * сводка того, что заполнено. Начатые заявки доходят по прежнему порядку.
     *
     * @return list<array>|null план перевода; null — маршрут уже в новом порядке или перевод не прошёл
     */
    public function convertToStructured(): ?array
    {
        if ($this->isNewItem() || ($this->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE) {
            return null;
        }
        $running = $this->runningCount();
        $plan = null;
        $ok = $this->definitionMutation(['id' => $this->getID()], function () use (&$plan): bool {
            global $DB;
            if (($this->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE) {
                return false;
            }
            $plan = self::conversionPlan($this->getStages());
            foreach ($plan as $row) {
                if ($row['set'] && !$DB->update(Stage::getTable(), $row['set'], ['id' => $row['id']])) {
                    throw new \RuntimeException('Этап не обновлён.');
                }
            }
            return (bool) parent::update(['id' => $this->getID(), 'outcome_contract' => DefinitionSnapshot::MODE]);
        });
        if (!$ok || $plan === null) {
            return null;
        }
        $this->getFromDB($this->getID());
        $lines = self::conversionLines($plan, $running);
        Session::addMessageAfterRedirect(htmlescape('Маршрут переведён на новый порядок. '
            . implode(' ', array_slice($lines, 1, -1)) . ' ' . $lines[0]), false, INFO);
        return $plan;
    }

    private static function andList(array $items): string
    {
        $last = array_pop($items);
        return $items ? implode(', ', $items) . ' и ' . $last : (string) $last;
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

    public function prepareInputForAdd($input)
    {
        $input['version'] = 1;
        return $this->validateInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        // Правка определения поднимает версию: уже запущенные экземпляры
        // продолжают идти по своему снимку и историю не переписывают.
        if (!isset($input['_no_version_bump'])) {
            $input['version'] = (int) $this->fields['version'] + 1;
        }
        return $this->validateInput($input);
    }

    private function validateInput(array $input): array
    {
        // Блок «Запуск маршрута»: редактор «Запускать, если» → start_if (проверка — ниже, со связью с формой).
        if (array_key_exists(self::START_EDITOR, $input)) {
            $start = self::startFromEditor($input, $this->fields, $this->isNewItem());
            unset($input[self::START_EDITOR]);
            if ($start['error'] !== null) { Session::addMessageAfterRedirect(htmlescape($start['error']), false, ERROR); return []; }
            if ($start['notice'] !== null) { Session::addMessageAfterRedirect(htmlescape($start['notice']), false, WARNING); }
            $input['start_if'] = $start['start_if'];
        }
        if (isset($input['forms_forms_id']) && (int) $input['forms_forms_id'] !== (int) ($this->fields['forms_forms_id'] ?? 0)
            && !array_key_exists('forms_destinations_id', $input)) { $input['forms_destinations_id'] = 0; }
        $source_changed = $this->isNewItem()
            || (isset($input['forms_forms_id']) && (int) $input['forms_forms_id'] !== (int) ($this->fields['forms_forms_id'] ?? 0))
            || (isset($input['forms_destinations_id']) && (int) $input['forms_destinations_id'] !== (int) ($this->fields['forms_destinations_id'] ?? 0));
        $validate_source = $source_changed || array_key_exists('start_if', $input)
            || (!empty($input['is_active']) && empty($this->fields['is_active']))
            || (isset($input['itemtype']) && $input['itemtype'] !== ($this->fields['itemtype'] ?? ''))
            || (isset($input['outcome_contract']) && $input['outcome_contract'] !== ($this->fields['outcome_contract'] ?? ''));
        $form_error = $validate_source ? FormConfiguration::inputError(array_replace($this->fields, $input), true) : null;
        if ($form_error !== null) { Session::addMessageAfterRedirect($form_error, false, ERROR); return []; }
        if ($validate_source) {
            $process = array_replace($this->fields, $input);
            $start = StartRule::decode($process['start_if'] ?? null);
            if ($start !== null) {
                if (($process['itemtype'] ?? '') !== 'Ticket'
                    || ($process['outcome_contract'] ?? '') !== DefinitionSnapshot::MODE
                    || (int) ($process['forms_forms_id'] ?? 0) <= 0) {
                    Session::addMessageAfterRedirect('Для запуска по ответам выберите форму заявки и маршрут заявок нового порядка.', false, ERROR);
                    return [];
                }
                $errors = StartRule::validate($start, FormFacts::questionCatalog((int) $process['forms_forms_id']));
                if ($errors !== []) {
                    Session::addMessageAfterRedirect(implode(' ', $errors), false, ERROR);
                    return [];
                }
            }
            if (array_key_exists('start_if', $input)) { $input['start_if'] = StartRule::encode($start); }
        }
        if (isset($input['outcome_contract']) && !in_array($input['outcome_contract'], ['legacy', DefinitionSnapshot::MODE], true)) {
            Session::addMessageAfterRedirect('Неизвестный порядок работы маршрута. Обновите страницу.', false, ERROR);
            return [];
        }
        // Полнота нового порядка проверяется только у маршрута с этапами: пустой новый
        // маршрут сохраняется, недостающее показывает строка готовности. Вместо общей
        // фразы — первая причина из строки готовности, с номером этапа.
        $stages = $this->isNewItem() ? [] : $this->getStages();
        if (($input['outcome_contract'] ?? $this->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE && $stages) {
            $reason = Readiness::firstSnapshotReason(array_replace($this->fields, $input), $stages);
            if ($reason !== null) {
                // Текст строки готовности — с названиями этапов, а сообщения после перехода
                // GLPI выводит как HTML: экранируется здесь, шаблоны экранируют сами.
                Session::addMessageAfterRedirect(htmlescape($reason), false, ERROR);
                return [];
            }
        }
        if (isset($input['name']) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect('Укажите название маршрута.', false, ERROR);
            return [];
        }
        if (isset($input['enforcement'])
            && !array_key_exists($input['enforcement'], self::getEnforcementLabels())) {
            Session::addMessageAfterRedirect('Неизвестное значение «Если действие не по порядку этапов».', false, ERROR);
            return [];
        }
        if (isset($input['itemtype'])
            && !array_key_exists($input['itemtype'], self::getSupportedItemtypes())) {
            Session::addMessageAfterRedirect('Неподдерживаемый тип объекта.', false, ERROR);
            return [];
        }
        return $input;
    }

    /** Этапы маршрута по порядку. */
    public function getStages(): array
    {
        $out = [];
        foreach ((new Stage())->find(
            ['plugin_itilflow_processes_id' => $this->getID()],
            ['ranking ASC', 'id ASC']
        ) as $id => $row) {
            $row['id'] = $id;
            $out[] = $row;
        }
        return $out;
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $tab[] = [
            'id' => '1', 'table' => self::getTable(), 'field' => 'name',
            'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => '2', 'table' => self::getTable(), 'field' => 'id',
            'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => '3', 'table' => self::getTable(), 'field' => 'itemtype',
            'name' => 'Тип объекта', 'datatype' => 'itemtypename',
            'itemtype_list' => 'itil_types', 'massiveaction' => false,
        ];
        // Выбор из трёх значений — и в поиске, и в массовом «Обновлении» (не поле свободного ввода).
        $tab[] = [
            'id' => '4', 'table' => self::getTable(), 'field' => 'enforcement',
            'name' => 'Если действие не по порядку этапов', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id' => '5', 'table' => self::getTable(), 'field' => 'is_active',
            'name' => __('Active'), 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => '6', 'table' => self::getTable(), 'field' => 'version',
            'name' => 'Версия', 'datatype' => 'number', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => '80', 'table' => 'glpi_entities', 'field' => 'completename',
            'name' => \Entity::getTypeName(1), 'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id' => '86', 'table' => self::getTable(), 'field' => 'is_recursive',
            'name' => __('Child entities'), 'datatype' => 'bool',
        ];
        $tab[] = [
            'id' => '16', 'table' => self::getTable(), 'field' => 'comment',
            'name' => __('Comments'), 'datatype' => 'text',
        ];
        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) { $values = [$field => $values]; }
        if ($field === 'enforcement') {
            $value = (string) ($values[$field] ?? '');
            return htmlescape(self::getEnforcementLabels()[$value] ?? $value);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) { $values = [$field => $values]; }
        if ($field === 'enforcement') {
            $options['display'] = false;
            $options['value'] = (string) ($values[$field] ?? '');
            return \Dropdown::showFromArray($name, self::getEnforcementLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([Stage::class]);
    }
}
