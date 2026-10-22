<?php

/**
 * 1.11.0, шаг 2: своя ступень согласования GLPI на проход этапа (ValidationLadder).
 *
 * 1. Название и ключ: «Этап маршрута N — порог P %», с попытки 2 — «…, попытка K…»,
 *    со второго запуска на заявке — «…, запуск R…»; ключ читается только из своих названий.
 * 2. Итог ступени: ValidationLadder::status совпадает с исполняемым кодом ядра
 *    (ITIL_ValidationStep::getStatus) для M от 1 до 30 запросов, порогов 0–100 и всех
 *    распределений «да / нет / ожидает». Особый случай 0 % — как в ядре.
 * 3. Исход этапа (outcome) = итог ступени: «Согласовано» — выполнен, «Отклонено» — отказ.
 * 4. Снятие неотвеченных после исхода итог ступени не переворачивает.
 * 5. Прежнее правило (запуски до 1.11) — целые проценты, как в 1.10.
 * Без базы: ядро считает итог по долям, доли подставляются так же, как их считает ядро.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Outcome;
use GlpiPlugin\Itilflow\ValidationLadder;

if (!estKlass(ValidationLadder::class)) {
    itog();
}

// 1. Название и ключ.
ok(ValidationLadder::name(10, 1, 1, 50) === 'Этап маршрута 10 — нужно 50 %', 'первая попытка первого запуска', ValidationLadder::name(10, 1, 1, 50));
ok(ValidationLadder::name(10, 2, 1, 50) === 'Этап маршрута 10, попытка 2 — нужно 50 %', 'попытка 2');
ok(ValidationLadder::name(20, 1, 2, 100) === 'Этап маршрута 20, запуск 2 — нужно 100 %', 'второй запуск на той же заявке');
ok(ValidationLadder::name(20, 3, 2, 67) === 'Этап маршрута 20, попытка 3, запуск 2 — нужно 67 %', 'попытка и запуск вместе');
$kluchi = [];
$povtor = false;
foreach ([1, 2, 10, 25, 300] as $n) {
    foreach ([1, 2, 7] as $k) {
        foreach ([1, 2, 3] as $r) {
            foreach ([1, 50, 67, 100] as $p) {
                $imya = ValidationLadder::name($n, $k, $r, $p);
                $povtor = $povtor || isset($kluchi[$imya]);
                $kluchi[$imya] = true;
                $kl = ValidationLadder::parse($imya);
                if ($kl !== ['stage' => $n, 'attempt' => $k, 'run' => $r, 'percent' => $p]) {
                    ok(false, 'ключ из названия «' . $imya . '»', json_encode($kl));
                }
                $legacy = str_replace(' — нужно ', ' — порог ', $imya);
                if (ValidationLadder::parse($legacy) !== $kl) {
                    ok(false, 'старое имя 1.11–1.12 даёт тот же ключ', $legacy);
                }
            }
        }
    }
}
ok(!$povtor, 'разные ключи — разные названия: попытки и запуски не смешиваются в одной ступени');
ok(true, 'ключ читается из любого своего названия (180 сочетаний)');
foreach ([
    'Этап маршрута — порог 50%' => 'прежняя общая ступень',
    'Этап маршрута 10 — порог 50%' => 'без пробела перед %',
    'Этап маршрута 10, попытка 1 — порог 50 %' => 'попытка 1 не пишется',
    'Этап маршрута 10, запуск 1 — порог 50 %' => 'запуск 1 не пишется',
    'Этап маршрута 10, запуск 2, попытка 2 — порог 50 %' => 'другой порядок',
    'Этап маршрута 010 — порог 50 %' => 'ноль в начале номера',
    'Этап маршрута 10 — порог 101 %' => 'порог больше 100',
    'Согласование учебного запроса этап 1' => 'чужая ступень',
    'Этап маршрута 10 — порог 50 % ' => 'хвостовой пробел',
] as $imya => $chto) {
    ok(ValidationLadder::parse($imya) === null, 'не своё название: ' . $chto);
    ok(ValidationLadder::parse(str_replace(' — порог ', ' — нужно ', $imya)) === null,
        'новый формат также отвергает некорректный ключ: ' . $chto);
}

// 2. Итог ступени — исполняемым кодом ядра.
$yadro = new class extends \ITIL_ValidationStep {
    public array $doli = [];
    public function getAchievements(): array
    {
        return $this->doli;
    }
};
$ACC = \CommonITILValidation::ACCEPTED;
$REF = \CommonITILValidation::REFUSED;
$WAIT = \CommonITILValidation::WAITING;
$raznica = [];
$vsego = 0;
$napravlenie = ['отказ→ждём' => 0];
for ($m = 1; $m <= 30; $m++) {
    for ($a = 0; $a <= $m; $a++) {
        for ($r = 0; $a + $r <= $m; $r++) {
            $w = $m - $a - $r;
            // Доли — как ITIL_ValidationStep::getAchievements: count / всего * 100.
            $yadro->doli = [$ACC => $a / $m * 100, $REF => $r / $m * 100, $WAIT => $w / $m * 100];
            for ($p = 0; $p <= 100; $p++) {
                $yadro->fields = ['minimal_required_validation_percent' => $p];
                $vsego++;
                $u_yadra = $yadro->getStatus();
                if (ValidationLadder::status($a, $r, $w, $p) !== $u_yadra) {
                    $raznica[] = "M=$m да=$a нет=$r ждём=$w порог=$p";
                }
                $iskhod = ValidationLadder::outcome($a, $r, $w, $p);
                $ozhid = match ($u_yadra) { $ACC => Outcome::COMPLETED, $REF => Outcome::REFUSED, default => null };
                if ($iskhod !== $ozhid) {
                    $raznica[] = "исход M=$m да=$a нет=$r ждём=$w порог=$p";
                }
                // Прежнее правило объявляло отказ там, где ядро ещё ждёт (округление суммы долей).
                if ($p > 0 && ValidationLadder::legacyOutcome($a, $r, $m, $p) === Outcome::REFUSED && $u_yadra === $WAIT) {
                    $napravlenie['отказ→ждём']++;
                }
            }
        }
    }
}
ok($raznica === [], sprintf('итог ступени совпадает с кодом ядра: %d сочетаний (M 1–30, порог 0–100)', $vsego),
    count($raznica) . ': ' . implode('; ', array_slice($raznica, 0, 5)));
ok(ValidationLadder::status(0, 0, 0, 0) === $WAIT && ValidationLadder::status(0, 0, 0, 50) === $REF,
    'пустая ступень: порог 0 — ждём, выше нуля — «Отклонено», как в ядре');
ok(ValidationLadder::status(1, 5, 0, 0) === $ACC && ValidationLadder::status(0, 1, 3, 0) === $REF,
    'порог 0 %: одно «да» — согласовано, иначе одно «нет» — отклонено');
ok(ValidationLadder::status(2, 1, 0, 50) === $ACC, 'порог 50 %: два «да» и одно «нет» — «Согласовано»');
ok(ValidationLadder::status(2, 1, 0, 67) === $WAIT,
    'порог 67 %: два «да» и одно «нет» — у ядра «Ожидает» (66,67 округляется до 67): этап тоже ждёт');
info(sprintf('прежнее правило объявило бы отказ при «Ожидает» у ядра: %d сочетаний — у запусков 1.11 так не будет',
    $napravlenie['отказ→ждём']));
// Где ядро держит «Ожидает», хотя ответили все: доля «да» чуть ниже порога округляется до него.
$zavisaet = [];
for ($m = 1; $m <= 50; $m++) {
    for ($p = 1; $p <= 100; $p++) {
        for ($a = 0; $a <= $m; $a++) {
            if (ValidationLadder::status($a, $m - $a, 0, $p) === $WAIT) {
                $zavisaet[] = "M=$m, $p %";
                break;
            }
        }
    }
}
info(sprintf('составов до 50 запросов и порогов, где после всех ответов GLPI остаётся «Ожидает»: %d из 5000; при 50 %% и 100 %% — %s; примеры: %s',
    count($zavisaet), array_filter($zavisaet, static fn(string $z): bool => str_ends_with($z, ' 50 %') || str_ends_with($z, ' 100 %')) ? 'есть' : 'нет',
    implode(', ', array_slice($zavisaet, 0, 6))));

// 4. Снятие неотвеченных после исхода не переворачивает итог ступени.
$perevorot = [];
for ($m = 1; $m <= 30; $m++) {
    for ($a = 0; $a <= $m; $a++) {
        for ($r = 0; $a + $r <= $m; $r++) {
            $w = $m - $a - $r;
            if ($w === 0 || $a + $r === 0) {
                continue;
            }
            for ($p = 1; $p <= 100; $p++) {
                $do = ValidationLadder::status($a, $r, $w, $p);
                if ($do === $WAIT) {
                    continue; // исхода нет — маршрут ничего не снимает
                }
                if (ValidationLadder::status($a, $r, 0, $p) !== $do) {
                    $perevorot[] = "M=$m да=$a нет=$r ждём=$w порог=$p";
                }
            }
        }
    }
}
ok($perevorot === [], 'снятие неотвеченных после «Согласовано» или «Отклонено» итог ступени не меняет',
    implode('; ', array_slice($perevorot, 0, 5)));

// 5. Прежнее правило — как в 1.10: целые проценты с отбрасыванием дробной части.
$staroe = static function (int $a, int $r, int $m, int $p): ?string {
    return intdiv($a * 100, $m) >= $p ? Outcome::COMPLETED : (intdiv(($m - $r) * 100, $m) < $p ? Outcome::REFUSED : null);
};
$ne_tak = 0;
for ($m = 1; $m <= 30; $m++) {
    for ($a = 0; $a <= $m; $a++) {
        for ($r = 0; $a + $r <= $m; $r++) {
            for ($p = 1; $p <= 100; $p++) {
                $ne_tak += ValidationLadder::legacyOutcome($a, $r, $m, $p) !== $staroe($a, $r, $m, $p) ? 1 : 0;
            }
        }
    }
}
ok($ne_tak === 0, 'запуски до 1.11: исход этапа — прежним правилом, без изменений');
ok(ValidationLadder::legacyOutcome(0, 0, 0, 50) === null, 'прежнее правило: нет запросов — исхода нет');

// 6. Справочник заменён памятью: настоящие ensure/nextRun без БД. Итог выше
// по-прежнему сравнивается с исполняемым ITIL_ValidationStep ядра.
class ValidationStep
{
    public static array $rows = [];
    public static int $adds = 0;
    public function find($where = [], $order = [], $limit = null): array
    {
        $found = array_filter(self::$rows, static function (array $row) use ($where): bool {
            foreach ($where as $field => $value) {
                if (is_array($value) ? !in_array($row[$field], $value, true) : $row[$field] !== $value) {
                    return false;
                }
            }
            return true;
        });
        return $limit === null ? $found : array_slice($found, 0, $limit, true);
    }
    public function add($input): int
    {
        self::$adds++;
        $id = self::$rows ? max(array_keys(self::$rows)) + 1 : 1;
        self::$rows[$id] = ['id' => $id] + $input;
        return $id;
    }
}
$oldName = 'Этап маршрута 10, попытка 2, запуск 3 — порог 50 %';
ValidationStep::$rows = [40 => ['id' => 40, 'name' => $oldName, 'minimal_required_validation_percent' => 50]];
ok(ValidationLadder::ensure(10, 2, 3, 50) === 40 && ValidationStep::$adds === 0
    && ValidationStep::$rows[40]['name'] === $oldName, 'ensure сохраняет ID и имя старой записи, дубль не создаётся');
ValidationStep::$rows = [];
$newId = ValidationLadder::ensure(10, 2, 3, 50);
ok($newId > 0 && ValidationStep::$rows[$newId]['name'] === 'Этап маршрута 10, попытка 2, запуск 3 — нужно 50 %',
    'отсутствующий ключ создаётся с новым именем');
$adds = ValidationStep::$adds;
ok(ValidationLadder::ensure(10, 2, 3, 50) === $newId && ValidationStep::$adds === $adds,
    'повтор ensure нового имени не создаёт дубль');
ValidationStep::$rows[40] = ['id' => 40, 'name' => $oldName, 'minimal_required_validation_percent' => 50];
$ambiguous = false;
try { ValidationLadder::ensure(10, 2, 3, 50); } catch (RuntimeException) { $ambiguous = true; }
ok($ambiguous && ValidationStep::$adds === $adds, 'два формата одного ключа — явная неоднозначность без третьей записи');
ValidationStep::$rows = [40 => ['id' => 40, 'name' => $oldName, 'minimal_required_validation_percent' => 25]];
$correctId = ValidationLadder::ensure(10, 2, 3, 50);
ok($correctId !== 40 && ValidationStep::$rows[$correctId]['minimal_required_validation_percent'] === 50,
    'чужое число одобрений не подменяет ключ');

$hadDb = array_key_exists('DB', $GLOBALS);
$savedDb = $GLOBALS['DB'] ?? null;
try {
    // CLI unit не загружает Kernel: фабрика getValidationStepInstance всё же
    // читает список каталогов плагинов. Здесь плагин уже подключён _nachalo.php;
    // искать другие плагины в этой проверке не нужно.
    if (!defined('GLPI_PLUGINS_DIRECTORIES')) {
        define('GLPI_PLUGINS_DIRECTORIES', []);
    }
    $GLOBALS['DB'] = new class {
        public function request($criteria): array
        {
            return [
                ['id' => 1, 'validationsteps_id' => 40],
                ['id' => 2, 'validationsteps_id' => 41],
                ['id' => 3, 'validationsteps_id' => 42],
            ];
        }
    };
    $ticket = new \Ticket();
    $ticket->fields = ['id' => 100];
    ValidationStep::$rows = [
        40 => ['id' => 40, 'name' => $oldName, 'minimal_required_validation_percent' => 50],
        41 => ['id' => 41, 'name' => 'Этап маршрута 20, запуск 5 — нужно 50 %', 'minimal_required_validation_percent' => 50],
        42 => ['id' => 42, 'name' => 'Этап маршрута 20, запуск 99 — нужно 50 %', 'minimal_required_validation_percent' => 25],
    ];
    ok(ValidationLadder::nextRun($ticket) === 6, 'nextRun учитывает оба формата, но не запись с неверным числом одобрений');
    unset(ValidationStep::$rows[41]);
    ok(ValidationLadder::nextRun($ticket) === 4, 'nextRun продолжает старую нумерацию, не начинает запуск заново');
    ValidationStep::$rows = [];
    ok(ValidationLadder::nextRun($ticket) === 1, 'без своих записей первый запуск остаётся первым');
} finally {
    if ($hadDb) { $GLOBALS['DB'] = $savedDb; } else { unset($GLOBALS['DB']); }
}

itog();
