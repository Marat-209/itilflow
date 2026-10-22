<?php
/**
 * 1.13 · форма маршрута и вкладка заявки:
 * - «Другие маршруты этой формы» — и маршруты других назначений той же формы;
 * - условие создания назначения — без английского «Created if...» ядра;
 * - вкладка заявки без маршрута — «В этой организации маршрутов нет.» только когда их нет;
 * - подпись схемы для экранного диктора — «Схема маршрута».
 */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\FormConfiguration;
use GlpiPlugin\Itilflow\StartReadiness;

// 1. Другие маршруты формы
$marshrut = ['id' => 11, 'forms_forms_id' => 5, 'forms_destinations_id' => 2];
$rows = [11 => ['id' => 11, 'forms_destinations_id' => 2], 12 => ['id' => 12, 'forms_destinations_id' => 1],
    13 => ['id' => 13, 'forms_destinations_id' => 0]];
$ids = array_map(static fn(array $r): int => (int) $r['id'], StartReadiness::siblingRows($marshrut, $rows));
ok($ids === [12, 13], 'маршрут другого назначения той же формы — в «Других маршрутах этой формы»', json_encode($ids));
ok(StartReadiness::siblingRows(['id' => 13, 'forms_destinations_id' => 0], $rows) !== [] && !in_array(13,
    array_map(static fn(array $r): int => (int) $r['id'], StartReadiness::siblingRows(['id' => 13, 'forms_destinations_id' => 0], $rows)), true),
    'сам маршрут в список не попадает');

// 2. Условие создания назначения — по-русски
$editor = new \Glpi\Form\Condition\EditorManager();
$editor->setFormData(new \Glpi\Form\Condition\FormData(['questions' => [[
    'uuid' => 'test-cat', 'name' => 'Категория', 'type' => \Glpi\Form\QuestionType\QuestionTypeNumber::class,
]]]));
$c = new \Glpi\Form\Condition\ConditionData('test-cat', 'question', \Glpi\Form\Condition\ValueOperator::EQUALS->value, 3);
$if = FormConfiguration::creationText(\Glpi\Form\Condition\CreationStrategy::CREATED_IF, [$c], $editor);
$unless = FormConfiguration::creationText(\Glpi\Form\Condition\CreationStrategy::CREATED_UNLESS, [$c], $editor);
ok(str_starts_with($if, 'создаётся, если «Категория»') && !str_contains($if, 'Created'), 'условие создания — «создаётся, если …»', $if);
ok(str_starts_with($unless, 'не создаётся, если «Категория»') && !str_contains($unless, 'Created'), 'обратное условие — «не создаётся, если …»', $unless);

// 3. Вкладка заявки без маршрута
$koren = dirname(__DIR__, 2) . '/templates';
$zagr = new \Twig\Loader\FilesystemLoader([$koren, '/var/www/glpi/templates']);
$tw = new \Twig\Environment($zagr, ['autoescape' => 'html', 'strict_variables' => false]);
// Макросы ядра GLPI (fields_macros) зовут функции и фильтры ядра — здесь заглушки: проверяется только текст ветки.
$tw->registerUndefinedFunctionCallback(static fn(string $n) => new \Twig\TwigFunction($n, static fn() => ''));
$tw->registerUndefinedFilterCallback(static fn(string $n) => new \Twig\TwigFilter($n, static fn($v = '') => is_scalar($v) ? (string) $v : ''));
$item = new class { public array $fields = ['id' => 1]; public function getType(): string { return 'Ticket'; } };
$net = $tw->render('flow_start.html.twig', ['item' => $item, 'processes' => [], 'can_start' => false, 'active' => 0]);
$est = $tw->render('flow_start.html.twig', ['item' => $item, 'processes' => [], 'can_start' => false, 'active' => 3]);
ok(str_contains($net, 'В этой организации маршрутов нет.'), 'маршрутов нет вовсе — так и сказано');
ok(str_contains($est, 'Маршрут на этой заявке не запущен.') && !str_contains($est, 'маршрутов нет'),
    'маршруты есть, но этот человек их не запускает — «Маршрут на этой заявке не запущен»');

// 4. Схема — «маршрут»
$schema = file_get_contents($koren . '/scheme.html.twig');
ok(!str_contains($schema, 'Схема процесса') && str_contains($schema, 'aria-label="Схема маршрута"'), 'подпись схемы — «Схема маршрута»');

itog();
