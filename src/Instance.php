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
    public static $rightname = 'ticket';

    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const ABORTED = 'aborted';
    public const HALTED  = 'halted';

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
            self::RUNNING => 'Выполняется',
            self::DONE    => 'Завершён',
            self::ABORTED => 'Прерван',
            self::HALTED  => 'Остановлен',
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
        $found = (new Step())->find([
            'plugin_itilflow_instances_id' => $this->getID(),
            'state'                        => Step::RUNNING,
        ], ['ranking ASC'], 1);
        if (!count($found)) {
            return null;
        }
        $s = new Step();
        $s->getFromDB((int) array_key_first($found));
        return $s;
    }

    public function getProcess(): ?Process
    {
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
        return $o->getFromDB((int) $this->fields['items_id']) ? $o : null;
    }

    // ------------------------------------------------------------ вкладка

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof CommonITILObject) || $item->isNewItem()) {
            return '';
        }
        $inst = self::getForItem($item);
        if ($inst === null) {
            return Session::haveRight('plugin_itilflow_process', UPDATE)
                ? self::createTabEntry('Маршрут этапов')
                : '';
        }

        // В интерфейсе самообслуживания вкладку показываем, только если
        // маршрут это разрешает: не всякий процесс стоит раскрывать инициатору.
        if (Session::getCurrentInterface() !== 'central') {
            $process = $inst->getProcess();
            if ($process === null || !$process->fields['helpdesk_tab']) {
                return '';
            }
        }
        $done = 0;
        $total = 0;
        foreach ($inst->getSteps() as $s) {
            $total++;
            if (in_array($s['state'], [Step::DONE, Step::SKIPPED], true)) {
                $done++;
            }
        }
        return self::createTabEntry('Маршрут этапов', $total > 0 ? "{$done}/{$total}" : 0);
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
