<?php

namespace GlpiPlugin\Itilflow;

/**
 * Перенос маршрутов между базами GLPI — файл JSON по образцу штатного экспорта
 * и импорта форм GLPI 11 (Glpi\Form\Export).
 *
 * Файл, формат 1: маршруты с этапами, поля — как в таблицах плагина. Ссылка на объект
 * GLPI — номер исходной базы, а в data_requirements маршрута у каждой ссылки тип, номер,
 * имя и полное имя, по которым объект ищется в другой базе. Рабочих данных (заявки,
 * шаги, попытки, исходы, нарушения) и паролей в файле нет.
 *
 * Формат 2 (1.11) — у этапа ещё списки согласующих: approver_users, approver_groups (номера
 * сотрудников и групп; null — не заполнено, действуют прежние «группа / сотрудник») и
 * approver_group_mode — как отвечает группа. Каждый номер списка — ссылка со сведениями
 * в data_requirements, как группа и сотрудник этапа. Файл формата 1 читается: списки —
 * null, группа — «любой участник». Раздел «Форма» маршрута и шаг согласования формы
 * в файл добавит «Виза из формы» (форма — по uuid, назначение — выбором) в том же формате 2.
 *
 * Формат 3 (1.12): условия, ключи решений и политика неизвестного; вопросы по UUID
 * только внутри сопоставленной формы. Рабочие ответы и ревизии в файл не входят.
 *
 * Импорт — шаги импорта форм GLPI: предпросмотр → устранение проблем → импорт.
 * Предпросмотр ничего не пишет. Маршрут с тем же именем по умолчанию пропускается,
 * по выбору — создаётся копией. Маршруты создаются выключенными, существующие не
 * меняются. Импорт — одна транзакция: ошибка любого маршрута отменяет всё.
 */
final class Transfer
{
    public const FORMAT = 'itilflow-routes';
    /** Формат, который пишет этот выпуск; читаются все с 1 по этот. */
    public const VERSION = 4;
    public const MAX_BYTES = 2097152;

    public const READY   = 'ready';   // «Готов к импорту»
    public const INVALID = 'invalid'; // «Не может быть импортирован»
    public const EXISTS  = 'exists';  // имя занято — пропуск

    private const MAX_ROUTES = 500;
    private const MAX_STAGES = 200;
    private const MAX_REQUIREMENTS = 2000;
    private const TEXT_BYTES = 65535;
    private const INT_MAX = 2147483647;

    private const ROUTE_KEYS = ['id', 'name', 'comment', 'itemtype', 'entities_id', 'is_recursive', 'is_active',
        'enforcement', 'block_resolution', 'public_log', 'helpdesk_tab', 'allow_withdraw', 'calendars_id',
        'outcome_contract', 'stages', 'data_requirements', 'forms_forms_id', 'forms_destinations_id'];
    private const ROUTE_FLAGS = ['is_recursive', 'is_active', 'block_resolution', 'public_log', 'helpdesk_tab', 'allow_withdraw'];
    /** Поля этапа по версиям формата: в каждой — добавленные ею. */
    private const STAGE_KEYS = [
        1 => ['id', 'name', 'ranking', 'execution_mode', 'entity_strategy', 'entities_id_target',
            'groups_id', 'users_id', 'itilcategories_id', 'slas_id', 'duration', 'is_optional', 'completion_comment',
            'deadline_minutes', 'approval_percent', 'approver_source', 'ticket_status', 'on_reject',
            'plugin_itilflow_stages_id_reject', 'content', 'department_result_source', 'negative_policy', 'return_targets', 'form_validationsteps_id'],
        2 => ['approver_users', 'approver_groups', 'approver_group_mode'],
        3 => ['run_if', 'decision_items', 'unknown_policy'],
    ];
    /** Списки согласующих этапа: поле => тип объекта GLPI. */
    private const STAGE_LISTS = ['approver_users' => 'User', 'approver_groups' => 'Group'];
    /** Больше номеров в одном списке файл не несёт: это не настройка для людей. */
    private const MAX_LIST = 200;
    private const STAGE_INTS = ['entities_id_target', 'groups_id', 'users_id', 'itilcategories_id', 'slas_id',
        'duration', 'deadline_minutes', 'ticket_status', 'plugin_itilflow_stages_id_reject', 'form_validationsteps_id'];
    /** Ссылки этапа на объекты GLPI: поле => тип. Организация отдела — только «В указанной организации». */
    private const STAGE_REFS = ['entities_id_target' => 'Entity', 'groups_id' => 'Group', 'users_id' => 'User',
        'itilcategories_id' => 'ITILCategory', 'slas_id' => 'SLA', 'form_validationsteps_id' => 'ValidationStep'];
    /** Где ссылка видна администратору — подпись поля формы этапа. */
    private const REF_LABELS = ['entities_id' => 'организация маршрута', 'calendars_id' => 'календарь',
        'entities_id_target' => 'организация дочерней заявки', 'groups_id' => 'группа', 'users_id' => 'пользователь',
        'itilcategories_id' => 'категория', 'slas_id' => 'время до решения',
        'forms_forms_id' => 'форма', 'forms_destinations_id' => 'назначение формы', 'form_validationsteps_id' => 'этап согласования формы',
        'run_if' => 'условие этапа', 'start_if' => 'Запускать, если', 'approver_users' => 'согласующий пользователь', 'approver_groups' => 'согласующая группа'];

    /** Поля этапа файла версии $version — все, накопленные до неё. */
    private static function stageKeys(int $version): array
    {
        $out = [];
        foreach (self::STAGE_KEYS as $v => $keys) {
            if ($v <= $version) {
                array_push($out, ...$keys);
            }
        }
        return $out;
    }

    /* ================================================================ экспорт */

