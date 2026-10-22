<?php

namespace GlpiPlugin\Itilflow;

use Closure;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use Throwable;

/** Настройка намеренно пустая: редактор и импорт формы не управляют маршрутом. */
final class FormFieldConfig implements JsonFieldInterface
{
    public static function jsonDeserialize(array $data): self { return new self(); }
    public function jsonSerialize(): array { return []; }
}

/**
 * Native поле GLPI 11.0.8; production() подключает квитанцию и защищённый scope.
 *
 * Адаптер передаётся только серверным кодом. Он получает точное назначение, набор ответов
 * и объекты этого назначения после создания всех назначений и старта маршрутов.
 * Само поле не восстанавливает назначение по relation и не обещает происхождение виз.
 */
final class FormField extends AbstractConfigField
{
    public const ANSWERS_MARKER = '_plugin_itilflow_answersets_id';

    /**
     * $afterCreated(FormDestination, AnswersSet, array<Ticket>): void — адаптер изолированных проверок
     * полного сбора фактов, сверки, квитанции и принятия. Без него записей нет.
     * $scopeFactory — собственный TransactionScope; замена нужна для чистых тестов.
     * $report(array) — только диагностика, не запись квитанции после ошибки транзакции.
     * $transactionState() — проверенный серверный probe {active: bool, depth: int}, не данные формы.
     */
    public function __construct(
        private readonly ?Closure $afterCreated = null,
        private readonly ?Closure $scopeFactory = null,
        private readonly ?Closure $report = null,
        private readonly ?Closure $transactionState = null,
        private readonly bool $production = false,
    ) {}

    /** Регистрация только сервером, без параметров из настроек формы. */
    public static function production(): self { return new self(production: true); }

    public function getLabel(): string { return 'Маршрут этапов'; }
    public function getConfigClass(): string { return FormFieldConfig::class; }
    public function getWeight(): int { return 1000; }
    public function getCategory(): Category { return Category::PLANS; }
    public function getDefaultConfig(Form $form): FormFieldConfig { return new FormFieldConfig(); }

    /** Импорт обходит prepareInput ядра; даже сохранённая чужая настройка игнорируется. */
    public function getConfig(Form $form, array $config, ?AnswersSet $answers_set = null): JsonFieldInterface
    {
        if ($this->production && $answers_set !== null) { FormFacts::rememberSubmission($form, $answers_set); }
        return $this->getDefaultConfig($form);
    }

    public function prepareInput(array $input): array
    {
        unset($input[static::getKey()], $input[static::getAutoConfigKey()]);
        return $input;
    }

    public function renderConfigForm(Form $form, FormDestination $destination, JsonFieldInterface $config,
        string $input_name, array $display_options): string
    {
        return '<div class="form-text">Маршрут этапов: связь настраивается в маршруте.</div>';
    }

    public function applyConfiguratedValueToInputUsingAnswers(JsonFieldInterface $config, array $input,
        AnswersSet $answers_set): array
    {
        if ($this->production) {
            try { FormAdoption::capture($answers_set, $input); }
            catch (Throwable) { TransactionScope::assertHealthy(); $this->diagnostic('capture_failed', ['answerset_id' => (int) $answers_set->getID()]); }
        }
        // До создания ядро не передаёт назначение. Никакого выдуманного destination id.
        $id = (int) $answers_set->getID();
        unset($input[self::ANSWERS_MARKER]);
        if ($id > 0) { $input[self::ANSWERS_MARKER] = $id; }
        return $input;
    }

