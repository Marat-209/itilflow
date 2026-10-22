<?php

/**
 * «Нужно N из M» должно совпадать с правилом ядра GLPI.
 * При M = 50 и P = 58 % экран писал «нужно 29», а ступень даёт «Согласовано» только при 30.
 * Перебор M 1–50 и P 1–100: N — наименьшее число одобрений, при котором
 * ValidationLadder::status (копия правила ядра) даёт «Согласовано», если остальные ждут.
 *
 * MySQL 8: срок этапа дальше предела TIMESTAMP (2038-01-19) не сохраняется и не пишется.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\ApprovalRoster;
use GlpiPlugin\Itilflow\ValidationLadder;

if (!estKlass(ApprovalRoster::class) || !estKlass(ValidationLadder::class)) {
    itog();
}

$rashozhdeniya = [];
for ($m = 1; $m <= 50; $m++) {
    for ($p = 1; $p <= 100; $p++) {
        $nuzhno = null;
        for ($a = 0; $a <= $m; $a++) {
            if (ValidationLadder::status($a, 0, $m - $a, $p) === \CommonITILValidation::ACCEPTED) {
                $nuzhno = $a;
                break;
            }
        }
        $pokazano = ApprovalRoster::threshold($m, $p)['needed'];
        if ($nuzhno !== $pokazano) {
            $rashozhdeniya[] = "M=$m P=$p: экран $pokazano, ядро $nuzhno";
        }
    }
}
ok($rashozhdeniya === [], '«нужно N из M» совпадает с правилом ядра', implode('; ', array_slice($rashozhdeniya, 0, 10)));

// MySQL 8.0.46: «5000 дней» без календаря сохранялось, а запуск
// падал на записи срока 2040 года в TIMESTAMP. Теперь — отказ при сохранении словами.
ok(\GlpiPlugin\Itilflow\Deadline::durationError(5000 * 1440, 0) !== null, 'срок 5000 дней не сохраняется: дальше 2038 года');
ok(\GlpiPlugin\Itilflow\Deadline::durationError(30 * 1440, 0) === null, 'обычный срок 30 дней сохраняется');
ok(!\GlpiPlugin\Itilflow\Deadline::storable('2040-06-29 15:04:21') && \GlpiPlugin\Itilflow\Deadline::storable('2030-01-01 00:00:00'),
    'срок 2040 года не записывается в этап, 2030 — записывается');

itog();