    /**
     * Файл переноса для маршрутов — как FormSerializer::exportFormsToJson.
     *
     * @param list<Process> $processes
     * @return array{filename:string,json:string}
     */
    public static function export(array $processes): array
    {
        $routes = [];
        foreach ($processes as $process) {
            try {
                $routes[] = self::exportRoute($process);
            } catch (TransferError $e) {
                // Повреждённую настройку не угадываем и не теряем: называем маршрут, который поправить.
                $message = $e->getMessage();
                throw new TransferError(str_starts_with($message, 'Маршрут «') ? $message
                    : sprintf('Маршрут «%s»: %s', (string) ($process->fields['name'] ?? ''), $message));
            }
        }
        $data = [
            'format'         => self::FORMAT,
            'version'        => self::VERSION,
            'plugin_version' => defined('PLUGIN_ITILFLOW_VERSION') ? PLUGIN_ITILFLOW_VERSION : '',
            'glpi_version'   => defined('GLPI_VERSION') ? GLPI_VERSION : '',
            'date'           => date('c'),
            'routes'         => $routes,
        ];
        return [
            'filename' => self::filename($processes),
            'json'     => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }

    /** Имя файла: один маршрут — по его названию, несколько — число, дата и контрольная сумма номеров. */
    private static function filename(array $processes): string
    {
        $date = date('Y-m-d');
        if (count($processes) === 1) {
            $slug = trim(\Toolbox::slugify((string) reset($processes)->fields['name']), '-');
            return 'itilflow-' . ($slug !== '' ? substr($slug, 0, 80) : 'route') . '-' . $date . '.json';
        }
        $ids = array_map(static fn(Process $p): int => (int) $p->getID(), $processes);
        return sprintf('itilflow-routes-%d-%s-%u.json', count($processes), $date, crc32(implode(',', $ids)));
    }

    private static function exportRoute(Process $process): array
    {
        $p = $process->fields;
        // «Запускать, если» проверяется первым, до чтения базы: повреждённая настройка — отказ
        // с названием маршрута, а не файл без запуска по форме.
        $start = self::definitionArray($p['start_if'] ?? null);
        self::startShape($start, sprintf('Маршрут «%s», «Запускать, если»', (string) ($p['name'] ?? '')));
        $requirements = [];
        $need = static function (string $type, int $id) use (&$requirements): void {
            $requirements[TransferMapper::key($type, $id)] ??= self::requirement($type, $id);
        };
        $need('Entity', (int) $p['entities_id']);
        if ((int) $p['calendars_id'] > 0) {
            $need('Calendar', (int) $p['calendars_id']);
        }
        foreach (['forms_forms_id' => TransferMapper::FORM, 'forms_destinations_id' => TransferMapper::DESTINATION] as $field => $type) {
            if ((int) ($p[$field] ?? 0) > 0) { $need($type, (int) $p[$field]); }
        }
        foreach ($start['rule']['clauses'] ?? [] as $clause) {
            foreach (self::conditionRefs($clause) as [$type, $id]) {
                if ($type === TransferMapper::QUESTION) {
                    $form = self::requirement(TransferMapper::FORM, (int) ($p['forms_forms_id'] ?? 0));
                    $q = $clause['question'];
                    $requirements[TransferMapper::key($type, $id)] = ['itemtype' => $type, 'id' => $id, 'name' => $q['label'], 'uuid' => $q['uuid'],
                        'forms_forms_id' => (int) ($p['forms_forms_id'] ?? 0), 'form_uuid' => $form['uuid'], 'descriptor' => $q];
                } else { $need($type, $id); }
            }
        }
        $stages = [];
        $all = $process->getStages();
        $own = array_flip(array_map(static fn(array $s): int => (int) $s['id'], $all));
        foreach ($all as $s) {
            $row = [
                'id'                 => (int) $s['id'],
                'name'               => (string) $s['name'],
                'ranking'            => (int) $s['ranking'],
                'execution_mode'     => (string) $s['execution_mode'],
                'entity_strategy'    => (string) $s['entity_strategy'],
                'is_optional'        => (int) $s['is_optional'],
                'completion_comment' => (string) $s['completion_comment'],
                'approval_percent'   => (int) $s['approval_percent'],
                'approver_source'    => (string) $s['approver_source'],
                'on_reject'          => (string) $s['on_reject'],
                'content'            => $s['content'] === null ? null : (string) $s['content'],
                'department_result_source' => (string) ($s['department_result_source'] ?? ''),
                'negative_policy'    => (string) ($s['negative_policy'] ?? ''),
                // Неверно записанные точки возврата не угадываются: в файле их нет, как «не задано».
                'return_targets'     => ($s['return_targets'] ?? null) === null ? null : DefinitionSnapshot::targets($s['return_targets']),
                'approver_group_mode' => (string) ($s['approver_group_mode'] ?? DefinitionSnapshot::GROUP_ANY),
            ];
            foreach (['run_if', 'decision_items'] as $field) {
                $row[$field] = self::definitionArray($s[$field] ?? null);
            }
            $row['unknown_policy'] = (string) ($s['unknown_policy'] ?? 'run');
            foreach ($row['run_if']['clauses'] ?? [] as $clause) {
                foreach (self::conditionRefs($clause) as [$type, $id]) {
                    if ($type === TransferMapper::QUESTION) {
                        $form = self::requirement(TransferMapper::FORM, (int) ($p['forms_forms_id'] ?? 0));
                        $q = $clause['question'];
                        $requirements[TransferMapper::key($type, $id)] = ['itemtype' => $type, 'id' => $id, 'name' => $q['label'], 'uuid' => $q['uuid'],
                            'forms_forms_id' => (int) ($p['forms_forms_id'] ?? 0), 'form_uuid' => $form['uuid'], 'descriptor' => $q];
                    } else { $need($type, $id); }
                }
            }
            // Списки согласующих: как точки возврата — неверно записанные не угадываются (null).
            foreach (array_keys(self::STAGE_LISTS) as $field) {
                $list = DefinitionSnapshot::idList($s[$field] ?? null);
                $row[$field] = is_array($list) ? $list : null;
            }
            foreach (self::STAGE_INTS as $field) {
                $row[$field] = (int) ($s[$field] ?? 0);
            }
            // Удалённый этап остаётся в «Вернуться на этап» (удаление этапа чистит только точки
            // возврата) — ссылка в никуда в файл не идёт, как и точки возврата на чужие этапы.
            if (!isset($own[$row['plugin_itilflow_stages_id_reject']])) {
                $row['plugin_itilflow_stages_id_reject'] = 0;
            }
            if ($row['return_targets'] !== null) {
                $row['return_targets'] = array_values(array_filter($row['return_targets'], static fn(int $t): bool => isset($own[$t])));
            }
            // Поля, которые этап этого режима не читает (остаются после смены режима), — нулём:
            // в другой базе их номера ничего не значат, сопоставлять их незачем.
            foreach (self::STAGE_REFS as $field => $type) {
                if (self::isRef($field, $row)) {
                    $need($type, $row[$field]);
                } else {
                    $row[$field] = 0;
                }
            }
            // Списки согласующих читает только согласование «Задан в этапе»: у остальных — не заполнены.
            if (self::listsRead($row)) {
                foreach (self::STAGE_LISTS as $field => $type) {
                    foreach ($row[$field] ?? [] as $id) {
                        $need($type, $id);
                    }
                }
            } else {
                $row['approver_users'] = $row['approver_groups'] = null;
                $row['approver_group_mode'] = DefinitionSnapshot::GROUP_ANY;
            }
            $stages[] = array_merge(array_flip(self::stageKeys(self::VERSION)), $row);
        }
        $route = [
            'id'               => (int) $p['id'],
            'name'             => (string) $p['name'],
            'comment'          => $p['comment'] === null ? null : (string) $p['comment'],
            'itemtype'         => (string) $p['itemtype'],
            'entities_id'      => (int) $p['entities_id'],
            'enforcement'      => (string) $p['enforcement'],
            'calendars_id'     => (int) $p['calendars_id'],
            'outcome_contract' => (string) ($p['outcome_contract'] ?? 'legacy'),
            'stages'           => $stages,
            'data_requirements' => array_values($requirements),
            'forms_forms_id' => (int) ($p['forms_forms_id'] ?? 0),
            'forms_destinations_id' => (int) ($p['forms_destinations_id'] ?? 0),
            'start_if' => $start,
        ];
        foreach (self::ROUTE_FLAGS as $flag) {
            $route[$flag] = (int) $p[$flag];
        }
        return array_merge(array_flip(self::ROUTE_KEYS), $route);
    }

    /** Требование: по чему объект найти в другой базе. Объекта нет — имя пустое, найдёт администратор. */
    private static function requirement(string $type, int $id): array
    {
        global $DB;
        $tree = TransferMapper::TYPES[$type] === 'completename';
        $req = ['itemtype' => $type, 'id' => $id, 'name' => ''] + ($tree ? ['completename' => ''] : []);
        if ($type === TransferMapper::FORM) { $req['uuid'] = ''; }
        if ($type === TransferMapper::DESTINATION) { $req += ['forms_forms_id' => 0, 'form_uuid' => '']; }
        $item = getItemForItemtype($type);
        if ($item instanceof \CommonDBTM) {
            foreach ($DB->request(['FROM' => $item::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
                $req['name'] = (string) $row['name'];
                if ($type === TransferMapper::FORM) { $req['uuid'] = (string) ($row['uuid'] ?? ''); }
                if ($type === TransferMapper::DESTINATION) {
                    $req['forms_forms_id'] = (int) $row['forms_forms_id'];
                    $parent = self::requirement(TransferMapper::FORM, $req['forms_forms_id']);
                    $req['form_uuid'] = $parent['uuid'];
                }
                if ($tree) {
                    $req['completename'] = (string) ($row['completename'] ?? $row['name']);
                }
            }
        }
        return $req;
    }

    /**
     * Поле этапа — ссылка на объект, которую этап читает: ненулевой номер; группа и сотрудник —
     * кроме согласования «при прохождении» (форма их очищает); категория и время до решения —
     * у дочерней заявки; организация отдела — у дочерней заявки «В указанной организации»,
     * и нуль (корневая) тоже.
     */
    private static function isRef(string $field, array $stage): bool
    {
        $delegated = $stage['execution_mode'] === Stage::MODE_DELEGATED;
        return match ($field) {
            'form_validationsteps_id' => $stage['execution_mode'] === Stage::MODE_APPROVAL && $stage['approver_source'] === 'form' && (int) $stage[$field] > 0,
            'entities_id_target' => $delegated && $stage['entity_strategy'] === 'fixed',
            'itilcategories_id', 'slas_id' => $delegated && (int) $stage[$field] > 0,
            'groups_id', 'users_id' => (int) $stage[$field] > 0
                && !($stage['execution_mode'] === Stage::MODE_APPROVAL && $stage['approver_source'] !== Stage::APPROVER_FIXED),
            default => (int) $stage[$field] > 0,
        };
    }

    /** Списки согласующих читает только согласование «Задан в этапе»: только там их номера — ссылки. */
    private static function listsRead(array $stage): bool
    {
        return $stage['execution_mode'] === Stage::MODE_APPROVAL && $stage['approver_source'] === Stage::APPROVER_FIXED;
    }

    /**
     * Этап файла — в виде строки таблицы этапов: списки согласующих — JSON-строкой или NULL,
     * как их хранит база и читает DefinitionSnapshot::approvers.
     */
    private static function dbRow(array $stage): array
    {
        foreach (array_merge(array_keys(self::STAGE_LISTS), ['run_if', 'decision_items']) as $field) {
            $stage[$field] = $stage[$field] === null ? null : DefinitionSnapshot::encode($stage[$field]);
        }
        return $stage;
    }

    /* ================================================================ файл */

    /**
     * Текст файла из запроса: загруженный файл первого шага или скрытое поле следующих.
     */
    public static function requestJson(array $post, array $files): string
    {
        if (array_key_exists('json', $post)) {
            if (!is_string($post['json']) || $post['json'] === '') {
                throw new TransferError('Файл не передан. Выберите его ещё раз.');
            }
            return $post['json'];
        }
        $f = $files['import_file'] ?? null;
        if (!is_array($f) || !isset($f['error'], $f['tmp_name'], $f['name'], $f['size']) || is_array($f['error'])) {
            throw new TransferError('Выберите файл.');
        }
        if (in_array((int) $f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new TransferError('Файл больше, чем принимает сервер (upload_max_filesize в настройках PHP).');
        }
        if ((int) $f['size'] > self::MAX_BYTES) {
            throw new TransferError('Файл больше 2 МБ — это не файл маршрутов.');
        }
        if ((int) $f['error'] === UPLOAD_ERR_NO_FILE) {
            throw new TransferError('Выберите файл.');
        }
        if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            throw new TransferError('Файл не загрузился. Выберите его ещё раз.');
        }
        if (!preg_match('/\.json$/iD', (string) $f['name'])) {
            throw new TransferError('Нужен файл .json — его выгружает «Экспорт» в списке маршрутов.');
        }
        $json = file_get_contents((string) $f['tmp_name'], false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($json) || $json === '') {
            throw new TransferError('Файл пустой.');
        }
        return $json;
    }

    /**
     * Строгая проверка файла по схеме формата 1. Любое отличие — TransferError с причиной.
     *
     * @return array{plugin_version:string,glpi_version:string,date:string,routes:list<array>}
     */
    public static function parse(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new TransferError('Файл больше 2 МБ — это не файл маршрутов.');
        }
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3); // метка порядка байтов от «Блокнота»
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new TransferError('Это не файл JSON или он повреждён.');
        }
        if (!is_array($data) || array_is_list($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new TransferError('Это не файл маршрутов: его выгружает «Экспорт» в списке маршрутов этапов.');
        }
        $version = $data['version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > self::VERSION) {
            throw new TransferError(sprintf('Версия формата файла — %s, эта версия плагина читает версии с 1 по %d. Обновите плагин.',
                is_scalar($version) ? (string) $version : 'не указана', self::VERSION));
        }
        self::keys($data, ['format', 'version', 'plugin_version', 'glpi_version', 'date', 'routes'], 'Файл');
        foreach (['plugin_version', 'glpi_version', 'date'] as $field) {
            self::string($data, $field, 'Файл', 64, true);
        }
        if (!is_array($data['routes']) || !array_is_list($data['routes']) || $data['routes'] === []) {
            throw new TransferError('В файле нет маршрутов.');
        }
        if (count($data['routes']) > self::MAX_ROUTES) {
            throw new TransferError(sprintf('В файле больше %d маршрутов.', self::MAX_ROUTES));
        }
        $routes = [];
        $route_ids = [];
        $stage_ids = [];
        foreach ($data['routes'] as $n => $route) {
            $route = self::parseRoute($route, $n + 1, $stage_ids, $version);
            if (isset($route_ids[$route['id']])) {
                throw new TransferError(sprintf('Маршрут «%s»: номер %d в файле повторяется.', $route['name'], $route['id']));
            }
            $route_ids[$route['id']] = true;
            $routes[] = $route;
        }
        return ['plugin_version' => $data['plugin_version'], 'glpi_version' => $data['glpi_version'],
            'date' => $data['date'], 'routes' => $routes];
    }

    private static function parseRoute(mixed $route, int $n, array &$stage_ids, int $version): array
    {
        $where = 'Маршрут ' . $n;
        if (!is_array($route) || ($route !== [] && array_is_list($route))) {
            throw new TransferError($where . ': ожидается объект.');
        }
        $route += ['forms_forms_id' => 0, 'forms_destinations_id' => 0];
        self::keys($route, $version >= 4 ? array_merge(self::ROUTE_KEYS, ['start_if']) : self::ROUTE_KEYS, $where);
        $route += ['start_if' => null];
        self::startShape($route['start_if'], $where . ', «Запускать, если»');
        self::int($route, 'forms_forms_id', $where, 0);
        self::int($route, 'forms_destinations_id', $where, 0);
        self::string($route, 'name', $where, 255);
        $where = 'Маршрут «' . $route['name'] . '»';
        self::int($route, 'id', $where, 1);
        self::text($route, 'comment', $where);
        self::oneOf($route, 'itemtype', $where, array_keys(Process::getSupportedItemtypes()));
        self::int($route, 'entities_id', $where, 0);
        self::int($route, 'calendars_id', $where, 0);
        foreach (self::ROUTE_FLAGS as $flag) {
            self::oneOf($route, $flag, $where, [0, 1]);
        }
        self::oneOf($route, 'enforcement', $where, array_keys(Process::getEnforcementLabels()));
        self::oneOf($route, 'outcome_contract', $where, ['legacy', DefinitionSnapshot::MODE]);

        // Требования: тип, номер, имя; у древовидных справочников ещё полное имя.
        if (!is_array($route['data_requirements']) || !array_is_list($route['data_requirements'])
            || count($route['data_requirements']) > self::MAX_REQUIREMENTS) {
            throw new TransferError($where . ': список объектов GLPI записан неверно.');
        }
        $requirements = [];
        foreach ($route['data_requirements'] as $req) {
            if (!is_array($req) || !is_string($req['itemtype'] ?? null) || !isset(TransferMapper::TYPES[$req['itemtype']])) {
                throw new TransferError($where . ': в списке объектов GLPI неизвестный тип.');
            }
            $tree = TransferMapper::TYPES[$req['itemtype']] === 'completename';
            $keys = $tree ? ['itemtype', 'id', 'name', 'completename'] : ['itemtype', 'id', 'name'];
            if (in_array($req['itemtype'], [TransferMapper::FORM, TransferMapper::QUESTION], true)) { $keys[] = 'uuid'; }
            if ($req['itemtype'] === TransferMapper::QUESTION) { array_push($keys, 'forms_forms_id', 'form_uuid', 'descriptor'); }
            if ($req['itemtype'] === TransferMapper::DESTINATION) {
                array_push($keys, 'forms_forms_id', 'form_uuid');
            }
            self::keys($req, $keys, $where . ', объект GLPI');
            if (in_array($req['itemtype'], [TransferMapper::FORM, TransferMapper::QUESTION], true)) { self::string($req, 'uuid', $where, 255, true); }
            if (in_array($req['itemtype'], [TransferMapper::DESTINATION, TransferMapper::QUESTION], true)) {
                self::int($req, 'forms_forms_id', $where, 1); self::string($req, 'form_uuid', $where, 255, true);
            }
            if ($req['itemtype'] === TransferMapper::QUESTION) {
                self::questionDescriptor($req['descriptor'], $where);
                if ($req['descriptor']['id'] !== $req['id'] || $req['descriptor']['uuid'] !== $req['uuid']) { throw new TransferError($where . ': вопрос не совпадает со своими сведениями.'); }
            }
            self::int($req, 'id', $where . ', объект GLPI', $req['itemtype'] === 'Entity' ? 0 : 1);
            self::string($req, 'name', $where . ', объект GLPI', 255, true);
            if ($tree) {
                self::string($req, 'completename', $where . ', объект GLPI', 4096, true);
            }
            $key = TransferMapper::key($req['itemtype'], $req['id']);
            if (isset($requirements[$key])) {
                throw new TransferError($where . ': объект GLPI ' . $key . ' в списке повторяется.');
            }
            $requirements[$key] = $req;
        }

        if (!is_array($route['stages']) || !array_is_list($route['stages']) || count($route['stages']) > self::MAX_STAGES) {
            throw new TransferError($where . ': список этапов записан неверно.');
        }
        // Ошибки смысла одного маршрута (порядок, статус, ссылки между этапами) — не отказ всему
        // файлу, а «Не может быть импортирован» у этого маршрута: остальные переносятся.
        $problems = [];
        if ($route['start_if'] !== null && ($route['forms_forms_id'] <= 0 || $route['itemtype'] !== 'Ticket'
            || $route['outcome_contract'] !== DefinitionSnapshot::MODE)) {
            $problems[] = 'Для запуска по ответам выберите форму заявки и маршрут заявок нового порядка.';
        }
        $statuses = Stage::getStatusChoices((string) $route['itemtype']);
        $stages = [];
        $ranks = [];
        foreach ($route['stages'] as $i => $stage) {
            $stage = self::parseStage($stage, $where . ', этап ' . ($i + 1), $version);
            $label = sprintf('Этап %d «%s»', $stage['ranking'], $stage['name']);
            if (isset($stage_ids[$stage['id']])) {
                throw new TransferError($where . ', этап «' . $stage['name'] . '»: номер этапа ' . $stage['id'] . ' в файле повторяется.');
            }
            if ($stage['ranking'] <= 0) {
                $problems[] = $label . ' — порядок должен быть больше нуля.';
            } elseif (isset($ranks[$stage['ranking']])) {
                $problems[] = sprintf('Два этапа с порядком %d.', $stage['ranking']);
            }
            if (!isset($statuses[$stage['ticket_status']])) {
                $problems[] = $label . ' — статус, который выставляет этап, не подходит типу маршрута.';
            }
            $stage_ids[$stage['id']] = true;
            $ranks[$stage['ranking']] = true;
            $stages[$stage['id']] = $stage;
        }
        // Внутренние ссылки — только на этапы этого же маршрута.
        foreach ($stages as $stage) {
            $label = sprintf('Этап %d «%s»', $stage['ranking'], $stage['name']);
            if ($stage['plugin_itilflow_stages_id_reject'] > 0 && !isset($stages[$stage['plugin_itilflow_stages_id_reject']])) {
                $problems[] = $label . ' — «Вернуться на этап»: этапа нет в этом маршруте.';
            }
            foreach ($stage['return_targets'] ?? [] as $target) {
                if (!isset($stages[$target])) {
                    $problems[] = $label . ' — этап для возврата отсутствует в этом маршруте.';
                    break;
                }
            }
        }
        foreach ($stages as $stage) {
            $prior = array_keys(array_filter($stages, static fn(array $s): bool => $s['ranking'] < $stage['ranking']));
            array_push($problems, ...Condition::validate($stage['run_if'] ?? [], $prior));
            foreach ($stage['run_if']['clauses'] ?? [] as $c) {
                if ($c['source'] === 'decision' && !in_array($c['key'], array_column($stages[$c['stage_id']]['decision_items'] ?? [], 'key'), true)) {
                    $problems[] = 'Вопрос этапа не найден.';
                }
            }
        }
        // У каждой ссылки на объект GLPI — сведения, по которым его искать.
        $refs = [['Entity', $route['entities_id'], 'entities_id', null]];
        if ($route['calendars_id'] > 0) {
            $refs[] = ['Calendar', $route['calendars_id'], 'calendars_id', null];
        }
        foreach (['forms_forms_id' => TransferMapper::FORM, 'forms_destinations_id' => TransferMapper::DESTINATION] as $field => $type) {
            if ($route[$field] > 0) { $refs[] = [$type, $route[$field], $field, null]; }
        }
        if ($route['forms_destinations_id'] > 0) {
            $dest = $requirements[TransferMapper::key(TransferMapper::DESTINATION, $route['forms_destinations_id'])] ?? [];
            $form = $requirements[TransferMapper::key(TransferMapper::FORM, $route['forms_forms_id'])] ?? [];
            if (($dest['forms_forms_id'] ?? 0) !== $route['forms_forms_id'] || !isset($form['uuid']) || ($dest['form_uuid'] ?? null) !== $form['uuid']) {
                throw new TransferError($where . ': назначение не связано с указанной формой.');
            }
        }
        foreach ($stages as $stage) {
            foreach (self::STAGE_REFS as $field => $type) {
                if (self::isRef($field, $stage)) {
                    $refs[] = [$type, $stage[$field], $field, $stage];
                }
            }
            if (self::listsRead($stage)) {
                foreach (self::STAGE_LISTS as $field => $type) {
                    foreach ($stage[$field] ?? [] as $id) {
                        $refs[] = [$type, $id, $field, $stage];
                    }
                }
            }
        }
        $conditions = [['rule' => $route['start_if']['rule'] ?? null, 'field' => 'start_if', 'stage' => null]];
        foreach ($stages as $stage) { $conditions[] = ['rule' => $stage['run_if'], 'field' => 'run_if', 'stage' => $stage]; }
        foreach ($conditions as $condition) {
            foreach ($condition['rule']['clauses'] ?? [] as $c) {
                foreach (self::conditionRefs($c) as [$type, $id]) {
                    $refs[] = [$type, $id, $condition['field'], $condition['stage']];
                    if ($type === TransferMapper::QUESTION) {
                        $req = $requirements[TransferMapper::key($type, $id)] ?? [];
                        $form = $requirements[TransferMapper::key(TransferMapper::FORM, $route['forms_forms_id'])] ?? [];
                        if (($req['forms_forms_id'] ?? 0) !== $route['forms_forms_id'] || ($req['form_uuid'] ?? null) !== ($form['uuid'] ?? null)
                            || !is_array($req['descriptor'] ?? null)
                            || $req['descriptor']['id'] !== $c['question']['id']
                            || FormFacts::validateMapping($req['descriptor'], $c['question']) !== []) { throw new TransferError($where . ': вопрос условия не связан с формой маршрута.'); }
                    }
                }
            }
        }
        $uses = [];
        foreach ($refs as [$type, $id, $field, $stage]) {
            $key = TransferMapper::key($type, $id);
            if (!isset($requirements[$key])) {
                throw new TransferError(sprintf('%s: нет сведений об объекте GLPI %s — файл неполный.', $where, $key));
            }
            $uses[$key][] = $stage === null ? self::REF_LABELS[$field]
                : sprintf('этап %d «%s» — %s', $stage['ranking'], $stage['name'], self::REF_LABELS[$field]);
        }
        // Требования без ссылок не нужны: сопоставлять их не просим.
        $route['data_requirements'] = array_intersect_key($requirements, $uses);
        $route['uses'] = $uses;
        $route['problems'] = array_values(array_unique($problems));
        uasort($stages, static fn(array $a, array $b): int => $a['ranking'] <=> $b['ranking']);
        $route['stages'] = array_values($stages);
        $route['start_summary'] = self::startSummary($route);
        return $route;
    }

    /** Строгий формат файла; смысл и доступ к сопоставленной форме проверяются перед записью. */
    private static function startShape(mixed $start, string $where): void
    {
        if ($start === null) { return; }
        if (!is_array($start) || array_is_list($start)) { throw new TransferError($where . ': неверная настройка запуска. Выберите её заново.'); }
        $decoded = StartRule::decode($start);
        if (($decoded['mode'] ?? '') === StartRule::MODE_INVALID) { throw new TransferError($where . ': неверная настройка запуска. Выберите её заново.'); }
        if ($decoded['mode'] === StartRule::MODE_ALWAYS) { return; }
        if (!is_array($decoded['rule']['clauses'] ?? null) || count($decoded['rule']['clauses']) < 1
            || count($decoded['rule']['clauses']) > 3) {
            throw new TransferError($where . ': задайте от одного до трёх условий по ответам формы.');
        }
        foreach ($decoded['rule']['clauses'] ?? [] as $clause) {
            if (!is_array($clause) || ($clause['source'] ?? '') !== 'form_answer') {
                throw new TransferError($where . ': доступны только ответы выбранной формы. Поля заявки и ответы этапов для запуска не подходят.');
            }
        }
        self::branchShape(['run_if' => $decoded['rule'], 'decision_items' => null, 'unknown_policy' => 'run'], $where);
    }

    private static function startSummary(array $route): string
    {
        $start = StartRule::decode($route['start_if'] ?? null);
        // Те же слова, что в блоке «Запуск маршрута» формы маршрута (ConditionEditor::summary).
        if ($start === null) { return 'не запускается по форме'; }
        // Имена берём из файла, а не по исходным номерам в чужой базе.
        $name = static function (string $type, int $id) use ($route): string {
            $req = $route['data_requirements'][TransferMapper::key($type, $id)] ?? [];
            foreach (['completename', 'name'] as $field) {
                if (trim((string) ($req[$field] ?? '')) !== '') { return (string) $req[$field]; }
            }
            return 'без имени';
        };
        return StartRule::describe($start, $name)
            . ((int) ($route['forms_destinations_id'] ?? 0) === 0 ? ' · Любое назначение формы, создающее заявку' : '');
    }

    private static function parseStage(mixed $stage, string $where, int $version): array
    {
        if (!is_array($stage) || ($stage !== [] && array_is_list($stage))) {
            throw new TransferError($where . ': ожидается объект.');
        }
        $stage += ['form_validationsteps_id' => 0];
        self::keys($stage, self::stageKeys($version), $where);
        $stage += ['run_if' => null, 'decision_items' => null, 'unknown_policy' => 'run'];
        self::branchShape($stage, $where);
        self::string($stage, 'name', $where, 255);
        $where .= ' «' . $stage['name'] . '»';
        self::int($stage, 'id', $where, 1);
        self::int($stage, 'ranking', $where, -self::INT_MAX);
        foreach (self::STAGE_INTS as $field) {
            self::int($stage, $field, $where, 0);
        }
        self::oneOf($stage, 'execution_mode', $where, array_keys(Stage::getModeLabels()));
        self::oneOf($stage, 'entity_strategy', $where, array_keys(Stage::getEntityStrategyLabels()));
        self::oneOf($stage, 'is_optional', $where, [0, 1]);
        self::oneOf($stage, 'completion_comment', $where, array_keys(Stage::getCompletionCommentLabels()));
        self::int($stage, 'approval_percent', $where, 0, 100);
        self::oneOf($stage, 'approver_source', $where, array_keys(Stage::getApproverSourceLabels()));
        self::oneOf($stage, 'on_reject', $where, array_keys(Stage::getRejectLabels()));
        self::text($stage, 'content', $where);
        self::oneOf($stage, 'department_result_source', $where, ['', 'assignee_action', 'native_validation']);
        self::oneOf($stage, 'negative_policy', $where, ['', Stage::REJECT_BLOCK, Stage::REJECT_ABORT]);
        $targets = $stage['return_targets'];
        if ($targets !== null) {
            if (!is_array($targets) || !array_is_list($targets) || count($targets) > self::MAX_STAGES
                || count(array_unique($targets, SORT_REGULAR)) !== count($targets)) {
                throw new TransferError($where . ': этапы для возврата записаны неверно.');
            }
            foreach ($targets as $t) {
                if (!is_int($t) || $t < 1 || $t > self::INT_MAX) {
                    throw new TransferError($where . ': этапы для возврата записаны неверно.');
                }
            }
        }
        if ($version < 2) {
            // Формат 1: списков нет — прежние «группа / сотрудник» читаются как список из одного.
            return $stage + ['approver_users' => null, 'approver_groups' => null,
                'approver_group_mode' => DefinitionSnapshot::GROUP_ANY];
        }
        foreach (self::STAGE_LISTS as $field => $type) {
            $list = $stage[$field];
            if ($list === null) {
                continue;
            }
            if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_LIST
                || count(array_unique($list, SORT_REGULAR)) !== count($list)) {
                throw new TransferError(sprintf('%s: поле «%s» — список номеров записан неверно.', $where, $field));
            }
            foreach ($list as $id) {
                if (!is_int($id) || $id < 1 || $id > self::INT_MAX) {
                    throw new TransferError(sprintf('%s: поле «%s» — список номеров записан неверно.', $where, $field));
                }
            }
        }
        self::oneOf($stage, 'approver_group_mode', $where, [DefinitionSnapshot::GROUP_ANY, DefinitionSnapshot::GROUP_EACH]);
        return $stage;
    }

