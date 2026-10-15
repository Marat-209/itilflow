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
 * Импорт — шаги импорта форм GLPI: предпросмотр → устранение проблем → импорт.
 * Предпросмотр ничего не пишет. Маршрут с тем же именем по умолчанию пропускается,
 * по выбору — создаётся копией. Маршруты создаются выключенными, существующие не
 * меняются. Импорт — одна транзакция: ошибка любого маршрута отменяет всё.
 */
final class Transfer
{
    public const FORMAT = 'itilflow-routes';
    public const VERSION = 1;
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
        'outcome_contract', 'stages', 'data_requirements'];
    private const ROUTE_FLAGS = ['is_recursive', 'is_active', 'block_resolution', 'public_log', 'helpdesk_tab', 'allow_withdraw'];
    private const STAGE_KEYS = ['id', 'name', 'ranking', 'execution_mode', 'entity_strategy', 'entities_id_target',
        'groups_id', 'users_id', 'itilcategories_id', 'slas_id', 'duration', 'is_optional', 'completion_comment',
        'deadline_minutes', 'approval_percent', 'approver_source', 'ticket_status', 'on_reject',
        'plugin_itilflow_stages_id_reject', 'content', 'department_result_source', 'negative_policy', 'return_targets'];
    private const STAGE_INTS = ['entities_id_target', 'groups_id', 'users_id', 'itilcategories_id', 'slas_id',
        'duration', 'deadline_minutes', 'ticket_status', 'plugin_itilflow_stages_id_reject'];
    /** Ссылки этапа на объекты GLPI: поле => тип. Организация отдела — только «В указанной организации». */
    private const STAGE_REFS = ['entities_id_target' => 'Entity', 'groups_id' => 'Group', 'users_id' => 'User',
        'itilcategories_id' => 'ITILCategory', 'slas_id' => 'SLA'];
    /** Где ссылка видна администратору — подпись поля формы этапа. */
    private const REF_LABELS = ['entities_id' => 'организация маршрута', 'calendars_id' => 'календарь',
        'entities_id_target' => 'организация заявки отдела', 'groups_id' => 'группа', 'users_id' => 'сотрудник',
        'itilcategories_id' => 'категория', 'slas_id' => 'время до решения'];

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
            $routes[] = self::exportRoute($process);
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
        $requirements = [];
        $need = static function (string $type, int $id) use (&$requirements): void {
            $requirements[TransferMapper::key($type, $id)] ??= self::requirement($type, $id);
        };
        $need('Entity', (int) $p['entities_id']);
        if ((int) $p['calendars_id'] > 0) {
            $need('Calendar', (int) $p['calendars_id']);
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
            ];
            foreach (self::STAGE_INTS as $field) {
                $row[$field] = (int) $s[$field];
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
            $stages[] = array_merge(array_flip(self::STAGE_KEYS), $row);
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
        $item = getItemForItemtype($type);
        if ($item instanceof \CommonDBTM) {
            foreach ($DB->request(['FROM' => $item::getTable(), 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
                $req['name'] = (string) $row['name'];
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
            'entities_id_target' => $delegated && $stage['entity_strategy'] === 'fixed',
            'itilcategories_id', 'slas_id' => $delegated && (int) $stage[$field] > 0,
            'groups_id', 'users_id' => (int) $stage[$field] > 0
                && !($stage['execution_mode'] === Stage::MODE_APPROVAL && $stage['approver_source'] !== Stage::APPROVER_FIXED),
            default => (int) $stage[$field] > 0,
        };
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
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new TransferError(sprintf('Версия формата файла — %s, эта версия плагина читает версию %d. Обновите плагин.',
                is_scalar($data['version'] ?? null) ? (string) $data['version'] : 'не указана', self::VERSION));
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
            $route = self::parseRoute($route, $n + 1, $stage_ids);
            if (isset($route_ids[$route['id']])) {
                throw new TransferError(sprintf('Маршрут «%s»: номер %d в файле повторяется.', $route['name'], $route['id']));
            }
            $route_ids[$route['id']] = true;
            $routes[] = $route;
        }
        return ['plugin_version' => $data['plugin_version'], 'glpi_version' => $data['glpi_version'],
            'date' => $data['date'], 'routes' => $routes];
    }

    private static function parseRoute(mixed $route, int $n, array &$stage_ids): array
    {
        $where = 'Маршрут ' . $n;
        if (!is_array($route) || ($route !== [] && array_is_list($route))) {
            throw new TransferError($where . ': ожидается объект.');
        }
        self::keys($route, self::ROUTE_KEYS, $where);
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
            throw new TransferError($where . ': список объектов GLPI (data_requirements) записан неверно.');
        }
        $requirements = [];
        foreach ($route['data_requirements'] as $req) {
            if (!is_array($req) || !is_string($req['itemtype'] ?? null) || !isset(TransferMapper::TYPES[$req['itemtype']])) {
                throw new TransferError($where . ': в списке объектов GLPI неизвестный тип.');
            }
            $tree = TransferMapper::TYPES[$req['itemtype']] === 'completename';
            self::keys($req, $tree ? ['itemtype', 'id', 'name', 'completename'] : ['itemtype', 'id', 'name'], $where . ', объект GLPI');
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
        $statuses = Stage::getStatusChoices((string) $route['itemtype']);
        $stages = [];
        $ranks = [];
        foreach ($route['stages'] as $i => $stage) {
            $stage = self::parseStage($stage, $where . ', этап ' . ($i + 1));
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
                    $problems[] = $label . ' — точка возврата: этапа нет в этом маршруте.';
                    break;
                }
            }
        }
        // У каждой ссылки на объект GLPI — сведения, по которым его искать.
        $refs = [['Entity', $route['entities_id'], 'entities_id', null]];
        if ($route['calendars_id'] > 0) {
            $refs[] = ['Calendar', $route['calendars_id'], 'calendars_id', null];
        }
        foreach ($stages as $stage) {
            foreach (self::STAGE_REFS as $field => $type) {
                if (self::isRef($field, $stage)) {
                    $refs[] = [$type, $stage[$field], $field, $stage];
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
        return $route;
    }

    private static function parseStage(mixed $stage, string $where): array
    {
        if (!is_array($stage) || ($stage !== [] && array_is_list($stage))) {
            throw new TransferError($where . ': ожидается объект.');
        }
        self::keys($stage, self::STAGE_KEYS, $where);
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
                throw new TransferError($where . ': точки возврата записаны неверно.');
            }
            foreach ($targets as $t) {
                if (!is_int($t) || $t < 1 || $t > self::INT_MAX) {
                    throw new TransferError($where . ': точки возврата записаны неверно.');
                }
            }
        }
        return $stage;
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
                'status' => self::READY, 'details' => [], 'resolve' => false, 'copy' => null];
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
            $count = [TransferMapper::MISSING => 0, TransferMapper::SEVERAL => 0, TransferMapper::BY_ID => 0, 'chosen' => 0, 'empty' => 0];
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
            if ($count[TransferMapper::MISSING] || $count[TransferMapper::SEVERAL]) {
                $row['status'] = self::INVALID;
                $row['resolve'] = true;
                if ($count[TransferMapper::MISSING]) {
                    $row['details'][] = sprintf('Не найдено объектов GLPI: %d.', $count[TransferMapper::MISSING]);
                }
                if ($count[TransferMapper::SEVERAL]) {
                    $row['details'][] = sprintf('Найдено несколько, нужно выбрать: %d.', $count[TransferMapper::SEVERAL]);
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
                'original_id' => $req['id'],
                'label'       => ($name !== '' ? $name : 'без имени') . ' (' . $req['id'] . ')',
                'state'       => $r['state'],
                'id'          => $r['id'],
                'candidates'  => $r['candidates'],
                'more'        => count($r['candidates']) >= TransferMapper::CANDIDATES,
                'uses'        => $route['uses'][$key] ?? [],
            ];
        }
        return $out;
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
        [$process, $stages] = self::mapped($route, $map);
        $out = [];
        foreach ($stages as $s) {
            $label = sprintf('Этап %d «%s»', $s['ranking'], $s['name']);
            $runtime = $s['execution_mode'] === Stage::MODE_APPROVAL && $s['approver_source'] !== Stage::APPROVER_FIXED;
            if (!$runtime && $s['groups_id'] === 0 && $s['users_id'] === 0) {
                $out[] = $label . ' — не выбран исполнитель: группа или сотрудник.';
            }
            if ($s['execution_mode'] === Stage::MODE_DELEGATED && $s['entity_strategy'] === 'by_group' && $s['groups_id'] === 0) {
                $out[] = $label . ' — заявка отдела «В организации группы», а группа не выбрана.';
            }
        }
        if ($out || $stages === [] || $process['outcome_contract'] !== DefinitionSnapshot::MODE) {
            return $out;
        }
        $rows = [];
        foreach ($stages as $s) {
            $s['plugin_itilflow_processes_id'] = $route['id'];
            $s['return_targets'] = $s['return_targets'] === null ? null : DefinitionSnapshot::encode($s['return_targets']);
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
        $stages = [];
        foreach ($route['stages'] as $s) {
            foreach (self::STAGE_REFS as $field => $type) {
                $s[$field] = self::isRef($field, $s) ? $ref($type, $s[$field]) : 0;
            }
            // Как Stage::validateInput: у задачи нового порядка пустое «при отказе» — «остановить».
            if ($route['outcome_contract'] === DefinitionSnapshot::MODE && $s['execution_mode'] === Stage::MODE_INLINE
                && !in_array($s['negative_policy'], [Stage::REJECT_BLOCK, Stage::REJECT_ABORT], true)) {
                $s['negative_policy'] = Stage::REJECT_BLOCK;
            }
            $stages[] = $s;
        }
        return [$process, $stages];
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
        $ids = [];
        foreach ($stages as $s) {
            $stage = array_diff_key($s, array_flip(['id', 'return_targets', 'plugin_itilflow_stages_id_reject']));
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
            $set = [];
            if ($s['return_targets']) {
                $set['return_targets'] = DefinitionSnapshot::encode(array_map(static fn(int $t): int => $ids[$t], $s['return_targets']));
            }
            if ($s['plugin_itilflow_stages_id_reject'] > 0) {
                $set['plugin_itilflow_stages_id_reject'] = $ids[$s['plugin_itilflow_stages_id_reject']];
            }
            $errors = count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
            if ($set && !(new Stage())->update(['id' => $ids[$s['id']]] + $set)) {
                throw new TransferError(sprintf('Маршрут «%s», этап %d «%s»: точки возврата не сохранены: %s',
                    $name, $s['ranking'], $s['name'], self::lastError($errors)));
            }
        }
        return $pid;
    }

    /** Последняя ошибка, которую оставили проверки GLPI после отметки, — простым текстом. */
    private static function lastError(int $before): string
    {
        $list = array_slice($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [], $before);
        $text = $list ? trim(html_entity_decode(strip_tags((string) end($list)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        // Общая фраза формы «…Обновите страницу» на шаге импорта сбивает: причина — в журнале.
        if ($text === '' || str_starts_with($text, 'Изменение определения не подтверждено')) {
            return 'ошибка записи в базу — подробности в журнале itilflow.log.';
        }
        return $text;
    }
}
