<?php

namespace GlpiPlugin\Itilflow;

use CommonDBChild;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** Этап маршрута. */
class Stage extends CommonDBChild
{
    public static $rightname = 'plugin_itilflow_process';
    public static $itemtype = Process::class;
    public static $items_id = 'plugin_itilflow_processes_id';
    public $dohistory = true;

    public const MODE_INLINE    = 'inline';    // задача в самой заявке
    public const MODE_DELEGATED = 'delegated'; // дочерняя заявка (в т.ч. в другой сущности)
    public const MODE_APPROVAL  = 'approval';  // штатное согласование

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
            'inherit'  => 'Сущность родительской заявки',
            'fixed'    => 'Заданная сущность',
            'by_group' => 'Сущность группы-исполнителя',
        ];
    }

    public static function getCompletionCommentLabels(): array
    {
        return [
            'none'     => 'Не запрашивать',
            'optional' => 'Запрашивать, заполнение по желанию',
            'required' => 'Запрашивать, заполнение обязательно',
        ];
    }

    public static function getRejectLabels(): array
    {
        return [
            self::REJECT_BLOCK => 'Остановить маршрут и ждать решения',
            self::REJECT_BACK  => 'Вернуться на указанный этап',
            self::REJECT_ABORT => 'Прервать маршрут',
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
        $stages = $process->getStages();
        $groups = [];
        foreach ($stages as $s) {
            if ($s['groups_id']) {
                $g = new \Group();
                $g->getFromDB($s['groups_id']);
                $groups[$s['groups_id']] = $g->fields['name'] ?? '';
            }
        }
        TemplateRenderer::getInstance()->display('@itilflow/stages.html.twig', [
            'process'    => $process,
            'stages'     => $stages,
            'group_names' => $groups,
            'modes'      => self::getModeLabels(),
            'can_edit'   => $process->canUpdateItem(),
            'new_stage'  => new self(),
        ]);
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

        $siblings = [0 => '-----'];
        foreach ($process->getStages() as $s) {
            if ((int) $s['id'] !== (int) $this->getID()) {
                $siblings[$s['id']] = sprintf('%d. %s', $s['ranking'], $s['name']);
            }
        }

        TemplateRenderer::getInstance()->display('@itilflow/stage.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'processes_id' => $processes_id,
            'process'      => $process,
            'modes'        => self::getModeLabels(),
            'strategies'   => self::getEntityStrategyLabels(),
            'rejects'      => self::getRejectLabels(),
            'completions'  => self::getCompletionCommentLabels(),
            'siblings'     => $siblings,
        ]);
        return true;
    }

    public function prepareInputForAdd($input)
    {
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
        if ($groups_id === 0 && $users_id === 0) {
            Session::addMessageAfterRedirect(
                'У этапа должен быть ответственный: группа или пользователь.',
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
            if (!$this->isNewItem()) {
                $crit[] = ['NOT' => ['id' => $this->getID()]];
            }
            if (count((new self())->find($crit, [], 1))) {
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