    /** Записанное определение не превращаем в пустое при повреждении JSON. */
    private static function definitionArray(mixed $value): ?array
    {
        if ($value === null || $value === '') { return null; }
        if (is_string($value)) {
            try { $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new TransferError('Условия или вопросы этапа записаны неверно.'); }
        }
        if (!is_array($value)) { throw new TransferError('Условия или вопросы этапа записаны неверно.'); }
        return $value;
    }

    private static function questionDescriptor(mixed $q, string $where): void
    {
        if (!is_array($q)) { throw new TransferError($where . ': отсутствует описание вопроса.'); }
        self::keys($q, array_merge(['id','uuid','label','native_type','type','multiple','options','itemtype','supported','reason'],
            array_values(array_intersect(['required','conditional'], array_keys($q)))), $where);
        foreach (['required','conditional'] as $flag) {
            if (array_key_exists($flag, $q) && !is_bool($q[$flag])) { throw new TransferError($where . ': неверный признак вопроса.'); }
        }
        self::int($q, 'id', $where, 1);
        foreach (['uuid','label','native_type','type'] as $field) { self::string($q, $field, $where, 1024, $field === 'label'); }
        if (!is_bool($q['multiple']) || $q['supported'] !== true || $q['reason'] !== null
            || !in_array($q['itemtype'], [null,'User','Group','ITILCategory'], true)
            || !is_array($q['options']) || !array_is_list($q['options']) || count($q['options']) > 2000) {
            throw new TransferError($where . ': неподдерживаемое описание вопроса.');
        }
        $seen = [];
        foreach ($q['options'] as $option) {
            if (!is_array($option)) { throw new TransferError($where . ': неверный вариант ответа.'); }
            self::keys($option, ['key','label'], $where);
            self::string($option, 'key', $where, 1024); self::string($option, 'label', $where, 4096, true);
            if (isset($seen[$option['key']])) { throw new TransferError($where . ': повтор ключа варианта.'); }
            $seen[$option['key']] = true;
        }
    }

