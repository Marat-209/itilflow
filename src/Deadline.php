<?php

namespace GlpiPlugin\Itilflow;

/**
 * Срок этапа в днях или часах рабочего календаря маршрута.
 *
 * В базе срок по-прежнему в минутах (`deadline_minutes`), считает его движок
 * по календарю маршрута. Здесь перевод для формы и проверка срока до записи.
 * Рабочий день — среднее число рабочих минут на рабочий день календаря:
 * у «8:00–17:00 пять дней» это 540. Без календаря или у календаря без рабочих
 * дней день — 24 ч: так и движок считает срок круглосуточно.
 */
final class Deadline
{
    public const DAY = 'd';
    public const HOUR = 'h';
    public const MINUTE = 'm';

    private const MAX_MINUTES = 2147483647;

    /**
     * 1.13: сроки хранятся в
     * TIMESTAMP: и у плагина (steps.date_due), и у ядра (glpi_tickets.time_to_resolve). Дальше
     * 2038-01-19 03:14:07 UTC MySQL 8 не запишет (ошибка 1292) — маршрут не запустится.
     */
    public const STORAGE_LIMIT_UTC = '2038-01-19 03:14:07';

    /**
     * Ошибка срока этапа при сохранении и импорте: срок, отсчитанный от сегодня по календарю
     * маршрута, уходит за предел хранения. null — можно сохранить. Вызывают Stage
     * и Transfer.
     */
    public static function durationError(int $minutes, int $calendarsId): ?string
    {
        if ($minutes === 0) {
            return null;
        }
        if ($minutes < 0 || $minutes > self::MAX_MINUTES) {
            return 'Срок этапа указан неверно. Укажите неотрицательное число дней или часов.';
        }
        try {
            $start = self::now();
            $date = self::date($start);
            if ($date === null) {
                return 'Не удалось определить начало срока этапа. Проверьте дату и время.';
            }
            // Те же секунды и тот же календарь, что у Engine::computeDue().
            $due = date('Y-m-d H:i:s', $date->getTimestamp() + $minutes * 60);
            // Даже круглосуточный срок уже не помещается: не запускаем
            // посуточный обход рабочего календаря на миллионы дней.
            if (!self::storable($due)) {
                return 'Срок слишком большой: этап закончится позже 18.01.2038 — такую дату база не хранит. Укажите меньше.';
            }
            if ($calendarsId > 0) {
                $calendar = new \Calendar();
                if ($calendar->getFromDB($calendarsId) && $calendar->hasAWorkingDay()) {
                    $due = $calendar->computeEndDate($start, $minutes * 60);
                }
            }
            if (!is_string($due) || self::date($due) === null) {
                return 'Не удалось вычислить срок этапа. Проверьте срок и рабочий календарь.';
            }
            return self::storable($due) ? null
                : 'Срок слишком большой: этап закончится позже 18.01.2038 — такую дату база не хранит. Укажите меньше.';
        } catch (\Throwable) {
            // Ошибка календаря не должна превращать ненулевой срок в «без срока».
            return 'Не удалось вычислить срок этапа. Проверьте срок и рабочий календарь.';
        }
    }

    /**
     * Ошибка SLA этапа «Дочерняя заявка»: время решения дочерней заявки уйдёт за предел
     * хранения. null — можно. Вызывает Stage; при запуске то же проверяет движок.
     */
    public static function slaError(int $slasId, int $calendarsId): ?string
    {
        if ($slasId === 0) {
            return null;
        }
        try {
            $sla = new \SLA();
            if ($slasId < 0 || !$sla->getFromDB($slasId)) {
                return 'Не удалось найти SLA дочерней заявки. Выберите действующий SLA.';
            }
            $start = self::now();
            if (self::date($start) === null) {
                return 'Не удалось определить начало срока дочерней заявки. Проверьте дату и время.';
            }
            // Как Ticket::getDatasToAddSLA(): это календарь организации дочерней заявки,
            // не календарь маршрута. setTicketCalendar сохраняет собственный календарь SLA,
            // если use_ticket_calendar выключен. Месяцы и конец рабочего дня считает ядро.
            $sla->setTicketCalendar($calendarsId);
            $due = $sla->computeDate($start);
            if (!is_string($due) || self::date($due) === null) {
                return 'Не удалось вычислить срок решения дочерней заявки. Проверьте SLA и рабочий календарь.';
            }
            return self::storable($due) ? null
                : 'Срок решения дочерней заявки слишком большой: выберите SLA с запасом не меньше суток до 19.01.2038.';
        } catch (\Throwable) {
            return 'Не удалось вычислить срок решения дочерней заявки. Проверьте SLA и рабочий календарь.';
        }
    }

