<?php

namespace GlpiPlugin\Itilflow;

/**
 * Чистая проверка наблюдений из обратного вызова назначения формы (GLPI 11.0.8).
 * Вызывается серверным адаптером FormAdoption; сама не читает БД,
 * не создаёт привязок и не снимает запросы.
 *
 * Доверенная граница — серверный адаптер, НЕ массив из HTTP/JSON клиента.
 * Он собирает callback из объектов назначения и AnswersSet; created_items — только
 * created_objects[destination_id], ticket_input — полный сохранившийся вход Ticket.
 * initial_targets — результат ValidationField до создания, expected_targets — в
 * callback. Оба используют штатный формат _validation_targets. source — настройка
 * маршрута, закреплённая до создания. step — текущая ступень заявки; validations —
 * ВСЕ её запросы, без вычитания своих или чужих; bindings — все привязки этих виз.
 *
 * Наблюдения собираются в одной активной транзакции с current-read блокировками.
 * Обязательные evidence-флаги означают проверенные адаптером факты. Проверяется
 * наблюдаемый вклад назначения и правил во входе, затем весь фактический шаг.
 * Пересечение известных производителей не скрывается дедупликацией. Это не
 * аудит произвольного стороннего кода: незаметную замену идентичной строки
 * вне наблюдаемого входа такой контракт не устанавливает.
 *
 * Receipt ниже — содержимое квитанции происхождения, не новая таблица и не
 * цифровая подпись. Адаптер пишет её и уникальную привязку атомарно в своей точке
 * сохранения внутри отправки; commit точки НЕ означает внешний commit. Повтор
 * читает существующую квитанцию под блокировкой; конфликт не перезаписывает её.
 * Throwable локальной операции обрабатывает адаптер, а потерю внешней транзакции
 * нельзя выдавать за записанную квитанцию неуспеха. Никаких внешних эффектов здесь.
 */
final class FormSource
{
    public const INPUT_KEY = '_plugin_itilflow_answersets_id';
    private const WAITING = 2;

