<?php

/** Настоящие интерфейсы и модели GLPI 11 из vendor; заменён только локальный транзакционный уровень. */
require __DIR__ . '/_nachalo.php';

use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use GlpiPlugin\Itilflow\FormField;

final class FieldScopeFixture
{
    public static int $depth = 1;
    public static bool $active = true;
    public int $commits = 0;
    public int $rollbacks = 0;
    public function __construct(public bool $commitFails = false, public bool $rollbackFails = false) {}
    public function commit(): void { $this->commits++; if ($this->commitFails) { throw new RuntimeException('synthetic commit'); } self::$depth--; }
    public function rollback(): void { $this->rollbacks++; if ($this->rollbackFails) { self::$active = false; self::$depth = 0; throw new RuntimeException('synthetic rollback'); } self::$depth--; }
}

// Не вызываем загрузку модели/пустых полей из БД. Методы и сигнатуры остаются штатными.
$object = static function (string $class, int $id): object {
    $o = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $o->fields = ['id' => $id];
    return $o;
};
$form = $object(Form::class, 7);
$answers = $object(AnswersSet::class, 8);
$destination = $object(FormDestination::class, 9);
$otherDestination = $object(FormDestination::class, 10);
$ticket = $object(Ticket::class, 11);
$ticket->input = ['_add_validation' => [0, 17], FormField::ANSWERS_MARKER => 8];
$otherTicket = $object(Ticket::class, 12);
$created = [9 => [$ticket], 10 => [$otherTicket]];
$events = [];
$report = static function (array $event) use (&$events): void { $events[] = $event; };
$field = new FormField(report: $report);
$config = $field->getDefaultConfig($form);
ok($field instanceof AbstractConfigField && $config instanceof JsonFieldInterface, 'поле и конфигурация реализуют настоящие интерфейсы GLPI');
ok($field->getCategory() === Category::PLANS && $field->getWeight() === 1000, 'поле применяется после согласования в разделе планов');
ok(!$field->supportAutoConfiguration(), 'поле не включает автоматическую настройку редактора');
$rendered = $field->renderConfigForm($form, $destination, $config, '<input name="forged">', []);
ok(!preg_match('/<(input|select|textarea|button)\b/i', $rendered), 'редактор содержит только пояснение, без полей ввода');
ok(!str_contains($rendered, 'forged'), 'имя входа не попадает в пояснение');
$prepared = $field->prepareInput([FormField::getKey() => ['forged' => true], FormField::getAutoConfigKey() => true, 'other' => 42]);
ok($prepared === ['other' => 42], 'присланная настройка и auto удаляются, чужие поля сохранены');
$imported = $field->getConfig($form, [FormField::getKey() => 'malformed imported value']);
ok($imported->jsonSerialize() === [], 'импортированная настройка игнорируется даже при неверном типе');
$class = $field->getConfigClass();
ok($class::jsonDeserialize(['forged' => true])->jsonSerialize() === [], 'прямое чтение JSON также не сохраняет настройку');
$input = ['name' => 'Synthetic', '_validation_targets' => ['untouched'], FormField::ANSWERS_MARKER => 999];
$marked = $field->applyConfiguratedValueToInputUsingAnswers($config, $input, $answers);
ok($marked === ['name' => 'Synthetic', '_validation_targets' => ['untouched'], FormField::ANSWERS_MARKER => 8], 'до создания добавляется только настоящий answerset marker');
ok(!array_key_exists('_plugin_itilflow_destinations_id', $marked), 'до создания не выдумывается назначение');
$invalidAnswers = $object(AnswersSet::class, 0);
ok(!isset($field->applyConfiguratedValueToInputUsingAnswers($config, $input, $invalidAnswers)[FormField::ANSWERS_MARKER]), 'при несохранённом наборе ответов поддельный marker удаляется');
$field->applyConfiguratedValueAfterDestinationCreation($destination, $config, $answers, $created);
ok(end($events)['code'] === 'adapter_not_configured', 'без адаптера нет заявления об успешной квитанции');

$guard = static fn(): array => ['active' => FieldScopeFixture::$active, 'depth' => FieldScopeFixture::$depth];
$scope = new FieldScopeFixture(); $calls = [];
$consumer = static function ($d, $a, $items) use (&$calls): void {
    $calls[] = [(int) $d->getID(), (int) $a->getID(), array_map(static fn($i) => (int) $i->getID(), $items), $items[0]->input ?? []];
};
$field = new FormField($consumer, static function () use ($scope) { FieldScopeFixture::$depth++; return $scope; }, $report, $guard);
$field->applyConfiguratedValueAfterDestinationCreation($destination, $config, $answers, $created);
$field->applyConfiguratedValueAfterDestinationCreation($otherDestination, $config, $answers, $created);
ok($calls[0][0] === 9 && $calls[0][2] === [11] && $calls[1][0] === 10 && $calls[1][2] === [12], 'два назначения получают только свои объекты из точной карты callback');
ok($calls[0][3] === $ticket->input, 'исходный вход заявки доступен адаптеру для provenance-сверки');
ok($scope->commits === 2 && $scope->rollbacks === 0, 'успех закрывает только предоставленный локальный уровень');
ok(end($events)['code'] === 'adapter_completed', 'успех локального адаптера не называется доказанным принятием');

