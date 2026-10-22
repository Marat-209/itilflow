<?php

/**
 * Срок этапа вводится в рабочих днях или часах, хранится по-прежнему в минутах.
 *
 * День — рабочий день календаря маршрута: среднее число рабочих минут на рабочий
 * день. Без календаря (или у календаря без рабочих дней) день — 24 ч, как и
 * считает движок. Показ: делится на день — дни, на час — часы, иначе минуты;
 * перевод туда и обратно не меняет хранимое число.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Deadline;

if (!estKlass(Deadline::class)) {
    itog();
}

// Длина рабочего дня по отрезкам календаря: [день недели, начало, конец].
$pyatidnevka = [];
foreach ([1, 2, 3, 4, 5] as $d) {
    $pyatidnevka[] = ['day' => $d, 'begin' => '08:00:00', 'end' => '17:00:00'];
}
ok(Deadline::averageDay($pyatidnevka) === 540, '8:00–17:00 пять дней — день 540 минут');
ok(Deadline::averageDay([]) === 1440, 'без рабочих отрезков — круглые сутки');
$obed = [];
foreach ([1, 2, 3, 4, 5] as $d) {
    $obed[] = ['day' => $d, 'begin' => '08:00:00', 'end' => '12:00:00'];
    $obed[] = ['day' => $d, 'begin' => '13:00:00', 'end' => '17:00:00'];
}
ok(Deadline::averageDay($obed) === 480, 'с перерывом — сумма отрезков дня');
$nerovno = [
    ['day' => 1, 'begin' => '09:00:00', 'end' => '18:00:00'],
    ['day' => 5, 'begin' => '09:00:00', 'end' => '15:00:00'],
];
ok(Deadline::averageDay($nerovno) === 450, 'неравные дни — среднее по рабочим дням');
ok(Deadline::averageDay([['day' => 0, 'begin' => '00:00:00', 'end' => '24:00:00']]) === 1440, 'конец 24:00 считается');

// Ввод → минуты.
ok(Deadline::toMinutes('3', 'd', 540) === 1620, '3 рабочих дня по 9 ч — 1620 минут');
ok(Deadline::toMinutes('6', 'h', 540) === 360, '6 рабочих часов — 360 минут');
ok(Deadline::toMinutes('1.5', 'd', 540) === 810, '1.5 дня — 810 минут');
ok(Deadline::toMinutes('1,5', 'd', 540) === 810, 'запятая как разделитель');
ok(Deadline::toMinutes('', 'd', 540) === 0, 'пусто — без срока');
ok(Deadline::toMinutes('0', 'h', 540) === 0, 'ноль — без срока');
ok(Deadline::toMinutes('45', 'm', 540) === 45, 'минуты как есть');
ok(Deadline::toMinutes('2', 'd', 1440) === 2880, 'без календаря день — 24 ч');
ok(Deadline::toMinutes('-1', 'd', 540) === null, 'отрицательное — ошибка');
ok(Deadline::toMinutes('три', 'd', 540) === null, 'не число — ошибка');
ok(Deadline::toMinutes('3', 'w', 540) === null, 'неизвестная единица — ошибка');
ok(Deadline::toMinutes('99999999', 'd', 1440) === null, 'больше, чем вмещает поле, — ошибка');

// Минуты → показ.
ok(Deadline::split(0, 540) === ['', 'd'], '0 — пустое поле в днях');
ok(Deadline::split(1620, 540) === ['3', 'd'], '1620 — 3 дня');
ok(Deadline::split(360, 540) === ['6', 'h'], '360 — 6 часов');
ok(Deadline::split(810, 540) === ['1.5', 'd'], '810 — 1.5 дня');
ok(Deadline::split(270, 540) === ['4.5', 'h'], '270 — 4.5 часа');
ok(Deadline::split(45, 540) === ['45', 'm'], '45 — в минутах: третья единица только для такого значения');
ok(Deadline::split(2880, 1440) === ['2', 'd'], 'без календаря 2880 — 2 дня');

// Круг: любые минуты возвращаются теми же.
$krug = [];
foreach ([1, 30, 45, 60, 90, 270, 360, 480, 540, 600, 810, 1080, 1440, 1620, 2160, 2700, 3240, 3780, 4321] as $m) {
    foreach ([540, 480, 450, 1440] as $day) {
        [$v, $u] = Deadline::split($m, $day);
        if (Deadline::toMinutes($v, $u, $day) !== $m) {
            $krug[] = $m . '@' . $day . '→' . $v . $u;
        }
    }
}
ok($krug === [], 'показ и ввод возвращают те же минуты', implode(', ', $krug));
foreach ([1, 2, 3, 4, 5, 6, 7] as $n) {
    if (Deadline::split(540 * $n, 540) !== [(string) $n, 'd']) {
        $krug[] = $n;
    }
}
ok($krug === [], '540×N показывается как N дней');

// Подпись в таблице этапов.
ok(Deadline::label(1620, 540) === '3 р. д.', 'подпись дней');
ok(Deadline::label(810, 540) === '1,5 р. д.', 'подпись полутора дней');
ok(Deadline::label(360, 540) === '6 ч', 'подпись часов');
ok(Deadline::label(45, 540) === '45 мин', 'подпись минут');
ok(Deadline::label(0, 540) === '—', 'без срока — прочерк');

// Проверяем границы и вызовы календаря/SLA без базы. Заменители возвращают результат ядра;
// его реальные календарные расчёты здесь не проверяются.
class Calendar
{
    public static bool $found = true;
    public static bool $working = true;
    public static mixed $due = null;
    public static array $calls = [];
    public function getFromDB($id): bool { self::$calls[] = ['load', $id]; return self::$found; }
    public function hasAWorkingDay(): bool { return self::$working; }
    public function computeEndDate($start, $seconds): mixed
    {
        self::$calls[] = ['compute', $start, $seconds];
        if (self::$due instanceof Throwable) { throw self::$due; }
        return self::$due;
    }
}
class SLA
{
    public static bool $found = true;
    public static mixed $due = null;
    public static bool $inherit = true;
    public static array $calls = [];
    private int $calendar = 17;
    public function getFromDB($id): bool { self::$calls[] = ['load', $id]; return self::$found; }
    public function setTicketCalendar($id): void
    {
        self::$calls[] = ['ticket_calendar', $id];
        if (self::$inherit) { $this->calendar = $id; }
    }
    public function computeDate($start): mixed
    {
        self::$calls[] = ['compute', $start, $this->calendar];
        if (self::$due instanceof Throwable) { throw self::$due; }
        return self::$due;
    }
}

$zone = date_default_timezone_get();
$hadClock = array_key_exists('glpi_currenttime', $_SESSION ?? []);
$savedClock = $_SESSION['glpi_currenttime'] ?? null;
try {
    date_default_timezone_set('UTC');
    $_SESSION['glpi_currenttime'] = '2026-10-21 12:00:00';
    ok(Deadline::storable(null), 'отсутствие срока разрешено');
    ok(Deadline::storable('2038-01-18 03:14:07'), 'граница с запасом ровно сутки разрешена');
    ok(!Deadline::storable('2038-01-18 03:14:08'), 'на секунду меньше запаса суток — отказ');
    ok(!Deadline::storable(Deadline::STORAGE_LIMIT_UTC), 'секунда предела TIMESTAMP без запаса суток запрещена');
    ok(!Deadline::storable('2038-01-19 03:14:08'), 'секунда после предела TIMESTAMP запрещена');
    ok(!Deadline::storable('1970-01-01 00:00:00') && Deadline::storable('1970-01-01 00:00:01'), 'нижняя граница TIMESTAMP тоже проверена');
    $invalid = ['', '0000-00-00 00:00:00', '2030-02-31 00:00:00', '2030-01-01 24:00:00', 'tomorrow', "2030-01-01 00:00:00\0"];
    ok(!array_filter($invalid, [Deadline::class, 'storable']), 'неправильная дата не нормализуется и не становится бессрочной');
    date_default_timezone_set('Europe/Moscow');
    ok(Deadline::storable('2038-01-18 06:14:07') && !Deadline::storable('2038-01-18 06:14:08'), 'локальная дата сравнивается с UTC-пределом, Москва');
    date_default_timezone_set('America/New_York');
    ok(Deadline::storable('2038-01-17 22:14:07') && !Deadline::storable('2038-01-17 22:14:08'), 'отрицательное UTC-смещение тоже учитывается');
    date_default_timezone_set('UTC');
    ok(Deadline::durationError(0, 0) === null, 'нулевая длительность остаётся без срока');
    ok(Deadline::durationError(-1, 0) !== null, 'отрицательная длительность не становится бессрочной');
    ok(Deadline::durationError(30 * 1440, 0) === null, 'обычный срок проходит без календаря');
    ok(Deadline::durationError(5000 * 1440, 0) !== null, '5000 дней без календаря отклоняются при сохранении');
    $_SESSION['glpi_currenttime'] = '2038-01-18 03:13:07';
    ok(Deadline::durationError(1, 0) === null, 'вычисленная граничная минута проходит');
    $_SESSION['glpi_currenttime'] = '2038-01-18 03:13:08';
    ok(Deadline::durationError(1, 0) !== null, 'тот же срок при позднем запуске отклоняется');
    $_SESSION['glpi_currenttime'] = '2026-10-21 12:00:00';
    Calendar::$calls = [];
    ok(Deadline::durationError(2147483647, 7) !== null && Calendar::$calls === [],
        'непомещающийся круглосуточный минимум отклонён без загрузки и обхода календаря');
    Calendar::$due = '2038-01-18 03:14:07';
    ok(Deadline::durationError(60, 7) === null
        && Calendar::$calls === [['load', 7], ['compute', '2026-10-21 12:00:00', 3600]], 'календарь получает время запуска и секунды, граничный результат принят');
    Calendar::$due = '2038-01-18 03:14:08';
    ok(Deadline::durationError(60, 7) !== null, 'короткий рабочий срок с дальней календарной датой отклонён');
    Calendar::$due = null;
    ok(Deadline::durationError(60, 7) !== null, 'ошибка календаря NULL не разрешает бессрочный этап');
    Calendar::$due = new RuntimeException('calendar failure');
    ok(Deadline::durationError(60, 7) !== null, 'исключение календаря даёт отказ словами');
    Calendar::$working = false;
    ok(Deadline::durationError(60, 7) === null, 'календарь без рабочих дней считает круглосуточно, как движок');
    Calendar::$working = true;
    Calendar::$found = false;
    ok(Deadline::durationError(60, 7) === null, 'отсутствующий календарь считает круглосуточно, как движок');
    ok(Deadline::slaError(0, 0) === null, 'невыбранный SLA разрешён');
    SLA::$calls = [];
    SLA::$due = '2030-01-01 00:00:00';
    ok(Deadline::slaError(5, 9) === null
        && SLA::$calls === [['load', 5], ['ticket_calendar', 9], ['compute', '2026-10-21 12:00:00', 9]], 'SLA рассчитывает ядро с календарём дочерней заявки и текущим временем');
    SLA::$calls = [];
    SLA::$inherit = false;
    ok(Deadline::slaError(5, 9) === null && SLA::$calls[2][2] === 17, 'собственный календарь SLA не заменяется календарём маршрута');
    SLA::$due = '2040-06-29 12:00:00';
    ok(Deadline::slaError(5, 9) !== null, 'дальний результат SLA не допускается до создания дочерней');
    SLA::$due = null;
    ok(Deadline::slaError(5, 9) !== null, 'SLA без вычисленной даты не считается проверенным');
    SLA::$due = new RuntimeException('sla failure');
    ok(Deadline::slaError(5, 9) !== null, 'ошибка вычисления SLA даёт отказ словами');
    SLA::$found = false;
    ok(Deadline::slaError(5, 9) !== null, 'удалённый SLA не пропускается');
    $_SESSION['glpi_currenttime'] = 'не дата';
    ok(Deadline::durationError(1, 0) !== null, 'неверное время запуска не подменяется текущим временем сервера');
} finally {
    date_default_timezone_set($zone);
    if ($hadClock) { $_SESSION['glpi_currenttime'] = $savedClock; }
    else { unset($_SESSION['glpi_currenttime']); }
}

itog();
