<?php

namespace GlpiPlugin\Itilflow;

/**
 * Срок этапа в днях или часах рабочего календаря маршрута.
 *
 * В базе срок по-прежнему в минутах (`deadline_minutes`), считает его движок
 * по календарю маршрута. Здесь только перевод для формы и таблицы этапов.
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