$before = count($calls);
foreach ([[], [9 => []], [9 => [$form]], [9 => [$ticket, $ticket]]] as $badCreated) {
    $field->applyConfiguratedValueAfterDestinationCreation($destination, $config, $answers, $badCreated);
}
ok(count($calls) === $before && $scope->commits === 2, 'нет fallback к другому назначению, неверный или повторный объект не передаётся адаптеру');

foreach (['adapter', 'begin', 'commit', 'rollback'] as $failure) {
    FieldScopeFixture::$depth = 1; FieldScopeFixture::$active = true;
    $brokenScope = new FieldScopeFixture($failure === 'commit', $failure === 'rollback');
    $called = 0;
    $consumer = static function () use (&$called, $failure): void {
        $called++;
        if (in_array($failure, ['adapter', 'rollback'], true)) { throw new LogicException('synthetic secret not for diagnostics'); }
    };
    $factory = static function () use ($failure, $brokenScope) {
        if ($failure === 'begin') { throw new RuntimeException('synthetic begin'); }
        FieldScopeFixture::$depth++; return $brokenScope;
    };
    $protected = new FormField($consumer, $factory, $report, $guard);
    $escaped = false;
    try { $protected->applyConfiguratedValueAfterDestinationCreation($destination, $config, $answers, $created); }
    catch (Throwable) { $escaped = true; }
    $last = end($events);
    ok($escaped === ($failure === 'rollback') && $last['code'] === 'callback_failed', 'ошибка ' . $failure . ': продолжение только после подтверждённого rollback');
    ok($brokenScope->rollbacks === ($failure === 'begin' ? 0 : 1), 'при ошибке ' . $failure . ' rollback своего уровня вызывается не более одного раза');
    ok(!str_contains(json_encode($last), 'secret') && !array_key_exists('outer_alive', $last), 'диагностика ' . $failure . ' не раскрывает исключение и не обещает сохранение outer');
}
(new ReflectionProperty(\GlpiPlugin\Itilflow\TransactionScope::class, 'lost'))->setValue(null, null);
$protected = new FormField(report: static function (): void { throw new RuntimeException('synthetic report failure'); });
$escaped = false;
try { $protected->applyConfiguratedValueAfterDestinationCreation($destination, $config, $answers, $created); }
catch (Throwable) { $escaped = true; }
ok(!$escaped, 'ошибка диагностического канала также не выходит в callback');

foreach (['missing_outer', 'lost_outer', 'unknown_outer', 'lost_after_commit'] as $failure) {
    // Каждый fault-сценарий моделирует отдельный PHP-запрос; product API сброса poison не имеет.
    (new ReflectionProperty(\GlpiPlugin\Itilflow\TransactionScope::class, 'lost'))->setValue(null, null);
    FieldScopeFixture::$depth = 1; FieldScopeFixture::$active = true;
    $called = 0; $reads = 0; $scope = new FieldScopeFixture();
    $stateReader = static function () use ($failure, &$reads): array {
        $reads++;
        if ($failure === 'unknown_outer') { throw new RuntimeException('synthetic unknown with private detail'); }
        if ($failure === 'missing_outer' || ($failure === 'lost_after_commit' && $reads >= 3)) {
            return ['active' => false, 'depth' => 0];
        }
        return ['active' => FieldScopeFixture::$active, 'depth' => FieldScopeFixture::$depth];
    };
    $factory = static function () use ($scope) { FieldScopeFixture::$depth++; return $scope; };
    $consumer = static function () use ($failure, &$called): void {
        $called++;
        if ($failure === 'lost_outer') { FieldScopeFixture::$active = false; FieldScopeFixture::$depth = 0; }
    };
    $guarded = new FormField($consumer, $factory, $report, $stateReader);
    $error = null;
    try { $guarded->applyConfiguratedValueAfterDestinationCreation($destination, $config, $answers, $created); }
    catch (RuntimeException $e) { $error = $e; }
    ok($error instanceof \GlpiPlugin\Itilflow\TransactionLost && $error->getPrevious() === null && $error->reason === 'form_callback_unconfirmed', 'потерянная или неизвестная транзакция ' . $failure . ' останавливает цепочку без сырых подробностей');
    ok(end($events)['continuation_safe'] === false, 'при ' . $failure . ' не заявлено безопасное продолжение');
    if (in_array($failure, ['missing_outer', 'unknown_outer'], true)) {
        ok($called === 0 && $scope->rollbacks === 0, 'при ' . $failure . ' writer и rollback чужой транзакции не вызываются');
    }
}

itog();
