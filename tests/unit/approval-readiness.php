<?php
/** Предупреждения готовности: конечный состав, округление GLPI и отсутствие заместителя. */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Readiness;

$stage = ['id' => 9, 'ranking' => 1, 'name' => 'Проверка', 'approval_percent' => 67];
$preview = ['ok' => true, 'count' => 3, 'warnings' => []];
$notes = Readiness::approvalWarnings([$stage], [9 => $preview]);
ok(count($notes) === 1 && str_contains($notes[0]['text'], 'нужно 3 из 3'), '67 процентов: округлённая цель видна', $notes[0]['text'] ?? '');
ok(str_contains($notes[0]['text'], 'GLPI может не подвести итог') && str_contains($notes[0]['text'], 'этап закроет администратор маршрутов'),
    'не подменяет правило GLPI и называет действие администратора маршрутов', $notes[0]['text'] ?? '');
ok($notes[0]['snapshot'] === false && $notes[0]['stage'] === 9, 'предупреждение не делает снимок недействительным');
ok(Readiness::approvalWarnings([array_replace($stage, ['approval_percent' => 50])], [9 => $preview]) === [], 'при 50 процентах состав из трёх не даёт ложного предупреждения');
ok(Readiness::approvalWarnings([array_replace($stage, ['approval_percent' => 100])], [9 => $preview]) === [], 'единогласие не даёт ложного предупреждения');
ok(Readiness::approvalWarnings([$stage], [9 => array_replace($preview, ['ok' => false])]) === [], 'неполный состав не выдаётся за рассчитанный порог');
$notes = Readiness::approvalWarnings([$stage], [9 => array_replace($preview, ['count' => 1, 'warnings' => ['Нет действующего заместителя.']])]);
ok(count($notes) === 1 && str_contains($notes[0]['text'], 'заместителя'), 'заместитель показан отдельным предупреждением');
ok(Readiness::approvalWarnings([$stage], []) === [], 'неизвестный состав не оценивается');
itog();