    public function applyConfiguratedValueAfterDestinationCreation(FormDestination $destination,
        JsonFieldInterface $config, AnswersSet $answers_set, array $created_objects): void
    {
        if ($this->production) {
            $items = $created_objects[(int) $destination->getID()] ?? null;
            if (!is_array($items) || !$items) { return; }
            $seen = [];
            foreach ($items as $item) {
                if (!$item instanceof \Ticket || (int) $item->getID() <= 0 || isset($seen[$item->getID()])) { return; }
                $seen[$item->getID()] = true;
            }
            // Выбор и запуск маршрута по ответу формы — здесь, после создания
            // всех заявок отправки, с точным назначением, до принятия согласования и фактов формы.
            // Свой уровень: сбой выбора откатывает только его и не мешает следующим шагам.
            (new FormTransactionGuard())->run(
                static function () use ($destination, $answers_set, $items): void {
                    FormStart::afterCreated($destination, $answers_set, array_values($items));
                },
                static function (\Throwable $error) use ($destination, $answers_set, $items): void {
                    FormStart::failed($destination, $answers_set, array_values($items), $error);
                },
            );
            (new FormTransactionGuard())->run(
                static function () use ($destination, $answers_set, $items): void {
                    FormAdoption::afterCreated($destination, $answers_set, array_values($items));
                    FormFacts::afterCreated($destination, $answers_set, array_values($items));
                },
                static function () use ($destination, $answers_set, $items): void {
                    FormAdoption::failed($destination, $answers_set, array_values($items));
                    FormFacts::failed($destination, $answers_set, array_values($items));
                },
            );
            return;
        }
        $scope = null;
        $before = null;
        $phase = 'context';
        $context = ['destination_id' => 0, 'answerset_id' => 0];
        try {
            $context = ['destination_id' => (int) $destination->getID(), 'answerset_id' => (int) $answers_set->getID()];
            $items = $created_objects[$context['destination_id']] ?? null;
            if ($context['destination_id'] <= 0 || $context['answerset_id'] <= 0 || !is_array($items) || !$items) {
                $this->diagnostic('invalid_context', $context);
                return;
            }
            $ids = [];
            foreach ($items as $item) {
                if (!$item instanceof \Ticket || (int) $item->getID() <= 0 || isset($ids[(int) $item->getID()])) {
                    $this->diagnostic('invalid_created_items', $context);
                    return;
                }
                $ids[(int) $item->getID()] = true;
            }
            if ($this->afterCreated === null) {
                $this->diagnostic('adapter_not_configured', $context);
                return;
            }
            $phase = 'guard_before';
            $before = $this->readTransactionState();
            if (!$before['active'] || $before['depth'] < 1) { throw new \RuntimeException('Outer unavailable'); }
            $phase = 'begin';
            $scope = $this->scopeFactory !== null ? ($this->scopeFactory)() : new TransactionScope();
            $phase = 'adapter';
            ($this->afterCreated)($destination, $answers_set, array_values($items));
            $phase = 'guard_before_commit';
            if ($this->readTransactionState() !== ['active' => true, 'depth' => $before['depth'] + 1]) {
                throw new \RuntimeException('Scope unavailable');
            }
            $phase = 'commit';
            $scope->commit();
            $scope = null;
            $phase = 'guard_after_commit';
            if ($this->readTransactionState() !== $before) { throw new \RuntimeException('Outer changed'); }
            // Это окончание локального уровня, не durable commit и не доказательство принятия.
            $this->diagnostic('adapter_completed', $context);
        } catch (Throwable $error) {
            TransactionScope::assertHealthy();
            $rollback = 'not_started';
            if ($scope !== null) {
                try { $scope->rollback(); $rollback = 'returned'; }
                catch (Throwable) { TransactionScope::assertHealthy(); $rollback = 'failed'; }
            }
            // Не пишем квитанцию после сбоя: сервер мог уже откатить внешнюю транзакцию целиком.
            // Даже успешный возврат rollback не подтверждает жизнь внешней транзакции.
            $alive = false;
            try {
                $alive = $before !== null && $before['active'] && $before['depth'] > 0
                    && $this->readTransactionState() === $before;
            } catch (Throwable) { TransactionScope::assertHealthy(); }
            $safe = $alive && $rollback !== 'failed' && $phase !== 'guard_after_commit';
            $this->diagnostic('callback_failed', $context + ['phase' => $phase, 'rollback' => $rollback, 'continuation_safe' => $safe]);
            if (!$safe) {
                // Остановить native цепочку: следующие callbacks не должны писать вне отправки.
                TransactionScope::markLost('form_callback_unconfirmed');
            }
        }
    }

    /**
     * Текущее состояние сервера и native счётчик, а не только наличие нашего savepoint.
     * Встроенный probe подтверждён документацией MariaDB. Для MySQL 8 нужен отдельный
     * проверенный адаптер до подключения: @@in_transaction не заявлен переносимым.
     * Неизвестное состояние останавливает writer; одного native счётчика недостаточно.
     */
    private function readTransactionState(): array
    {
        if ($this->transactionState !== null) {
            $state = ($this->transactionState)();
        } else {
            global $DB;
            if (!$DB instanceof \DBmysql) { throw new \RuntimeException('Unknown transaction adapter'); }
            $depth = (int) (new \ReflectionProperty(\DBmysql::class, 'transaction_level'))->getValue($DB);
            $row = $DB->doQuery('SELECT @@in_transaction AS active')->fetch_assoc();
            if (!is_array($row) || !isset($row['active']) || !in_array((string) $row['active'], ['0', '1'], true)) {
                throw new \RuntimeException('Unknown server transaction state');
            }
            $state = ['active' => (string) $row['active'] === '1', 'depth' => $depth];
        }
        if (!is_array($state) || !is_bool($state['active'] ?? null) || !is_int($state['depth'] ?? null) || $state['depth'] < 0) {
            throw new \RuntimeException('Unknown transaction state');
        }
        return ['active' => $state['active'], 'depth' => $state['depth']];
    }
    /** Ошибка журнала тоже не выходит в native callback; никаких входов формы и текстов SQL. */
    private function diagnostic(string $code, array $context): void
    {
        $diagnostic = ['code' => $code] + $context;
        try {
            if ($this->report !== null) { ($this->report)($diagnostic); }
            else { \Toolbox::logInFile('itilflow', 'FormField ' . json_encode($diagnostic) . "\n", true, false); }
        } catch (Throwable) {
            TransactionScope::assertHealthy();
            // Последний безопасный канал. Он также не должен прервать отправку формы.
            try { error_log('itilflow FormField diagnostic unavailable: ' . $code); } catch (Throwable) {}
        }
    }
}
