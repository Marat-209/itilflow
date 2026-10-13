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

    public function isEntityAssign()
    {
        return true;
    }

    public function maybeRecursive()
    {
        return true;
    }

    public static function getEnforcementLabels(): array
    {
        return [
            self::ENFORCE_AUDIT  => 'Журнал — разрешить и записать',
            self::ENFORCE_WARN   => 'Предупреждение — разрешить с предупреждением',
            self::ENFORCE_STRICT => 'Запрет — не дать сделать',
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
            'enforcements' => self::getEnforcementLabels(),
            'itemtypes'    => self::getItemtypeFormLabels(),
            'structured'   => $structured,
            'readiness'    => $this->isNewItem() ? null : Readiness::check($this),
            'new_contract' => $this->isNewItem() ? (string) $this->fields['outcome_contract'] : '',
            'legacy'       => $this->isNewItem() || $structured ? null : [
                'lines'       => self::conversionLines(self::conversionPlan($this->getStages()), $this->runningCount()),
                'can_convert' => $this->canUpdateItem(),
            ],
        ]);
        return true;
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
     * «Журнал», поэтому форма предлагает его, а не «Запрет».
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
            ? sprintf('Новые заявки пойдут по новому порядку. %d %s по прежнему.', $running,
                self::plural($running, 'начатая доходит', 'начатые доходят', 'начатых доходят'))
            : 'Новые заявки пойдут по новому порядку. Начатых заявок нет.'];
        // Этапы с точками возврата: одной строкой те, у кого одинаковые «при отказе» и точки.
        $back = [];
        foreach ($groups['back'] as $r) {
            $back[$r['policy'] . '|' . implode(',', $r['to'])][] = $r;
        }
        foreach ($back as $rows) {
            $lines[] = sprintf('%s %s: при отказе — «%s», %s — %s.',
                count($rows) === 1 ? 'Этап' : 'Этапы',
                self::andList(array_map(static fn(array $r): string => $r['ranking']
                    . ($r['from'] !== null ? ' («Вернуться на этап ' . $r['from'] . '»)' : ''), $rows)),
                $policy($rows[0]),
                count($rows[0]['to']) === 1 ? 'точка возврата' : 'точки возврата',
                self::andList(array_map('strval', $rows[0]['to'])));
        }
        foreach ($groups['back_lost'] as $r) {
            $lines[] = sprintf('Этап %d: этап для возврата позже или удалён — при отказе «%s», точку возврата выберите в этапе.',
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
            $lines[] = sprintf('Дочерние заявки на %s %s: отдел сообщает итог — «Исполнитель отмечает результат».',
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
        Session::addMessageAfterRedirect('Маршрут переведён на новый порядок. '
            . implode(' ', array_slice($lines, 1, -1)) . ' ' . $lines[0], false, INFO);
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
        if (isset($input['outcome_contract']) && !in_array($input['outcome_contract'], ['legacy', DefinitionSnapshot::MODE], true)) {
            Session::addMessageAfterRedirect('Неизвестный контракт исходов.', false, ERROR);
            return [];
        }
        // Полнота нового порядка проверяется только у маршрута с этапами: пустой новый
        // маршрут сохраняется, недостающее показывает строка готовности. Вместо общей
        // фразы — первая причина из строки готовности, с номером этапа.
        $stages = $this->isNewItem() ? [] : $this->getStages();
        if (($input['outcome_contract'] ?? $this->fields['outcome_contract'] ?? 'legacy') === DefinitionSnapshot::MODE && $stages) {
            $reason = Readiness::firstSnapshotReason(array_replace($this->fields, $input), $stages);
            if ($reason !== null) {
                Session::addMessageAfterRedirect($reason, false, ERROR);
                return [];
            }
        }
        if (isset($input['name']) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect('Укажите название маршрута.', false, ERROR);
            return [];
        }
        if (isset($input['enforcement'])
            && !array_key_exists($input['enforcement'], self::getEnforcementLabels())) {
            Session::addMessageAfterRedirect('Неизвестный режим контроля.', false, ERROR);
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
        $tab[] = [
            'id' => '4', 'table' => self::getTable(), 'field' => 'enforcement',
            'name' => 'Режим контроля', 'datatype' => 'string',
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

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([Stage::class]);
    }
}
