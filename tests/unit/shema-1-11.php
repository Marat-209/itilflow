<?php

/**
 * 1.11.0, шаги 1–2: схема без базы.
 *
 * 1. Поля 1.11 — ровно те, что в плане («Миграция 1.11»), с умолчаниями прежнего поведения.
 * 2. Миграция — только addField / addKey: в очереди нет UPDATE, изменения и удаления полей;
 *    строки маршрутов и этапов не переписываются, версии не растут.
 * 3. Строгая проверка таблицы привязок (outcomevalidations) знает новую колонку binding_kind
 *    со значением по умолчанию 'created': таблица 1.10.1 без неё — «не подтверждена», с ней —
 *    подтверждена. Отсюда порядок установки: сначала колонка, потом проверка.
 * Колонки подставляются так, как их показывает SHOW COLUMNS MariaDB / MySQL.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\StructuredSchema;

if (!estKlass(StructuredSchema::class)) {
    itog();
}

// 1. Поля 1.11.
$pole = StructuredSchema::added111();
ok(array_keys($pole) === ['processes', 'stages', 'steps', 'instances', 'outcomevalidations'], 'поля 1.11 — в пяти таблицах');
ok(array_keys($pole['processes']) === ['forms_forms_id', 'forms_destinations_id'], 'маршрут: форма и назначение');
ok(array_keys($pole['stages']) === ['form_validationsteps_id', 'approver_users', 'approver_groups', 'approver_group_mode'],
    'этап: шаг формы, списки согласующих, ответ группы');
ok(array_keys($pole['steps']) === ['approval_dropped', 'approver_fallback'], 'шаг: снятые запросы и замена согласующего');
ok(array_keys($pole['instances']) === ['form_source'], 'запуск: квитанция подачи формы');
ok(array_keys($pole['outcomevalidations']) === ['binding_kind'], 'привязка: вид');
$ozhid = [
    'forms_forms_id' => ['INT UNSIGNED', 'NO', '0'], 'forms_destinations_id' => ['INT UNSIGNED', 'NO', '0'],
    'form_validationsteps_id' => ['INT UNSIGNED', 'NO', '0'], 'approver_users' => ['LONGTEXT', 'YES', null],
    'approver_groups' => ['LONGTEXT', 'YES', null], 'approver_group_mode' => ['VARCHAR(8)', 'NO', 'any'],
    'approval_dropped' => ['TEXT', 'YES', null], 'approver_fallback' => ['TEXT', 'YES', null],
    'form_source' => ['LONGTEXT', 'YES', null], 'binding_kind' => ['VARCHAR(16)', 'NO', 'created'],
];
foreach ($pole as $tablica => $polya) {
    foreach ($polya as $imya => $opr) {
        $fakt = StructuredSchema::expectedColumns('`' . $imya . '` ' . $opr)[$imya] ?? null;
        ok($fakt === $ozhid[$imya], $tablica . '.' . $imya . ': ' . implode(' ', array_map(static fn($v) => $v ?? 'NULL', $ozhid[$imya])),
            json_encode($fakt));
    }
}

// 2. Только добавление полей.
$istochnik = static function (string $class, string $method): string {
    $m = new ReflectionMethod($class, $method);
    $lines = file($m->getFileName());
    return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
};
$queue = $istochnik(StructuredSchema::class, 'queue');
ok(preg_match('/doQuery|->update|changeField|dropField|addPostQuery|addPreQuery|UPDATE /i', $queue) === 0,
    'очередь миграции — только addField и addKey, без UPDATE и изменения полей');
ok(substr_count($queue, 'addField') >= 2 && !str_contains($queue, "'update'"), 'addField без заполнения существующих строк');
$hook = file_get_contents(dirname(__DIR__, 2) . '/hook.php');
ok(!str_contains($hook, "'update' =>") && !preg_match('/UPDATE\s+`?glpi_plugin_itilflow/i', $hook),
    'установка не переписывает строки плагина');
ok(strpos($hook, 'executeMigration') < strpos($hook, 'StructuredSchema::verify'), 'строгая проверка — после выполнения миграции');

// 3. Строгая проверка таблицы привязок.
$opr = "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n`date_creation` TIMESTAMP NULL DEFAULT NULL,\n`date_mod` TIMESTAMP NULL DEFAULT NULL,\n"
    . StructuredSchema::definition('outcomevalidations');
$kol = static fn(string $type, string $null, ?string $default, string $extra = ''): array =>
    ['Type' => $type, 'Null' => $null, 'Default' => $default, 'Extra' => $extra];
$tablica1101 = [
    'id' => $kol('int(10) unsigned', 'NO', null, 'auto_increment'),
    'date_creation' => $kol('timestamp', 'YES', null), 'date_mod' => $kol('timestamp', 'YES', null),
    'plugin_itilflow_steps_id' => $kol('int(10) unsigned', 'NO', null), 'attempt_id' => $kol('int(10) unsigned', 'NO', null),
    'validation_itemtype' => $kol('varchar(100)', 'NO', null), 'validations_id' => $kol('int(10) unsigned', 'NO', null),
    'artifact_itemtype' => $kol('varchar(100)', 'NO', null), 'artifact_items_id' => $kol('int(10) unsigned', 'NO', null),
    'entities_id' => $kol('int(10) unsigned', 'NO', null), 'itemtype_target' => $kol('varchar(100)', 'NO', null),
    'items_id_target' => $kol('int(10) unsigned', 'NO', null), 'validation_step_id' => $kol('int(10) unsigned', 'NO', null),
];
ok(StructuredSchema::columnsMismatch($tablica1101, $opr) === 'нет колонки binding_kind',
    'таблица 1.10.1 без новой колонки — проверка не пройдёт: колонку добавляют до проверки',
    (string) StructuredSchema::columnsMismatch($tablica1101, $opr));
$posle = $tablica1101 + ['binding_kind' => $kol('varchar(16)', 'NO', 'created')];
ok(StructuredSchema::columnsMismatch($posle, $opr) === null, 'после addField колонка binding_kind подтверждена');
$plohaya = $tablica1101 + ['binding_kind' => $kol('varchar(16)', 'NO', null)];
ok(StructuredSchema::columnsMismatch($plohaya, $opr) !== null, 'binding_kind без значения по умолчанию — не подтверждена');
$plohaya = $tablica1101 + ['binding_kind' => $kol('varchar(255)', 'NO', 'created')];
ok(StructuredSchema::columnsMismatch($plohaya, $opr) !== null, 'binding_kind другой длины — не подтверждена');
$plohaya = $posle;
$plohaya['id']['Extra'] = '';
ok(StructuredSchema::columnsMismatch($plohaya, $opr) !== null, 'номер без автоувеличения — не подтверждён, как раньше');
$opr_iskhody = "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n" . StructuredSchema::definition('outcomes');
$e = StructuredSchema::expectedColumns($opr_iskhody);
ok(($e['source_itemtype'] ?? null) === ['VARCHAR(100)', 'NO', ''] && ($e['explanation_pending'] ?? null) === ['TINYINT', 'NO', '0']
    && ($e['reason'] ?? null) === ['TEXT', 'YES', null], 'прежние значения по умолчанию (\'\', 0, NULL) читаются как раньше');

itog();
