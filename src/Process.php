<?php

namespace GlpiPlugin\Itilflow;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/** Маршрут: определение последовательности этапов. */
class Process extends CommonDBTM
{
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
            self::ENFORCE_AUDIT  => 'Журнал — нарушения только записываются',
            self::ENFORCE_WARN   => 'Предупреждение — действие разрешено, но фиксируется',
            self::ENFORCE_STRICT => 'Запрет — действие вне очереди отменяется',
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
        TemplateRenderer::getInstance()->display('@itilflow/process.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'enforcements' => self::getEnforcementLabels(),
            'itemtypes'    => self::getSupportedItemtypes(),
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