    private static function branchShape(array $s, string $where): void
    {
        self::oneOf($s, 'unknown_policy', $where, ['run','ask']);
        $items = $s['decision_items'];
        if (BranchConfiguration::decisionItems($items) === null) { throw new TransferError($where . ': вопросы этапа записаны неверно.'); }
        if ($items !== null) {
            if (!is_array($items) || !array_is_list($items) || count($items) > 4) { throw new TransferError($where . ': вопросы этапа записаны неверно.'); }
            $keys = [];
            foreach ($items as $item) {
                if (!is_array($item)) { throw new TransferError($where . ': вопросы этапа записаны неверно.'); }
                self::keys($item, ['key','label'], $where);
                self::string($item, 'label', $where, 255);
                if (!is_string($item['key']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $item['key']) || isset($keys[$item['key']])) { throw new TransferError($where . ': вопросы этапа записаны неверно.'); }
                $keys[$item['key']] = true;
            }
        }
        $rule = $s['run_if'];
        if ($rule === null) { return; }
        if (!is_array($rule)) { throw new TransferError($where . ': неверное условие.'); }
        self::keys($rule, ['version','join','clauses'], $where);
        if (!is_array($rule['clauses'])) { throw new TransferError($where . ': неверные условия.'); }
        $prior = [];
        foreach ($rule['clauses'] as $c) {
            if (!is_array($c)) { throw new TransferError($where . ': неверное условие.'); }
            $keys = match ($c['source'] ?? '') {
                'decision' => ['stage_id','key'], 'stage_not_needed' => ['stage_id'],
                'form_answer' => ['question_uuid','question'], 'ticket' => ['field'],
                default => throw new TransferError($where . ': неизвестный источник условия.'),
            };
            self::keys($c, array_merge(['source','operator','value'], $keys), $where);
            if (isset($c['stage_id'])) { $prior[] = $c['stage_id']; }
            if ($c['source'] === 'form_answer') {
                $singleErrors = Condition::validate(['version'=>1,'join'=>'all','clauses'=>[$c]], $prior);
                if ($singleErrors) { throw new TransferError($where . ': ' . implode(' ', $singleErrors)); }
                self::questionDescriptor($c['question'], $where);
                if ($c['question']['type'] === 'choice' && is_array($c['value'])
                    && array_diff($c['value'], array_column($c['question']['options'], 'key'))) { throw new TransferError($where . ': неизвестный вариант ответа.'); }
                if ($c['question']['type'] === 'ref' && $c['value'] !== null) {
                    $values = array_is_list($c['value']) ? $c['value'] : [$c['value']];
                    foreach ($values as $value) {
                        if (($value['itemtype'] ?? null) !== $c['question']['itemtype']) { throw new TransferError($where . ': тип ссылки не совпадает с вопросом.'); }
                    }
                }
            }
        }
        $errors = Condition::validate($rule, $prior);
        if ($errors) { throw new TransferError($where . ': ' . implode(' ', $errors)); }
    }

