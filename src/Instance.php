<?php

namespace GlpiPlugin\Itilflow;

use CommonDBTM;
use CommonGLPI;
use CommonITILObject;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** Экземпляр маршрута, привязанный к конкретной заявке. */
class Instance extends CommonDBTM
{
    use RuntimeRead;

    public static $rightname = 'ticket';

    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const ABORTED = 'aborted';
    public const HALTED  = 'halted';

    // Строки исполнения изменяются только командами Engine, не generic CRUD.
    // Native add/update внутри движка не вызывают эти permission callbacks.
    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }

    public function canViewItem(): bool
    {
        return Authorizer::instanceError($this, 'read') === null;
    }

    public static function getTypeName($nb = 0)
    {
        return 'Маршрут этапов';
    }

    public static function getIcon(): string
    {
        return 'ti ti-route';
    }

    public function isEntityAssign()
    {
        return true;
    }

    public static function getStateLabels(): array
    {
        return [
            self::RUNNING => Terms::IN_PROGRESS,
            self::DONE    => Terms::PASSED,
            self::ABORTED => Terms::ABORTED,
            self::HALTED  => Terms::STOPPED,
        ];
    }

    public static function getForItem(CommonITILObject $item): ?self
    {
        $found = (new self())->find(
            ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
            [],
            1
        );
        if (!count($found)) {
            return null;
        }
        $o = new self();
        $o->getFromDB((int) array_key_first($found));
        return $o;
    }

    /** Шаги экземпляра по порядку. */
    public function getSteps(): array
    {
        $out = [];
        foreach ((new Step())->find(
            ['plugin_itilflow_instances_id' => $this->getID()],
            ['ranking ASC', 'pass_number ASC', 'id ASC']
        ) as $id => $row) {
            $row['id'] = $id;
            $out[] = $row;
        }
        return $out;
    }

    public function getCurrentStep(): ?Step
    {
        // Все этапы запуска по его индексу, текущий — здесь: в команде это чтение с блокировкой, и условие
        // по state могло вести по индексу state — блокировать идущие этапы других заявок (1.13.2).
        foreach ((new Step())->find(['plugin_itilflow_instances_id' => $this->getID()], ['ranking ASC', 'id ASC']) as $id => $row) {
            if ($row['state'] === Step::RUNNING) {
                $s = new Step();
                $s->getFromDB((int) $id);
                return $s;
            }
        }
        return null;
    }

    public function getAttemptSteps(): array
    {
        if (!DefinitionSnapshot::enabled($this)) { return $this->getSteps(); }
        $attempt = Attempt::current($this);
        return $attempt === null ? [] : array_values((new Step())->find([
            'plugin_itilflow_instances_id' => $this->getID(), 'attempt_id' => $attempt->getID(),
        ], ['ranking ASC', 'id ASC']));
    }

    public function getProcess(): ?Process
    {
        if (DefinitionSnapshot::enabled($this)) { return DefinitionSnapshot::process($this); }
        $p = new Process();
        return $p->getFromDB((int) $this->fields['plugin_itilflow_processes_id']) ? $p : null;
    }

    public function getItilItem(): ?CommonITILObject
    {
        $c = $this->fields['itemtype'];
        if (!is_a($c, CommonITILObject::class, true)) {
            return null;
        }
        $o = new $c();
        return CurrentRead::load($o, (int) $this->fields['items_id']) ? $o : null;
    }

    // ------------------------------------------------------------ вкладка

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof CommonITILObject) || $item->isNewItem()) {
            return '';
        }
        $inst = self::getForItem($item);
        if ($inst === null) {
            if (StructuredUi::childStep($item) !== null) { return self::createTabEntry('Результат отдела'); }
            return Session::haveRight('plugin_itilflow_process', UPDATE)
                ? self::createTabEntry('Маршрут этапов')
                : '';
        }

        // В интерфейсе самообслуживания вкладку показываем, только если
        // маршрут это разрешает: не всякий процесс стоит раскрывать инициатору.
        // 1.13.3: согласующему, которого ждут вопросы этапа, — и без этого: ответить на них можно только здесь.
        if (Session::getCurrentInterface() !== 'central') {
            $process = $inst->getProcess();
            if ($process === null || (!$process->fields['helpdesk_tab'] && !self::questionsAwaitMe($inst))) {
                return '';
            }
        }
        // Счёт по этапам маршрута: у этапа с несколькими попытками учитывается последняя.
        // «Не нужен по условию» проходить не нужно: такой этап не входит в счёт, и инициатор
        // по числу не узнаёт о строках, которых не видит.
        $latest = [];
        foreach ($inst->getSteps() as $s) {
            $latest[(int) $s['plugin_itilflow_stages_id']] = $s;
        }
        $latest = array_filter($latest, fn($s) => $s['state'] !== Step::NOT_NEEDED);
        $total = count($latest);
        $done = count(array_filter($latest, fn($s) => in_array($s['state'], [Step::DONE, Step::SKIPPED], true)));
        return self::createTabEntry('Маршрут этапов', $total > 0 ? "{$done}/{$total}" : 0);
    }

    /**
     * Текущий этап ждёт ответов на вопросы этапа от вошедшего — адресата запроса на согласование
     * (Decision::canWrite). Повреждённые вопросы — нет: вкладка не падает.
     */
    private static function questionsAwaitMe(self $instance): bool
    {
        if ($instance->fields['state'] !== self::RUNNING || ($step = $instance->getCurrentStep()) === null) {
            return false;
        }
        try {
            return Decision::canWrite($step);
        } catch (\Throwable $e) {
            TransactionScope::keepGoing($e);
            return false;
        }
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CommonITILObject) {
            Ui::showFlowTab($item);
        }
        return true;
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName()];
        $tab[] = [
            'id' => '1', 'table' => self::getTable(), 'field' => 'state',
            'name' => __('Status'), 'datatype' => 'string',
        ];
        $tab[] = [
            'id' => '2', 'table' => self::getTable(), 'field' => 'id',
            'name' => __('ID'), 'datatype' => 'number',
        ];
        return $tab;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([Step::class]);
    }
}
