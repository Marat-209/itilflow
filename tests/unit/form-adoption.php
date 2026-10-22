<?php
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\FormAdoption;
use GlpiPlugin\Itilflow\FormField;
use Glpi\Form\Destination\FormDestinationManager;
use Glpi\Form\Destination\FormDestinationTicket;
$PLUGIN_HOOKS = [];
if (!defined('GLPI_PLUGINS_DIRECTORIES')) { define('GLPI_PLUGINS_DIRECTORIES', [dirname(__DIR__, 3)]); }
$nativeStep = \Ticket::getValidationStepInstance();
ok($nativeStep instanceof \ITIL_ValidationStep && !(new ReflectionClass($nativeStep))->isAbstract(),
    'штатная фабрика Ticket возвращает concrete validation step; abstract base не создаётся');
$manager = FormDestinationManager::getInstance();
$manager->registerPluginCommonITILConfigField(FormDestinationTicket::class, FormField::production());
$answers = (new ReflectionClass(\Glpi\Form\AnswersSet::class))->newInstanceWithoutConstructor(); $answers->fields = ['id' => 9];
$field = FormField::production(); $result = $field->applyConfiguratedValueToInputUsingAnswers($field->getDefaultConfig((new ReflectionClass(\Glpi\Form\Form::class))->newInstanceWithoutConstructor()), ['_validation_targets' => []], $answers);
ok(array_keys($result) === ['_validation_targets', FormField::ANSWERS_MARKER], 'production capture не добавляет destination или доказательства в клиентский вход');
$PLUGIN_HOOKS = ['pre_item_add' => ['itilflow' => ['Ticket' => [\GlpiPlugin\Itilflow\RuleStart::class, 'beforeAdd']]]];
ok(!defined(FormAdoption::class . '::AUDITED') && !method_exists(FormAdoption::class, 'producerScope'),
    'допуск не зависит от списка чужих плагинов и хешей их файлов');
foreach (['validation_count_mismatch', 'ambiguous_contribution', 'unknown_rule_contribution', 'callback_failed'] as $reason) {
    $text = FormAdoption::reasonText($reason);
    ok($text !== '' && !str_contains($text, $reason), 'причина ' . $reason . ' имеет понятный текст без служебного кода');
}
$consumer = ['id' => 4, 'plugin_itilflow_processes_id' => 3, 'approver_source' => 'form'];
ok(FormAdoption::exclusiveStage([$consumer], [], 4, 3), 'единственный свой source stage разрешает дальнейшую проверку очистки');
ok(!FormAdoption::exclusiveStage([$consumer, $consumer + ['name' => 'future']], [], 4, 3), 'будущий второй потребитель запрещает очистку даже без bindings');
ok(!FormAdoption::exclusiveStage([$consumer], [['id' => 8]], 4, 3), 'правило-потребитель запрещает очистку');
ok(!FormAdoption::exclusiveStage([$consumer], [], 4, 9), 'чужой процесс не даёт исключительности');
ok(!FormAdoption::exclusiveStage([], [], 4, 3), 'удалённый источник не даёт исключительности');
$pending = ['id' => 8, 'state' => \GlpiPlugin\Itilflow\Step::PENDING, 'pass_number' => 1, 'attempt_id' => 6,
    'plugin_itilflow_instances_id' => 2, 'artifact_items_id' => 0, 'artifact_itemtype' => '', 'approver_items_ids' => '',
    'approval_expected_count' => null, 'date_start' => null];