    /** Срок помещается в хранение (с запасом не меньше суток). Движок: до записи шага и до создания дочерней заявки. */
    public static function storable(?string $due): bool
    {
        if ($due === null) {
            return true; // Только отсутствие срока, а не ошибка расчёта.
        }
        $date = self::date($due);
        if ($date === null) {
            return false;
        }
        // Значение GLPI имеет текущую временную зону PHP; граница хранения всегда UTC.
        // Сутки — 86400 секунд, независимо от переходов летнего времени.
        $utc = new \DateTimeZone('UTC');
        $limit = new \DateTimeImmutable(self::STORAGE_LIMIT_UTC, $utc);
        $first = new \DateTimeImmutable('1970-01-01 00:00:01', $utc);
        return $date->getTimestamp() >= $first->getTimestamp()
            && $date->getTimestamp() <= $limit->getTimestamp() - 86400;
    }

    private static function now(): string
    {
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        return is_string($now) ? $now : '';
    }

    /** Не нормализовать 31 февраля, 24:00 или неверную строку в другую дату. */
    private static function date(string $value): ?\DateTimeImmutable
    {
        try {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone(date_default_timezone_get()));
            $errors = \DateTimeImmutable::getLastErrors();
            return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format('Y-m-d H:i:s') === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Средняя длина рабочего дня по отрезкам календаря.
     *
     * @param array<array{day:int|string,begin:string,end:string}> $segments
     */
    public static function averageDay(array $segments): int
    {
        $byDay = [];
        foreach ($segments as $s) {
            $minutes = self::clock((string) $s['end']) - self::clock((string) $s['begin']);
            if ($minutes > 0) {
                $byDay[(int) $s['day']] = ($byDay[(int) $s['day']] ?? 0) + $minutes;
            }
        }
        return $byDay ? (int) round(array_sum($byDay) / count($byDay)) : 1440;
    }

    /** Длина рабочего дня календаря маршрута; 0 — календарь не выбран. */
    public static function dayMinutes(int $calendars_id): int
    {
        if ($calendars_id <= 0) {
            return 1440;
        }
        $calendar = new \Calendar();
        if (!$calendar->getFromDB($calendars_id)) {
            return 1440;
        }
        return self::averageDay(array_values((new \CalendarSegment())->find(['calendars_id' => $calendars_id])));
    }

    /**
     * Ввод формы → минуты. Пусто или 0 — без срока (0).
     *
     * @return int|null null — значение не распознано
     */
    public static function toMinutes(mixed $value, string $unit, int $day): ?int
    {
        $text = str_replace(',', '.', trim((string) $value));
        if ($text === '') {
            return 0;
        }
        $per = match ($unit) {
            self::DAY => max(1, $day),
            self::HOUR => 60,
            self::MINUTE => 1,
            default => null,
        };
        if ($per === null || !preg_match('/^[0-9]+(\.[0-9]+)?$/D', $text)) {
            return null;
        }
        $minutes = round((float) $text * $per);
        return $minutes > self::MAX_MINUTES ? null : (int) $minutes;
    }

    /**
     * Минуты → [число, единица] для формы. Целые дни (и полтора, два с половиной…) —
     * в днях, кратное получасу — в часах, остальное — в минутах.
     *
     * @return array{0:string,1:string}
     */
    public static function split(int $minutes, int $day): array
    {
        $day = max(1, $day);
        if ($minutes <= 0) {
            return ['', self::DAY];
        }
        if ($minutes % $day === 0 || ($minutes >= $day && (2 * $minutes) % $day === 0)) {
            return [self::number($minutes / $day), self::DAY];
        }
        if ($minutes % 30 === 0) {
            return [self::number($minutes / 60), self::HOUR];
        }
        return [(string) $minutes, self::MINUTE];
    }

    /** Подпись срока в таблице этапов: «3 р. д.», «6 ч», «45 мин», «—». */
    public static function label(int $minutes, int $day): string
    {
        [$value, $unit] = self::split($minutes, $day);
        if ($value === '') {
            return '—';
        }
        $value = str_replace('.', ',', $value);
        return match ($unit) {
            self::DAY => $value . ' р. д.',
            self::HOUR => $value . ' ч',
            default => $value . ' мин',
        };
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function clock(string $time): int
    {
        $parts = array_map('intval', explode(':', $time) + [0, 0, 0]);
        return $parts[0] * 60 + $parts[1];
    }
}
