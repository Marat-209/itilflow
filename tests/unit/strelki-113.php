<?php
/**
 * 1.13 · стрелки ↑ ↓ списка этапов (Stage::move). Перестановка — два сохранения этапов; между
 * ними у пары на миг один номер. Раньше первое сохранение поднимало версию маршрута, проверка
 * полноты видела «Этапы 20 и 20 — одинаковый порядок» и откатывала перестановку: стрелки не
 * работали никогда. Теперь версию и полноту проверяет второе сохранение — по итоговому порядку.
 */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Stage;

// 1. Отсрочка — только у первого этапа пары и только внутри Stage::move.
ok(!Stage::versionDeferred(7) && !Stage::versionDeferred(0), 'вне перестановки версия маршрута поднимается при каждом сохранении этапа');
$svojstvo = new ReflectionProperty(Stage::class, 'swapFirst');
ok($svojstvo->isPrivate() && $svojstvo->isStatic(), 'отсрочку не задать вводом формы: это закрытое свойство класса');
$svojstvo->setValue(null, 7);
ok(Stage::versionDeferred(7) && !Stage::versionDeferred(8) && !Stage::versionDeferred(0),
    'во время перестановки отсрочка — только у первого этапа пары');
$svojstvo->setValue(null, null);

// 2. Stage::move: отсрочка снимается до второго сохранения и при сбое первого.
$kod = static function (ReflectionMethod $m): string {
    return implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
};
$move = $kod(new ReflectionMethod(Stage::class, 'move'));
$pervoe = strpos($move, 'self::$swapFirst = $id;');
$snyato = strpos($move, 'self::$swapFirst = null;');
$vtoroe = strpos($move, "'ranking' => \$a, '_itilflow_swap_with' => \$id]");
ok($pervoe !== false && $snyato !== false && $vtoroe !== false && $pervoe < $snyato && $snyato < $vtoroe
    && preg_match('/finally\s*\{\s*self::\$swapFirst = null;/', $move) === 1,
    'move: первое сохранение — с отсрочкой, она снимается в finally до второго сохранения');

// 3. DefinitionMutation: версия маршрута не поднимается только у отсроченного сохранения.
$mut = file_get_contents(dirname(__DIR__, 2) . '/src/DefinitionMutation.php');
ok(substr_count($mut, 'Stage::versionDeferred(') === 1
    && str_contains($mut, 'if ($this instanceof Stage && !Stage::versionDeferred((int) ($input[\'id\'] ?? 0))) {'),
    'DefinitionMutation: подъём версии и проверка полноты пропускаются только у первой половины перестановки');

itog();