$attempt = ['id' => 6, 'plugin_itilflow_instances_id' => 2, 'state' => \GlpiPlugin\Itilflow\Attempt::RUNNING];
ok(FormAdoption::futureEligible($pending, $attempt, 2), 'будущий неоткрытый шаг допускает проверку квитанции без adoption');
foreach (['state' => \GlpiPlugin\Itilflow\Step::RUNNING, 'pass_number' => 2, 'attempt_id' => 5,
    'artifact_items_id' => 9, 'artifact_itemtype' => 'TicketValidation', 'approver_items_ids' => '[11]',
    'approval_expected_count' => 1, 'date_start' => '2026-01-01 10:00:00'] as $key => $value) {
    $changed = $pending; $changed[$key] = $value;
    ok(!FormAdoption::futureEligible($changed, $attempt, 2), 'future cleanup закрыт при изменении ' . $key);
}
$ended = $attempt; $ended['state'] = \GlpiPlugin\Itilflow\Attempt::REFUSED;
ok(!FormAdoption::futureEligible($pending, $ended, 2), 'уже окончательная попытка не выдаёт новое право снятия');
ok(!FormAdoption::futureEligible($pending, $attempt, 3), 'чужой экземпляр не выдаёт право снятия');
$snapshotProcess = ['forms_destinations_id' => 0];
$started = ['version' => 1, 'kind' => 'form', 'destination_id' => 42];
ok(FormAdoption::matchesDestination($snapshotProcess, json_encode($started), 42), 'общее назначение принимает фактическое назначение запуска');
ok(!FormAdoption::matchesDestination($snapshotProcess, json_encode($started), 43), 'квитанция другого назначения не подходит общему маршруту после запуска');
ok(FormAdoption::matchesDestination(['forms_destinations_id' => 41], $started, 42), 'закреплённое назначение запуска важнее настройки определения');
ok(!FormAdoption::matchesDestination(['forms_destinations_id' => 41], null, 42), 'старый запуск с явным назначением не принимает чужой callback');
ok(FormAdoption::matchesDestination(['forms_destinations_id' => 41], null, 41), 'старый запуск сохраняет принятие своего назначения');
ok(FormAdoption::matchesDestination($snapshotProcess, null, 42), 'старый общий выбор не теряет совместимость');
ok(!FormAdoption::matchesDestination($snapshotProcess, null, 0), 'отсутствующий номер callback не становится любым назначением');
ok(FormAdoption::reasonText('waiting_for_requests') === 'Ждём: укажите согласующего или засчитайте запрос из заявки', 'заявке без формы — подсказка, что сделать');

// По пути FormAdoption::reasonForStep: этап и экземпляр — подменой, без базы.
$shagFormy = static function (?string $formSource, int $pass = 1): \GlpiPlugin\Itilflow\Step {
    $step = new class extends \GlpiPlugin\Itilflow\Step {
        public ?\GlpiPlugin\Itilflow\Instance $ekz = null;
        public ?\GlpiPlugin\Itilflow\Stage $etap = null;
        public function awaitsFormRequests(): bool { return true; }
        public function getInstance(): ?\GlpiPlugin\Itilflow\Instance { return $this->ekz; }
        public function getStage(): ?\GlpiPlugin\Itilflow\Stage { return $this->etap; }
    };
    $step->ekz = new \GlpiPlugin\Itilflow\Instance(); $step->ekz->fields = ['id' => 3, 'form_source' => $formSource];
    $step->etap = new \GlpiPlugin\Itilflow\Stage(); $step->etap->fields = ['approver_source' => 'form', 'form_validationsteps_id' => 7];
    $step->fields = ['pass_number' => $pass];
    return $step;
};
ok(FormAdoption::reasonForStep($shagFormy(null)) === 'Ждём: укажите согласующего или засчитайте запрос из заявки',
    'у заявки не с формы этап согласования из формы пишет «Ждём: укажите согласующего…»');
ok(FormAdoption::reasonForStep($shagFormy('{"version":1,"source":{},"steps":{}}')) === 'Согласование из формы не найдено — засчитайте вручную.',
    'заявка с формы без записи этого этапа согласования — «Согласование из формы не найдено»');
ok(FormAdoption::reasonForStep($shagFormy('{"version":1,"source":{},"steps":{"7":{"status":"unknown","reason":"callback_failed","receipt":null}}}'))
    === 'Не удалось связать согласование с формой. Засчитайте вручную.', 'сохранённая причина этапа — словами словаря');
ok(str_starts_with((string) FormAdoption::reasonForStep($shagFormy(null, 2)), 'Это повтор этапа'), 'повтор этапа — свой текст');
itog();