    /** Внешние ссылки условия; внутренний stage_id здесь намеренно отсутствует. */
    private static function conditionRefs(array $c): array
    {
        $refs = [];
        if ($c['source'] === 'form_answer') { $refs[] = [TransferMapper::QUESTION, $c['question']['id']]; }
        if ($c['source'] === 'ticket' && $c['field'] === 'category_id') {
            foreach (is_array($c['value']) ? $c['value'] : [$c['value']] as $id) {
                if ($id > 0) { $refs[] = ['ITILCategory', $id]; }
            }
        }
        elseif (is_array($c['value']) && isset($c['value']['itemtype'])) { $refs[] = [$c['value']['itemtype'], $c['value']['id']]; }
        elseif (($c['question']['type'] ?? '') === 'ref' && is_array($c['value']) && array_is_list($c['value'])) {
            foreach ($c['value'] as $value) { $refs[] = [$value['itemtype'], $value['id']]; }
        }
        return $refs;
    }

    /** Только после создания всех этапов: ни один исходный номер не сохраняется. */
    private static function remapCondition(?array $rule, array $ids): ?array
    {
        if ($rule === null) { return null; }
        foreach ($rule['clauses'] as &$c) {
            if (isset($c['stage_id'])) {
                if (!isset($ids[$c['stage_id']])) { throw new TransferError('Этап условия не сопоставлен.'); }
                $c['stage_id'] = $ids[$c['stage_id']];
            }
        }
        unset($c);
        return $rule;
    }

    /** Ровно эти ключи: лишний или недостающий — файл другого формата. */
    private static function keys(array $a, array $expected, string $where): void
    {
        $missing = array_diff($expected, array_keys($a));
        $extra = array_diff(array_map('strval', array_keys($a)), $expected);
        if ($missing || $extra) {
            throw new TransferError(sprintf('%s: %s.', $where, $missing
                ? 'нет поля «' . reset($missing) . '»' : 'лишнее поле «' . mb_substr((string) reset($extra), 0, 60) . '»'));
        }
    }

    private static function int(array $a, string $k, string $where, int $min, int $max = self::INT_MAX): void
    {
        if (!is_int($a[$k]) || $a[$k] < $min || $a[$k] > $max) {
            throw new TransferError(sprintf('%s: поле «%s» — нужно целое число от %d до %d.', $where, $k, $min, $max));
        }
    }

