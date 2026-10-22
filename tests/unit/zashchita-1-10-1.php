<?php

/**
 * 1.10.1 без базы: что объявляет плагин и как читается настройка этапа.
 *
 * 1. setup.php ставит защиту на «Удалить навсегда» запроса на согласование — для заявок
 *    и изменений, тем же обработчиком; защита заявок, изменений и проблем на месте.
 * 2. Согласование «Указан в этапе» с группой и сотрудником сразу — противоречие; задача
 *    и заявка отдела с обоими полями, согласование «при прохождении» — нет.
 */

require __DIR__ . '/_nachalo.php';

use Glpi\Plugin\Hooks;
use GlpiPlugin\Itilflow\Hook;
use GlpiPlugin\Itilflow\Stage;

if (!estKlass(Hook::class) || !estKlass(Stage::class)) {
    itog();
}

// 1. Хуки из setup.php рабочей копии.
require dirname(__DIR__, 2) . '/setup.php';
global $PLUGIN_HOOKS, $DB;
$PLUGIN_HOOKS = [];
// Базы нет: проверке прав меню в setup.php достаточно знать, что это не реплика только для чтения.
$DB = new class {
    public function isSlave(): bool { return false; }
};
plugin_init_itilflow();
$purge = $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['itilflow'] ?? [];
foreach (['TicketValidation', 'ChangeValidation'] as $tip) {
    $h = $purge[$tip] ?? null;
    ok(is_array($h) && $h[0] === Hook::class && $h[1] === 'prePurgeValidation' && is_callable($h),
        $tip . ': «Удалить навсегда» проходит через Hook::prePurgeValidation', var_export($h, true));
}
foreach (['Ticket', 'Change', 'Problem'] as $tip) {
    ok(($purge[$tip][1] ?? '') === 'prePurgeItil', $tip . ': удаление заявки по-прежнему убирает её маршрут');
}
ok(!isset($PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['itilflow']['ProblemValidation']), 'у проблем согласований нет — и хука нет');

$m = new ReflectionMethod(Hook::class, 'prePurgeValidation');
ok($m->isPublic() && $m->isStatic() && (string) $m->getParameters()[0]->getType() === 'CommonITILValidation',
    'обработчик принимает любой запрос на согласование GLPI');

// 2. Противоречие «группа и сотрудник» — только у согласования «Указан в этапе».
$etap = static fn(array $f): array => $f + ['execution_mode' => Stage::MODE_APPROVAL,
    'approver_source' => Stage::APPROVER_FIXED, 'groups_id' => 0, 'users_id' => 0];
$sluchai = [
    ['согласование «Указан в этапе»: группа и сотрудник', ['groups_id' => 5, 'users_id' => 7], true],
    ['согласование «Указан в этапе»: только группа', ['groups_id' => 5], false],
    ['согласование «Указан в этапе»: только сотрудник', ['users_id' => 7], false],
    ['согласование «при прохождении»: поля остались', ['approver_source' => Stage::APPROVER_RUNTIME, 'groups_id' => 5, 'users_id' => 7], false],
    ['согласование без источника (старая строка): как «Указан в этапе»', ['approver_source' => null, 'groups_id' => 5, 'users_id' => 7], true],
    ['задача в заявке: группа и сотрудник', ['execution_mode' => Stage::MODE_INLINE, 'groups_id' => 5, 'users_id' => 7], false],
    ['заявка отдела: группа и сотрудник', ['execution_mode' => Stage::MODE_DELEGATED, 'groups_id' => 5, 'users_id' => 7], false],
    ['номера строкой, как из базы', ['groups_id' => '5', 'users_id' => '7'], true],
];
foreach ($sluchai as [$imya, $polya, $zhdem]) {
    ok(Stage::approverConflict($etap($polya)) === $zhdem, $imya . ($zhdem ? ' — противоречие' : ' — допустимо'));
}

itog();