    /** Возвращает решение о допустимости автоматического принятия, но не выполняет его. */
    public static function assess(array $facts): array
    {
        $deny = static fn(string $reason): array => ['status' => 'unknown', 'can_adopt' => false,
            'reason' => $reason, 'validation_ids' => [], 'receipt' => null, 'can_cancel' => false];
        foreach (['transaction_active', 'input_complete', 'current_read_complete'] as $flag) {
            if (($facts['evidence'][$flag] ?? null) !== true) { return $deny('incomplete_' . $flag); }
        }
        $callback = $facts['callback'] ?? null;
        $source = $facts['source'] ?? null;
        $ticket = $facts['ticket'] ?? null;
        $input = $facts['ticket_input'] ?? null;
        $step = $facts['step'] ?? null;
        if (!is_array($callback) || !is_array($source) || !is_array($ticket)
            || !is_array($input) || !is_array($step)) { return $deny('missing_context'); }
        $ids = [];
        foreach (['form_id', 'destination_id', 'answerset_id', 'ticket_id'] as $field) {
            $ids[$field] = self::id($callback[$field] ?? null);
            if ($ids[$field] === null) { return $deny('invalid_callback'); }
        }
        $entity = self::id($callback['entity_id'] ?? null, true);
        if ($entity === null || self::id($callback['answerset_form_id'] ?? null) !== $ids['form_id']
            || self::id($callback['destination_form_id'] ?? null) !== $ids['form_id']
            || self::id($source['form_id'] ?? null) !== $ids['form_id']
            || self::id($source['destination_id'] ?? null) !== $ids['destination_id']
            || self::id($ticket['id'] ?? null) !== $ids['ticket_id']
            || self::id($ticket['entities_id'] ?? null, true) !== $entity
            || self::id($input[self::INPUT_KEY] ?? null) !== $ids['answerset_id']) {
            return $deny('context_mismatch');
        }
        // Одно назначение может создать несколько объектов; каждый билет проверяется отдельно.
        $created = $facts['created_items'] ?? null;
        if (!is_array($created) || !array_is_list($created) || !$created) { return $deny('missing_created_items'); }
        $seen = []; $found = false;
        foreach ($created as $item) {
            if (!is_array($item) || ($item['itemtype'] ?? null) !== 'Ticket'
                || ($id = self::id($item['id'] ?? null)) === null || isset($seen[$id])
                || self::id($item['entities_id'] ?? null, true) === null) { return $deny('invalid_created_items'); }
            $seen[$id] = true;
            if ($id === $ids['ticket_id']) {
                $found = self::id($item['entities_id'], true) === $entity;
            }
        }
        if (!$found) { return $deny('ticket_not_in_destination'); }
        if (array_key_exists('_template_id', $input) && self::id($input['_template_id'], true) !== 0) {
            return $deny('external_validation_input');
        }
        $templates = $facts['templates'] ?? [];
        foreach (['initial_targets' => $facts['initial_targets'] ?? null, 'input_targets' => $input['_validation_targets'] ?? null] as $raw) {
            if (!is_array($raw) || !is_array($facts['expected_targets'] ?? null)
                || self::canonical($raw) !== self::canonical($facts['expected_targets'])) { return $deny('targets_changed'); }
        }
        $expected = self::expandTargets($facts['expected_targets'] ?? null, $templates);
        $initial = self::expandTargets($facts['initial_targets'] ?? null, $templates);
        $inTargets = self::expandTargets($input['_validation_targets'] ?? null, $templates);
        if ($expected === null || !$expected || $initial === null || $inTargets === null) {
            return $deny('unknown_targets');
        }
        if ($expected !== $initial || $expected !== $inTargets) { return $deny('targets_changed'); }
        $contribution = self::ruleTargets($input, $facts['default_validationsteps_id'] ?? null);
        if ($contribution === null) { return $deny('unknown_rule_contribution'); }
        // Не склеиваем совпадение производителей: ядро могло скрыть его дедупликацией.
        $combined = self::targets(array_merge($expected, $contribution));
        if ($combined === null) { return $deny('ambiguous_contribution'); }
        $nativeStep = self::id($source['validationsteps_id'] ?? null);
        $stepId = self::id($step['id'] ?? null);
        $percent = self::id($source['approval_percent'] ?? null, true);
        if ($nativeStep === null || $stepId === null || $percent === null || $percent > 100
            || self::id($step['ticket_id'] ?? null) !== $ids['ticket_id']
            || self::id($step['validationsteps_id'] ?? null) !== $nativeStep
            || self::id($step['approval_percent'] ?? null, true) !== $percent) { return $deny('step_mismatch'); }
        // Сначала сверено ВСЁ назначение; принимаем только выбранную ступень формы.
        $expected = array_values(array_filter($expected,
            static fn(array $target): bool => $target['validationsteps_id'] === $nativeStep));
        if (!$expected) { return $deny('selected_step_missing'); }
        $formExclusive = !array_filter($contribution, static fn(array $target): bool => $target['validationsteps_id'] === $nativeStep);
        $expected = array_values(array_filter($combined,
            static fn(array $target): bool => $target['validationsteps_id'] === $nativeStep));
        if (array_key_exists('_validationsteps_threshold', $input)) {
            $threshold = self::id($input['_validationsteps_threshold'], true);
            $thresholdStep = self::id($input['_validationsteps_id'] ?? ($facts['default_validationsteps_id'] ?? null));
            if ($threshold === null || $threshold > 100 || $thresholdStep === null
                || ($thresholdStep === $nativeStep && $threshold !== $percent)) { return $deny('step_mismatch'); }
        }
        $rows = $facts['validations'] ?? null;
        $bindings = $facts['bindings'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || !is_array($bindings) || !array_is_list($bindings)) {
            return $deny('incomplete_current_read');
        }
        // Никакую привязку не заимствуем молча; повтор выполняется через проверку квитанции.
        if ($bindings !== []) { return $deny('already_bound'); }
        if (count($rows) !== count($expected)) { return $deny('validation_count_mismatch'); }
        $actual = []; $records = []; $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($id = self::id($row['id'] ?? null)) === null || isset($seen[$id])) {
                return $deny('invalid_validation_id');
            }
            $seen[$id] = true;
            if (self::id($row['tickets_id'] ?? null) !== $ids['ticket_id']
                || self::id($row['entities_id'] ?? null, true) !== $entity
                || self::id($row['itils_validationsteps_id'] ?? null) !== $stepId
                || self::id($row['itilvalidationtemplates_id'] ?? null, true) !== 0) {
                return $deny('validation_scope_mismatch');
            }
            if (self::id($row['status'] ?? null) !== self::WAITING) { return $deny('not_waiting'); }
            $one = self::targets([['itemtype_target' => $row['itemtype_target'] ?? null,
                'items_id_target' => $row['items_id_target'] ?? null, 'validationsteps_id' => $nativeStep]]);
            if ($one === null || count($one) !== 1) { return $deny('invalid_validation_target'); }
            $actual[] = $one[0];
            $records[] = ['id' => $id] + $one[0];
        }
        self::sortTargets($actual);
        if ($actual !== $expected) { return $deny('validation_targets_mismatch'); }
        usort($records, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        $receipt = ['version' => 1, 'kind' => 'form_callback', 'source' => $ids + ['entity_id' => $entity],
            'step_id' => $stepId, 'validationsteps_id' => $nativeStep, 'approval_percent' => $percent,
            'validations' => $records];
        $receipt['key'] = hash('sha256', self::encode($receipt['source']));
        $receipt['digest'] = hash('sha256', self::encode($receipt));
        return ['status' => 'verified', 'can_adopt' => true, 'reason' => null,
            'validation_ids' => array_column($records, 'id'), 'receipt' => $receipt, 'can_cancel' => false,
            'form_exclusive' => $formExclusive];
    }

    /**
     * Повтор: сверяем сохранённую квитанцию с НОВЫМИ доверенными наблюдениями.
     * Нельзя подставлять факты из самой квитанции. Ожидающие визы и отсутствие
     * привязок здесь обязательны: повтор уже совершённой команды будет обслуживать
     * движок по собственной атомарной квитанции команды и текущему владельцу.
     */
    public static function replay(array $storedReceipt, array $freshFacts): array
    {
        $current = self::assess($freshFacts);
        if (!$current['can_adopt']) { return $current; }
        if (self::canonical($storedReceipt) !== self::canonical($current['receipt'])) {
            return ['status' => 'unknown', 'can_adopt' => false, 'reason' => 'receipt_conflict',
                'validation_ids' => [], 'receipt' => null, 'can_cancel' => false];
        }
        $current['status'] = 'verified_replay';
        return $current;
    }

    /**
     * Позднее открытие этапа: источник берётся из доверенного хранилища, а не
     * доказывается заново по числу строк. WAITING→ACCEPTED/REFUSED не меняет источник.
     * fresh: evidence(transaction_active,current_read_complete,receipt_from_trusted_store),
     * source (точный tuple квитанции, из закреплённого контекста запуска), step,
     * validations (вся ступень), bindings (пусто до первого принятия).
     * Хеш проверяет целостность, но не удостоверяет автора. Полномочия, текущий
     * этап/попытка, native aggregate и атомарная привязка — обязанности адаптера.
     */
    public static function recheck(array $receipt, array $fresh): array
    {
        $deny = static fn(string $reason): array => ['status' => 'unknown', 'can_adopt' => false,
            'reason' => $reason, 'validation_ids' => [], 'receipt' => null, 'can_cancel' => false];
        foreach (['transaction_active', 'current_read_complete', 'receipt_from_trusted_store'] as $flag) {
            if (($fresh['evidence'][$flag] ?? null) !== true) { return $deny('incomplete_' . $flag); }
        }
        if (!self::keys($receipt, ['version', 'kind', 'source', 'step_id', 'validationsteps_id',
            'approval_percent', 'validations', 'key', 'digest'])
            || $receipt['version'] !== 1 || $receipt['kind'] !== 'form_callback'
            || !is_array($receipt['source']) || !is_array($receipt['validations'])
            || !array_is_list($receipt['validations']) || !$receipt['validations']
            || !is_string($receipt['key']) || !is_string($receipt['digest'])) { return $deny('invalid_receipt'); }
        $source = $receipt['source'];
        if (!self::keys($source, ['form_id', 'destination_id', 'answerset_id', 'ticket_id', 'entity_id'])) {
            return $deny('invalid_receipt');
        }
        foreach ($source as $field => $value) {
            if (!is_int($value) || self::id($value, $field === 'entity_id') === null) { return $deny('invalid_receipt'); }
        }
        if (!is_array($fresh['source'] ?? null) || self::canonical($fresh['source']) !== self::canonical($source)) { return $deny('receipt_context_mismatch'); }
        foreach (['step_id', 'validationsteps_id', 'approval_percent'] as $field) {
            if (!is_int($receipt[$field]) || self::id($receipt[$field], $field === 'approval_percent') === null) { return $deny('invalid_receipt'); }
        }
        if ($receipt['approval_percent'] > 100) { return $deny('invalid_receipt'); }

        $step = $fresh['step'] ?? [];
        if (!is_array($step) || self::id($step['id'] ?? null) !== $receipt['step_id']
            || self::id($step['ticket_id'] ?? null) !== $source['ticket_id']
            || self::id($step['validationsteps_id'] ?? null) !== $receipt['validationsteps_id']
            || self::id($step['approval_percent'] ?? null, true) !== $receipt['approval_percent']) { return $deny('step_mismatch'); }
        if (($fresh['bindings'] ?? null) !== []) { return $deny('already_bound_or_unknown'); }
        $rows = $fresh['validations'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count($receipt['validations'])) {
            return $deny('validation_count_mismatch');
        }
        $expected = []; $targets = []; $lastId = 0;
        foreach ($receipt['validations'] as $record) {
            if (!is_array($record) || !self::keys($record, ['id', 'itemtype_target', 'items_id_target', 'validationsteps_id'])
                || !is_int($record['id']) || $record['id'] <= $lastId
                || !is_int($record['items_id_target']) || !is_int($record['validationsteps_id'])
                || $record['validationsteps_id'] !== $receipt['validationsteps_id']) { return $deny('invalid_receipt'); }
            $lastId = $record['id'];
            $target = $record; unset($target['id']); $targets[] = $target;
            $expected[$record['id']] = $record;
        }
        if (self::targets($targets) === null) { return $deny('invalid_receipt'); }
        $unsigned = $receipt; unset($unsigned['digest']);
        if (!hash_equals(hash('sha256', self::encode($source)), $receipt['key'])
            || !hash_equals(hash('sha256', self::encode($unsigned)), $receipt['digest'])) { return $deny('invalid_receipt_digest'); }
        $statuses = []; $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($id = self::id($row['id'] ?? null)) === null
                || isset($seen[$id]) || !isset($expected[$id])) { return $deny('validation_identity_mismatch'); }
            $seen[$id] = true;
            if (self::id($row['tickets_id'] ?? null) !== $source['ticket_id']
                || self::id($row['entities_id'] ?? null, true) !== $source['entity_id']
                || self::id($row['itils_validationsteps_id'] ?? null) !== $receipt['step_id']
                || self::id($row['itilvalidationtemplates_id'] ?? null, true) !== 0
                || ($row['itemtype_target'] ?? null) !== $expected[$id]['itemtype_target']
                || self::id($row['items_id_target'] ?? null) !== $expected[$id]['items_id_target']) {
                return $deny('validation_scope_mismatch');
            }
            $status = self::id($row['status'] ?? null);
            if (!in_array($status, [2, 3, 4], true)) { return $deny('unknown_validation_status'); }
            $statuses[$id] = $status;
        }
        ksort($statuses);
        return ['status' => 'verified_current', 'can_adopt' => true, 'reason' => null,
            'validation_ids' => array_keys($expected), 'receipt' => $receipt,
            'validation_statuses' => $statuses, 'can_cancel' => false];
    }
    /** Разворачиваем только прочитанные адаптером шаблоны; смешанные типы ядро трактует неоднозначно. */
    private static function expandTargets(mixed $value, mixed $templates): ?array
    {
        if (!is_array($value) || !array_is_list($value) || !is_array($templates)) { return null; }
        $expanded = [];
        foreach ($value as $row) {
            if (!is_array($row)) { return null; }
            if (!array_key_exists('_template_id', $row)) { $expanded[] = $row; continue; }
            if (array_diff(array_keys($row), ['_template_id', 'validationsteps_id'])
                || ($id = self::id($row['_template_id'])) === null || !isset($templates[$id])) { return null; }
            $template = $templates[$id];
            if (!is_array($template) || self::id($template['id'] ?? null) !== $id
                || ($step = self::id($template['validationsteps_id'] ?? null)) === null
                || !is_array($template['targets'] ?? null) || !array_is_list($template['targets']) || !$template['targets']) { return null; }
            $type = null;
            foreach ($template['targets'] as $target) {
                if (!is_array($target) || !self::keys($target, ['itemtype', 'items_id'])
                    || !in_array($target['itemtype'], ['User', 'Group'], true)
                    || ($type !== null && $type !== $target['itemtype'])) { return null; }
                $type = $target['itemtype'];
                $expanded[] = ['itemtype_target' => $type, 'items_id_target' => $target['items_id'], 'validationsteps_id' => $step];
            }
        }
        return self::targets($expanded);
    }

    /** Явные пользователи и group_any из штатного входа правил. Динамику не угадываем по итоговым строкам. */
    private static function ruleTargets(array $input, mixed $defaultStep): ?array
    {
        if (!array_key_exists('_add_validation', $input)) { return []; }
        $value = $input['_add_validation'] ?? null;
        if (self::emptyContribution($value)) { return []; }
        $step = self::id($input['_validationsteps_id'] ?? $defaultStep);
        if ($step === null) { return null; }
        if (!is_array($value)) { $value = [$value]; }
        $out = [];
        foreach ($value as $key => $part) {
            if ($key === 'group_any') {
                if (!is_array($part) || !array_is_list($part) || !$part) { return null; }
                $out[] = ['itemtype_target' => 'Group', 'items_id_target' => $part, 'validationsteps_id' => $step];
            } elseif (is_int($key)) {
                if ($part === 0 || $part === '0') { continue; }
                if (self::id($part) === null) { return null; }
                $out[] = ['itemtype_target' => 'User', 'items_id_target' => $part, 'validationsteps_id' => $step];
            } else { return null; }
        }
        return self::targets($out);
    }

    /** Штатный _validation_targets: атомарный адресат либо список номеров одного типа. */
    private static function targets(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) { return null; }
        $out = []; $seen = [];
        foreach ($value as $row) {
            if (!is_array($row) || array_key_exists('_template_id', $row)
                || array_diff(array_keys($row), ['validatortype', 'itemtype_target', 'items_id_target', 'validationsteps_id'])
                || (array_key_exists('validatortype', $row) && $row['validatortype'] !== ($row['itemtype_target'] ?? null))
                || !in_array($row['itemtype_target'] ?? null, ['User', 'Group'], true)
                || ($step = self::id($row['validationsteps_id'] ?? null)) === null) { return null; }
            $ids = $row['items_id_target'] ?? null;
            $ids = is_array($ids) ? $ids : [$ids];
            if (!array_is_list($ids) || !$ids) { return null; }
            foreach ($ids as $raw) {
                $id = self::id($raw);
                if ($id === null) { return null; }
                $key = $row['itemtype_target'] . ':' . $id . ':' . $step;
                if (isset($seen[$key])) { return null; }
                $seen[$key] = true;
                $out[] = ['itemtype_target' => $row['itemtype_target'], 'items_id_target' => $id, 'validationsteps_id' => $step];
            }
        }
        self::sortTargets($out);
        return $out;
    }

    private static function sortTargets(array &$targets): void
    {
        usort($targets, static fn(array $a, array $b): int => [$a['validationsteps_id'], $a['itemtype_target'], $a['items_id_target']]
            <=> [$b['validationsteps_id'], $b['itemtype_target'], $b['items_id_target']]);
    }

    /** Разрешён только известный пустой вклад ядра; незнакомый формат не равен пустому. */
    private static function emptyContribution(mixed $value): bool
    {
        if ($value === 0 || $value === '0' || $value === []) { return true; }
        if (!is_array($value) || !array_is_list($value)) { return false; }
        foreach ($value as $v) { if ($v !== 0 && $v !== '0') { return false; } }
        return true;
    }

    /** Принимаем числовые строки БД, но не bool, float, знаки, пробелы или переполнение. */
    private static function id(mixed $value, bool $zero = false): ?int
    {
        if (is_string($value)) {
            if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value)) { return null; }
            $number = (int) $value;
            if ((string) $number !== $value) { return null; }
            $value = $number;
        }
        return is_int($value) && $value >= ($zero ? 0 : 1) ? $value : null;
    }

    private static function keys(array $value, array $keys): bool
    {
        $actual = array_keys($value); sort($actual); sort($keys);
        return $actual === $keys;
    }

    /** Объекты JSON независимы от порядка ключей; порядок списков сохраняется. */
    private static function canonical(array $value): array
    {
        foreach ($value as &$part) { if (is_array($part)) { $part = self::canonical($part); } }
        unset($part);
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        return $value;
    }

    private static function encode(array $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