    private static function string(array $a, string $k, string $where, int $max, bool $empty = false): void
    {
        $v = $a[$k];
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || mb_strlen($v) > $max || (!$empty && trim($v) === '')) {
            throw new TransferError(sprintf('%s: поле «%s» — нужен текст%s до %d знаков.', $where, $k, $empty ? '' : ', не пустой,', $max));
        }
    }

    private static function text(array $a, string $k, string $where): void
    {
        $v = $a[$k];
        if ($v !== null && (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || strlen($v) > self::TEXT_BYTES)) {
            throw new TransferError(sprintf('%s: поле «%s» — нужен текст до 64 КБ или null.', $where, $k));
        }
    }

    private static function oneOf(array $a, string $k, string $where, array $allowed): void
    {
        if (!in_array($a[$k], $allowed, true)) {
            throw new TransferError(sprintf('%s: поле «%s» — недопустимое значение.', $where, $k));
        }
    }

    /** @return list<int> номера из запроса, без повторов */
    public static function requestIds(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? array_slice($value, 0, self::MAX_ROUTES * 2) : [$value] as $v) {
            if ((is_string($v) || is_int($v)) && preg_match('/^[0-9]{1,10}$/D', (string) $v) && (int) $v <= self::INT_MAX) {
                $out[] = (int) $v;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Замены из скрытых полей: тип, номер в файле, номер в этой базе (0 — оставить пустым).
     *
     * @return list<array{0:string,1:int,2:int}>
     */
    public static function requestReplacements(mixed $value): array
    {
        if (is_string($value)) {
            // Прежние замены — одним скрытым полем JSON.
            try {
                $value = strlen($value) <= self::MAX_BYTES ? json_decode($value, true, 4, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException $e) {
                $value = null;
            }
        }
        $out = [];
        foreach (is_array($value) ? array_slice($value, 0, self::MAX_REQUIREMENTS * 2) : [] as $r) {
            if (!is_array($r) || !is_string($r['itemtype'] ?? null) || !isset(TransferMapper::TYPES[$r['itemtype']])) {
                continue;
            }
            $original = self::requestIds($r['original_id'] ?? null);
            $replacement = self::requestIds($r['replacement_id'] ?? null);
            if ($original !== [] && $replacement !== []) {
                $out[] = [$r['itemtype'], $original[0], $replacement[0]];
            }
        }
        return $out;
    }

    /* ================================================================ предпросмотр */

    /**
     * Предпросмотр — как FormSerializer::previewImport: по каждому маршруту файла, что будет.
     * Ничего не пишет.
     *
     * @param list<int> $skipped убранные из списка
     * @param list<int> $copies  «Создать копией» у маршрутов с занятым именем
     * @return list<array{id:int,name:string,new_name:string,status:string,details:list<string>,resolve:bool,copy:?bool}>
     *   copy: null — имя свободно; false — занято, пропуск; true — будет копия
     */
    public static function preview(array $file, TransferMapper $mapper, array $skipped = [], array $copies = []): array
    {
        $rows = [];
        $taken = [];
        foreach ($file['routes'] as $route) {
            if (in_array($route['id'], $skipped, true)) {
                continue;
            }
            $row = ['id' => $route['id'], 'name' => $route['name'], 'new_name' => $route['name'],
                'status' => self::READY, 'details' => [], 'resolve' => false, 'copy' => null, 'choose' => 0,
                'start_summary' => $route['start_summary'] ?? self::startSummary($route),
                'start_questions' => self::startQuestions($route, $mapper)];
            if (self::nameTaken($route['name'], $taken)) {
                if (!in_array($route['id'], $copies, true)) {
                    $row['status'] = self::EXISTS;
                    $row['copy'] = false;
                    $rows[] = $row;
                    continue;
                }
                $row['copy'] = true;
                $row['new_name'] = self::copyName($route['name'], $taken);
                $row['details'][] = sprintf('Будет создан копией: «%s».', $row['new_name']);
            }
            if ($route['problems'] !== []) {
                // Исправляется только в исходной базе: сопоставление тут не поможет.
                $row['status'] = self::INVALID;
                array_push($row['details'], ...$route['problems']);
                $rows[] = $row;
                continue;
            }
            $count = [TransferMapper::MISSING => 0, TransferMapper::SEVERAL => 0, TransferMapper::CHOOSE => 0, TransferMapper::BY_ID => 0,
                'chosen' => 0, 'empty' => 0];
            $map = [];
            foreach ($route['data_requirements'] as $key => $req) {
                $r = $mapper->resolve($req);
                $map[$key] = $r['id'];
                if (isset($count[$r['state']])) {
                    $count[$r['state']]++;
                }
                if ($r['state'] === TransferMapper::REPLACED) {
                    $count[$r['id'] === 0 && $req['itemtype'] !== 'Entity' ? 'empty' : 'chosen']++;
                }
            }
            if ($count[TransferMapper::MISSING] || $count[TransferMapper::SEVERAL] || $count[TransferMapper::CHOOSE]) {
                $row['status'] = self::INVALID;
                $row['resolve'] = true;
                $row['choose'] = self::choiceOnly($count);
                if ($count[TransferMapper::MISSING]) {
                    $row['details'][] = sprintf('Не найдено объектов GLPI: %d.', $count[TransferMapper::MISSING]);
                }
                if ($count[TransferMapper::SEVERAL]) {
                    $row['details'][] = sprintf('Найдено несколько, нужно выбрать: %d.', $count[TransferMapper::SEVERAL]);
                }
                if ($count[TransferMapper::CHOOSE]) {
                    $row['details'][] = sprintf('Назначение формы и этап согласования — выберите из списка: %d.', $count[TransferMapper::CHOOSE]);
                }
            } else {
                $blockers = self::blockers($route, $map);
                if ($blockers) {
                    $row['status'] = self::INVALID;
                    $row['resolve'] = $route['data_requirements'] !== [];
                    array_push($row['details'], ...$blockers);
                }
            }
            if ($count[TransferMapper::BY_ID]) {
                $row['details'][] = sprintf('Найдено по номеру, проверьте: %d.', $count[TransferMapper::BY_ID]);
                $row['resolve'] = true;
            }
            if ($count['chosen']) {
                $row['details'][] = sprintf('Выбрано вручную: %d.', $count['chosen']);
                $row['resolve'] = true;
            }
            if ($count['empty']) {
                $row['details'][] = sprintf('Оставлено пустым: %d.', $count['empty']);
                $row['resolve'] = true;
            }
            if ($row['status'] === self::READY) {
                $taken[self::nameKey($row['new_name'])] = true;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Сколько выбрать вручную, если всё остальное нашлось: тогда в предпросмотре «Нужно выбрать: N»,
     * а не «Не может быть импортирован». Импорт такой маршрут по-прежнему не принимает. 0 — чего-то нет в базе.
     */
    public static function choiceOnly(array $count): int
    {
        if ((int) ($count[TransferMapper::MISSING] ?? 0) > 0) { return 0; }
        return (int) ($count[TransferMapper::SEVERAL] ?? 0) + (int) ($count[TransferMapper::CHOOSE] ?? 0);
    }

    /**
     * Ссылки маршрута, которые не сопоставились сами, — для шага «Устранять проблемы».
     * Как FormSerializer::listIssues, плюс найденные по номеру и выбранные ранее: их можно поменять.
     *
     * @return list<array{itemtype:string,original_id:int,label:string,state:string,id:int,candidates:array<int,string>,more:bool,uses:list<string>}>
     *   more — найденных не меньше TransferMapper::CANDIDATES: выбор из общего списка GLPI
     */
    public static function issues(array $route, TransferMapper $mapper): array
    {
        $out = [];
        foreach ($route['data_requirements'] as $key => $req) {
            $r = $mapper->resolve($req);
            if ($r['state'] === TransferMapper::FOUND) {
                continue;
            }
            $name = (string) ($req[TransferMapper::TYPES[$req['itemtype']]] ?? '');
            $out[] = [
                'itemtype'    => $req['itemtype'],
                // Тип назначения формы GLPI 11.0.8 по-русски не называет («Destination»).
                'type_label'  => $req['itemtype'] === TransferMapper::DESTINATION ? 'Назначение формы' : null,
                'original_id' => $req['id'],
                'label'       => ($name !== '' ? $name : 'без имени') . ' (' . $req['id'] . ')',
                'state'       => $r['state'],
                'id'          => $r['id'],
                'candidates'  => $r['candidates'],
                'mandatory'   => TransferMapper::mandatory($req['itemtype']),
                'explicit'    => in_array($req['itemtype'], [TransferMapper::DESTINATION, TransferMapper::QUESTION, 'ValidationStep'], true),
                'more'        => count($r['candidates']) >= TransferMapper::CANDIDATES,
                'uses'        => $route['uses'][$key] ?? [],
                // Форма ещё не выбрана: её вопросы, назначение и этап согласования появятся после нового предпросмотра.
                'form_pending' => $req['itemtype'] === TransferMapper::FORM && $r['state'] !== TransferMapper::REPLACED,
            ];
        }
        return $out;
    }

    /** Сопоставление всех вопросов запуска, включая найденные автоматически. Только названия. */
    public static function startQuestions(array $route, TransferMapper $mapper): array
    {
        $out = [];
        foreach ($route['start_if']['rule']['clauses'] ?? [] as $clause) {
            $key = TransferMapper::key(TransferMapper::QUESTION, $clause['question']['id']);
            if (isset($out[$key])) { continue; }
            $req = $route['data_requirements'][$key] ?? null;
            if ($req === null) { continue; }
            $resolved = $mapper->resolve($req);
            $label = (string) ($resolved['candidates'][$resolved['id']] ?? '');
            $suffix = ' (' . $resolved['id'] . ')';
            if (str_ends_with($label, $suffix)) { $label = substr($label, 0, -strlen($suffix)); }
            $out[$key] = ['label' => (string) $req['name'], 'mapped_label' => $label, 'state' => $resolved['state']];
        }
        return array_values($out);
    }

    /** Маршрут файла по номеру. */
    public static function route(array $file, int $id): ?array
    {
        foreach ($file['routes'] as $route) {
            if ($route['id'] === $id) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Имя занято — в этой базе (сравнивает база: без регистра, «ё» = «е», без хвостовых пробелов)
     * или маршрутом этого же импорта. Считаются все маршруты базы, и в чужих организациях:
     * импорт правил GLPI ищет маршрут по имени во всей базе.
     */
    private static function nameTaken(string $name, array $taken): bool
    {
        return isset($taken[self::nameKey($name)]) || countElementsInTable(Process::getTable(), ['name' => $name]) > 0;
    }

    /** Ключ имени для повторов внутри файла — близко к тому, как сравнивает база. */
    private static function nameKey(string $name): string
    {
        return mb_strtolower(rtrim(str_replace(['ё', 'Ё'], ['е', 'Е'], $name)));
    }

    /** «Имя (копия)», «Имя (копия 2)» … — первое свободное. */
    private static function copyName(string $name, array $taken): string
    {
        for ($n = 1; $n < 1000; $n++) {
            $suffix = $n === 1 ? ' (копия)' : sprintf(' (копия %d)', $n);
            $candidate = mb_substr($name, 0, 255 - mb_strlen($suffix)) . $suffix;
            if (!self::nameTaken($candidate, $taken)) {
                return $candidate;
            }
        }
        throw new TransferError(sprintf('Маршрут «%s»: свободное имя копии не найдено.', $name));
    }

    /**
     * Что не даст создать маршрут после сопоставления — те же проверки, что при сохранении
     * этапа (Stage::validateInput) и маршрута нового порядка (Readiness::firstSnapshotReason).
     *
     * @return list<string>
     */
    private static function blockers(array $route, array $map): array
    {
        try { [$process, $stages] = self::mapped($route, $map); }
        catch (TransferError $e) { return [$e->getMessage()]; }
        $out = [];
        $start = StartRule::decode($process['start_if'] ?? null);
        if ($start !== null) {
            if ($process['forms_forms_id'] <= 0 || $process['itemtype'] !== 'Ticket'
                || $process['outcome_contract'] !== DefinitionSnapshot::MODE) {
                $out[] = 'Для запуска по ответам сопоставьте форму заявки и выберите маршрут заявок нового порядка.';
            } else {
                $error = FormConfiguration::inputError($process, true);
                if ($error !== null) { $out[] = $error; }
                array_push($out, ...StartRule::validate($start, FormFacts::questionCatalog($process['forms_forms_id'])));
            }
        }
        foreach ($stages as $s) {
            $label = sprintf('Этап %d «%s»', $s['ranking'], $s['name']);
            $durationError = Deadline::durationError((int) $s['deadline_minutes'], (int) $process['calendars_id']);
            if ($durationError !== null) { $out[] = $label . ' — ' . $durationError; }
            // SLA дочерней заявки — по календарю её организации, как при сохранении этапа и у движка.
            if ($s['execution_mode'] === Stage::MODE_DELEGATED && (int) $s['slas_id'] > 0) {
                $slaError = Deadline::slaError((int) $s['slas_id'], Stage::childCalendar($s, $process));
                if ($slaError !== null) { $out[] = $label . ' — ' . $slaError; }
            }
            $runtime = $s['execution_mode'] === Stage::MODE_APPROVAL && $s['approver_source'] !== Stage::APPROVER_FIXED;
            if ($s['execution_mode'] === Stage::MODE_APPROVAL && $s['approver_source'] === 'form'
                && ($process['forms_forms_id'] <= 0 || ($process['forms_destinations_id'] <= 0 && $start === null) || $s['form_validationsteps_id'] <= 0)) {
                $out[] = $label . ' — явно сопоставьте форму, её назначение и этап согласования.';
            }
            // Согласование со списками (формат 2): исполнитель — списки, а не прежние поля.
            $lists = self::listsRead($s) ? DefinitionSnapshot::approvers(self::dbRow($s)) : null;
            $by_lists = $lists !== null && !$lists['legacy'];
            $who = $by_lists ? $lists['users'] !== [] || $lists['groups'] !== [] : $s['groups_id'] !== 0 || $s['users_id'] !== 0;
            if (!$runtime && !$who) {
                $out[] = $label . ($s['execution_mode'] === Stage::MODE_APPROVAL ? ' — ' . Stage::CHOOSE_APPROVERS
                    : ' — не выбран исполнитель: группа или пользователь.');
            }
            if (!$by_lists && Stage::approverConflict($s)) {
                $out[] = $label . ' — согласование «Задан в этапе»: выбраны и группа, и пользователь, оставьте что-то одно.';
            }
            if ($s['execution_mode'] === Stage::MODE_DELEGATED && $s['entity_strategy'] === 'by_group' && $s['groups_id'] === 0) {
                $out[] = $label . ' — дочерняя заявка «В организации группы», а группа не выбрана.';
            }
        }
        if ($out || $stages === [] || $process['outcome_contract'] !== DefinitionSnapshot::MODE) {
            return $out;
        }
        $rows = [];
        foreach ($stages as $s) {
            $s = self::dbRow($s);
            $s['plugin_itilflow_processes_id'] = $route['id'];
            $s['return_targets'] = $s['return_targets'] === null ? null : DefinitionSnapshot::encode($s['return_targets']);
            // Ступень формы уже сопоставлена и явно подтверждена.
            $rows[] = $s;
        }
        $reason = Readiness::firstSnapshotReason($process + ['id' => $route['id'], 'version' => 1], $rows);
        return $reason === null ? [] : [$reason];
    }

    /**
     * Маршрут и этапы с номерами этой базы. Номера этапов и внутренние ссылки — ещё из файла.
     *
     * @param array<string,int> $map «Тип#номер в файле» => номер в этой базе
     * @return array{0:array,1:list<array>}
     */
    private static function mapped(array $route, array $map): array
    {
        $ref = static fn(string $type, int $id): int => $map[TransferMapper::key($type, $id)] ?? 0;
        $process = [
            'name'             => $route['name'],
            'comment'          => $route['comment'],
            'itemtype'         => $route['itemtype'],
            'entities_id'      => $ref('Entity', $route['entities_id']),
            'enforcement'      => $route['enforcement'],
            'calendars_id'     => $route['calendars_id'] > 0 ? $ref('Calendar', $route['calendars_id']) : 0,
            'outcome_contract' => $route['outcome_contract'],
        ];
        foreach (self::ROUTE_FLAGS as $flag) {
            $process[$flag] = $route[$flag];
        }
        $process['is_active'] = 0; // включает администратор после проверки
        $process['forms_forms_id'] = $route['forms_forms_id'] > 0 ? $ref(TransferMapper::FORM, $route['forms_forms_id']) : 0;
        $process['forms_destinations_id'] = $route['forms_destinations_id'] > 0 ? $ref(TransferMapper::DESTINATION, $route['forms_destinations_id']) : 0;
        $start = $route['start_if'] ?? null;
        if (isset($start['rule'])) { $start['rule'] = self::mapRule($start['rule'], $process['forms_forms_id'], $ref); }
        $process['start_if'] = $start === null ? null : DefinitionSnapshot::encode($start);
        $stages = [];
        foreach ($route['stages'] as $s) {
            foreach (self::STAGE_REFS as $field => $type) {
                $s[$field] = self::isRef($field, $s) ? $ref($type, $s[$field]) : 0;
            }
            // Номера списков — этой базы; «оставлено пустым» (0) из списка убирается.
            foreach (self::STAGE_LISTS as $field => $type) {
                $s[$field] = !self::listsRead($s) || $s[$field] === null ? null
                    : array_values(array_unique(array_filter(array_map(static fn(int $id): int => $ref($type, $id), $s[$field]),
                        static fn(int $id): bool => $id > 0)));
            }
            $s['run_if'] = self::mapRule($s['run_if'], $process['forms_forms_id'], $ref);
            // Как Stage::validateInput: у задачи нового порядка пустое «при отказе» — «остановить».
            if ($route['outcome_contract'] === DefinitionSnapshot::MODE && $s['execution_mode'] === Stage::MODE_INLINE
                && !in_array($s['negative_policy'], [Stage::REJECT_BLOCK, Stage::REJECT_ABORT], true)) {
                $s['negative_policy'] = Stage::REJECT_BLOCK;
            }
            $stages[] = $s;
        }
        return [$process, $stages];
    }

    /** Общий перенос внешних ссылок условий запуска и этапов. */
    private static function mapRule(?array $rule, int $formId, \Closure $ref): ?array
    {
        if ($rule === null) { return null; }
        foreach ($rule['clauses'] as &$c) {
            if ($c['source'] === 'form_answer') {
                $id = $ref(TransferMapper::QUESTION, $c['question']['id']);
                $target = null;
                foreach (FormFacts::questionCatalog($formId) as $q) { if ($q['id'] === $id) { $target = $q; break; } }
                if ($target === null) { throw new TransferError('Сопоставленный вопрос формы недоступен. Выберите его заново.'); }
                if (FormFacts::validateMapping($c['question'], $target, [$c['question_uuid'] => $target['uuid']]) !== []) {
                    throw new TransferError('Тип или варианты сопоставленного вопроса изменились. Задайте условие заново.');
                }
                $c['question'] = $target; $c['question_uuid'] = $target['uuid'];
            }
            $c['value'] = self::mapValue($c, $ref);
        }
        unset($c);
        return $rule;
    }

    /**
     * Значение условия с номерами этой базы: категория заявки (число или список «любая из»),
     * пользователь, группа, категория вопроса формы (объект или список «любой из»). Сравнения
     * без значения (null) и значения без ссылок — как есть. Два исходных объекта, сопоставленные
     * с одним, в списке остаются одним: список без повторов, как его сохраняет редактор.
     */
    private static function mapValue(array $c, \Closure $ref): mixed
    {
        $value = $c['value'] ?? null;
        if ($c['source'] === 'ticket' && $c['field'] === 'category_id') {
            $category = static function (int $id) use ($ref): int {
                if ($id === 0) { return 0; }
                $mapped = $ref('ITILCategory', $id);
                if ($mapped <= 0) { throw new TransferError('Категория условия не сопоставлена. Выберите её в списке.'); }
                return $mapped;
            };
            return is_array($value) ? array_values(array_unique(array_map($category, $value))) : $category($value);
        }
        $object = static function (array $v) use ($ref): array {
            $v['id'] = $ref($v['itemtype'], $v['id']);
            if ($v['id'] <= 0) { throw new TransferError('Объект условия не сопоставлен. Выберите его в списке.'); }
            return $v;
        };
        if (is_array($value) && isset($value['itemtype'])) { return $object($value); }
        if (($c['question']['type'] ?? '') === 'ref' && is_array($value) && array_is_list($value)) {
            $out = [];
            foreach ($value as $v) {
                $v = $object($v);
                $out[$v['itemtype'] . '#' . $v['id']] = $v;
            }
            return array_values($out);
        }
        return $value;
    }

    /* ================================================================ импорт */

    /**
     * Импорт — как FormSerializer::importFormsFromJson, но одной транзакцией на все маршруты:
     * создаются те, что предпросмотр называет «Готов к импорту», выключенными. Ошибка любого —
     * откат всего. Сообщения, которые оставили по пути проверки GLPI, на следующую страницу
     * не переносятся: причина — в результате.
     *
     * @return array{ok:bool,error:string,rows:list<array{name:string,status:string,id:int,details:list<string>}>}
     *   status: imported — «Импортировано»; skipped — «Пропущен» (имя занято); not_imported — «Не импортируется»
     */
    public static function import(array $file, TransferMapper $mapper, array $skipped = [], array $copies = []): array
    {
        $preview = self::preview($file, $mapper, $skipped, $copies);
        $created = [];
        $error = '';
        $todo = array_values(array_filter($preview, static fn(array $r): bool => $r['status'] === self::READY));
        if ($todo !== []) {
            $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
            $scope = null;
            try {
                $scope = new TransactionScope();
                foreach ($todo as $row) {
                    $created[$row['id']] = self::create(self::route($file, $row['id']), $row['new_name'], $mapper);
                }
                $scope->commit();
            } catch (\Throwable $e) {
                if ($scope !== null) {
                    try {
                        $scope->rollback();
                    } catch (\Throwable $ignored) {
                    }
                }
                TransactionScope::assertHealthy();
                $created = [];
                if ($e instanceof TransferError) {
                    $error = $e->getMessage();
                } else {
                    $error = 'Ошибка записи в базу — подробности в журнале itilflow.log.';
                    \Toolbox::logInFile('itilflow', sprintf("Импорт маршрутов: %s: %s\n", get_class($e), $e->getMessage()), true, false);
                }
            } finally {
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messages;
            }
        }
        $rows = [];
        foreach ($preview as $row) {
            if (isset($created[$row['id']])) {
                $rows[] = ['name' => $row['new_name'], 'status' => 'imported', 'id' => $created[$row['id']],
                    'details' => ['Выключен — включите после проверки.']];
                continue;
            }
            if ($row['status'] === self::EXISTS) {
                $rows[] = ['name' => $row['name'], 'status' => 'skipped', 'id' => 0, 'details' => ['Маршрут с таким именем уже есть.']];
                continue;
            }
            $details = $error !== '' && $row['status'] === self::READY ? ['Изменения отменены вместе со всем импортом.'] : $row['details'];
            $rows[] = ['name' => $row['name'], 'status' => 'not_imported', 'id' => 0, 'details' => $details];
        }
        return ['ok' => $error === '', 'error' => $error, 'rows' => $rows];
    }

    /** Маршрут и его этапы — штатными add/update плагина, с их проверками. */
    private static function create(array $route, string $name, TransferMapper $mapper): int
    {
        $map = [];
        foreach ($route['data_requirements'] as $key => $req) {
            $r = $mapper->resolve($req);
            if (!in_array($r['state'], [TransferMapper::FOUND, TransferMapper::BY_ID, TransferMapper::REPLACED], true)) {
                throw new TransferError(sprintf('Маршрут «%s»: объект GLPI %s не сопоставлен.', $name, $key));
            }
            $map[$key] = $r['id'];
        }
        [$input, $stages] = self::mapped($route, $map);
        $blockers = self::blockers($route, $map);
        if ($blockers !== []) { throw new TransferError(implode(' ', $blockers)); }
        $input['name'] = $name;
        $probe = $input; // can() дополняет ввод — в add() идёт исходный
        if (!(new Process())->can(-1, CREATE, $probe)) {
            throw new TransferError(sprintf('Маршрут «%s»: нет права создавать маршруты в организации «%s».',
                $name, \Dropdown::getDropdownName('glpi_entities', $input['entities_id'])));
        }
        $errors = count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
        $pid = (int) (new Process())->add($input);
        if ($pid <= 0) {
            throw new TransferError(sprintf('Маршрут «%s» не создан: %s', $name, self::lastError($errors)));
        }
        $savedProcess = new Process();
        if (!$savedProcess->getFromDB($pid)
            || Condition::encode(self::definitionArray($savedProcess->fields['start_if'] ?? null)) !== Condition::encode(self::definitionArray($input['start_if']))) {
            throw new TransferError('Настройка запуска после импорта не совпадает с файлом. Изменения отменены.');
        }
        $ids = [];
        foreach ($stages as $s) {
            $stage = array_diff_key(self::dbRow($s), array_flip(['id', 'return_targets', 'plugin_itilflow_stages_id_reject']));
            $stage['run_if'] = null;
            $stage['decision_items'] = null;
            $stage['unknown_policy'] = 'run';
            $stage['plugin_itilflow_processes_id'] = $pid;
            $stage['plugin_itilflow_stages_id_reject'] = 0;
            if ($s['return_targets'] !== null) {
                $stage['return_targets'] = '[]'; // точки возврата — вторым проходом, когда все этапы есть
            }
            $errors = count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
            $sid = (int) (new Stage())->add($stage);
            if ($sid <= 0) {
                throw new TransferError(sprintf('Маршрут «%s», этап %d «%s» не создан: %s', $name, $s['ranking'], $s['name'], self::lastError($errors)));
            }
            $ids[$s['id']] = $sid;
        }
        foreach ($stages as $s) {
            $set = ['decision_items' => $s['decision_items'] === null ? null : DefinitionSnapshot::encode($s['decision_items']),
                'unknown_policy' => $s['unknown_policy']];
            $rule = self::remapCondition($s['run_if'], $ids);
            $set['run_if'] = $rule === null ? null : DefinitionSnapshot::encode($rule);
            if ($s['return_targets']) {
                $set['return_targets'] = DefinitionSnapshot::encode(array_map(static fn(int $t): int => $ids[$t], $s['return_targets']));
            }
            if ($s['plugin_itilflow_stages_id_reject'] > 0) {
                $set['plugin_itilflow_stages_id_reject'] = $ids[$s['plugin_itilflow_stages_id_reject']];
            }
            $errors = count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
            if ($set && !(new Stage())->update(['id' => $ids[$s['id']]] + $set)) {
                throw new TransferError(sprintf('Маршрут «%s», этап %d «%s»: ссылки и условия не сохранены: %s',
                    $name, $s['ranking'], $s['name'], self::lastError($errors)));
            }
        }
        // Успешный update не доказывает сохранение DTO: проверяем прочитанное определение.
        foreach ($stages as $s) {
            $savedStage = new Stage();
            if (!$savedStage->getFromDB($ids[$s['id']])
                || Condition::encode(BranchConfiguration::decode($savedStage->fields['run_if'] ?? null)) !== Condition::encode(self::remapCondition($s['run_if'], $ids) ?? [])
                || Condition::encode(BranchConfiguration::decisionItems($savedStage->fields['decision_items'] ?? null)) !== Condition::encode($s['decision_items'] ?? [])
                || ($savedStage->fields['unknown_policy'] ?? '') !== $s['unknown_policy']) {
                throw new TransferError('Определение условий после импорта не совпадает с файлом.');
            }
        }
        if ($input['outcome_contract'] === DefinitionSnapshot::MODE) {
            $saved = new Process();
            if (!$saved->getFromDB($pid)) { throw new TransferError('Импортированный маршрут не прочитан.'); }
            $reason = Readiness::firstSnapshotReason($saved->fields, $saved->getStages());
            if ($reason !== null) { throw new TransferError($reason); }
        }
        return $pid;
    }

    /** Последняя ошибка, которую оставили проверки GLPI после отметки, — простым текстом. */
    private static function lastError(int $before): string
    {
        $list = array_slice($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [], $before);
        $text = $list ? trim(html_entity_decode(strip_tags((string) end($list)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        // Общая фраза формы «…Обновите страницу» на шаге импорта сбивает: причина — в журнале.
        if ($text === '' || str_contains($text, 'Обновите страницу')) {
            return 'ошибка записи в базу — подробности в журнале itilflow.log.';
        }
        return $text;
    }
}
