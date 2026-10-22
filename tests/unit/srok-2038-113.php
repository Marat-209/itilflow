<?php
/**
 * 1.13.x, MySQL 8:
 * - маршрут, остановленный сроком позже 2038 года, после исправления этапа продолжается
 *   «Возобновить»: срок и SLA — по текущей настройке этапа, а не по снимку запуска;
 * - строка готовности называет срок и SLA, которые база не сохранит, теми же словами,
 *   что и проверка при сохранении этапа.
 */
require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Deadline;
use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Readiness;

// ---------------------------------------------------------------- «Возобновить» после остановки сроком
$snimok = ['id' => 5, 'plugin_itilflow_processes_id' => 7, 'deadline_minutes' => 5000 * 1440, 'slas_id' => 9, 'name' => 'Этап'];
$sejchas = ['id' => 5, 'plugin_itilflow_processes_id' => 7, 'deadline_minutes' => 3 * 1440, 'slas_id' => 0, 'name' => 'Этап (изменён)'];
$r = Engine::resumeDeadline($snimok, $sejchas, 7);
ok($r['deadline_minutes'] === 3 * 1440 && $r['slas_id'] === 0, '«Возобновить» берёт срок и SLA из текущей настройки этапа', json_encode($r));
ok($r['name'] === 'Этап', 'остальная настройка этапа — по снимку запуска');
ok(Engine::resumeDeadline($snimok, null, 7) === $snimok, 'этапа больше нет — снимок как был');
ok(Engine::resumeDeadline($snimok, array_replace($sejchas, ['plugin_itilflow_processes_id' => 8]), 7) === $snimok,
    'этап другого маршрута не подменяет срок');
$kod = static function (string $method): string {
    $m = new ReflectionMethod(Engine::class, $method);
    return implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
};
$open = $kod('openStep');
ok(str_contains($open, 'self::$resuming') && str_contains($open, 'self::resumeDeadline(')
    && strpos($open, 'self::resumeDeadline(') < strpos($open, '$due = self::computeDue('),
    'срок при открытии этапа считается после подмены из текущей настройки');
$resume = $kod('resumeInternal');
ok(substr_count($resume, 'self::advanceResuming($instance)') === 2 && !preg_match('/self::advance\(\$instance\)/', $resume),
    'обе ветки «Возобновить» (новый и прежний порядок) открывают этап с пересчётом срока');
$flag = $kod('advanceResuming');
ok(preg_match('/finally\s*\{\s*self::\$resuming = \$prev;/', $flag) === 1, 'отметка «Возобновить» снимается и при сбое');

// ---------------------------------------------------------------- строка готовности: срок и SLA
$slova = Deadline::durationError(5000 * 1440, 0);
ok(is_string($slova) && str_starts_with($slova, 'Срок слишком большой'), 'текст проверки при сохранении этапа — источник слов');
$etap = ['id' => 1, 'ranking' => 10, 'name' => 'Задача', 'execution_mode' => 'inline', 'deadline_minutes' => 5000 * 1440, 'slas_id' => 0];
$marshrut = ['id' => 7, 'calendars_id' => 0, 'entities_id' => 0];
ok(Readiness::deadlineProblems($etap, $marshrut) === [$slova], 'срок 5000 дней, сохранённый до 1.13, — проблема готовности теми же словами');
ok(Readiness::deadlineProblems(array_replace($etap, ['deadline_minutes' => 3 * 1440]), $marshrut) === [], 'допустимый срок — без замечаний');
$slaTekst = 'Срок решения дочерней заявки слишком большой: выберите SLA с запасом не меньше суток до 19.01.2038.';
$sla = static fn(int $slas, int $calendar): ?string => $slas === 99 ? $slaTekst : null;
$kalendar = static fn(array $s, array $p): int => 0;
$doch = array_replace($etap, ['execution_mode' => 'delegated', 'deadline_minutes' => 0, 'slas_id' => 99]);
ok(Readiness::deadlineProblems($doch, $marshrut, $sla, $kalendar) === [$slaTekst], 'SLA дочерней на 200 месяцев — проблема готовности');
ok(Readiness::deadlineProblems(array_replace($doch, ['execution_mode' => 'inline']), $marshrut, $sla, $kalendar) === [],
    'SLA у задачи не действует — замечания нет');
$m = new ReflectionMethod(Readiness::class, 'stageProblems');
$sp = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
ok(str_contains($sp, 'self::deadlineProblems($s, $p)'), 'строка готовности проверяет сроки каждого этапа');

itog();
