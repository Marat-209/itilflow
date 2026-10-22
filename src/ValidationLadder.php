<?php

namespace GlpiPlugin\Itilflow;

use CommonITILValidation;

/**
 * Своя ступень согласования GLPI на проход этапа (1.11).
 *
 * Ступень заявки в ядре GLPI 11 — пара «заявка + запись справочника ступеней»
 * (CommonITILValidation::addITILValidationStepFromInput), её итог считается по всем запросам
 * ступени (ITIL_ValidationStep::getStatus), а итог согласования заявки — «Отклонено», если
 * отклонена хоть одна ступень (ITIL_ValidationStep::getValidationStatusForITIL). До 1.11 все
 * этапы с одним порогом шли в одну запись «Этап маршрута — порог P%», то есть в одну ступень
 * заявки: голоса разных этапов и попыток смешивались, и при пороге ниже 100 % отказ маршрута
 * мог выглядеть в GLPI как «Согласовано».
 *
 * Теперь у прохода этапа своя запись справочника. Ключ — запуск маршрута на заявке, номер
 * этапа, попытка, порог; новое название — «Этап маршрута N — нужно P %», с попытки 2 —
 * «Этап маршрута N, попытка K — нужно P %», со второго запуска на той же заявке — ещё
 * «, запуск R». Запись создаётся по первой надобности и общая для всех заявок: ступени
 * разных заявок — разные экземпляры. Только для запусков со снимком схемы 2; идущие
 * запуски, начатые до 1.11, остаются на общей записи. Имена 1.11–1.12 с «порог» читаются
 * с тем же ключом, их записи и ссылки сохраняются без переименования.
 *
 * Итог этапа у таких запусков считается тем же правилом, что ядро считает итог ступени
 * (status): маршрут отказал — ступень «Отклонено», согласовал — «Согласовано».
 */
final class ValidationLadder
{
    /** Название записи справочника ступеней для прохода этапа. */
    public static function name(int $stage, int $attempt, int $run, int $percent): string
    {
        $name = 'Этап маршрута ' . $stage;
        if ($attempt > 1) {
            $name .= ', попытка ' . $attempt;
        }
        if ($run > 1) {
            $name .= ', запуск ' . $run;
        }
        return $name . ' — нужно ' . $percent . ' %';
    }

    /** Общая запись маршрутов до 1.11 (Engine::ensureValidationStep); прежнее имя — Engine::SHARED_LEGACY. */
    public const SHARED = 'Этап маршрута — нужно %d %%';

    /**
     * Служебная запись плагина: ступень прохода этапа (parse) или общая запись до 1.11 под новым
     * или прежним именем. Назначению формы такие записи не принадлежат — в списках выбора их нет.
     */
    public static function isService(string $name): bool
    {
        if (self::parse($name) !== null) { return true; }
        if (!preg_match('/^Этап маршрута — \S+ (0|[1-9][0-9]?|100) ?%$/uD', $name, $m)) { return false; }
        return in_array($name, [sprintf(self::SHARED, (int) $m[1]), sprintf(Engine::SHARED_LEGACY, (int) $m[1])], true);
    }

    /** Только поиск записей 1.11–1.12: исторические имена и ID не переименовываем. */
    private static function legacyName(int $stage, int $attempt, int $run, int $percent): string
    {
        return str_replace(' — нужно ', ' — порог ', self::name($stage, $attempt, $run, $percent));
    }

    /**
     * Ключ из канонического названия 1.13 либо 1.11–1.12: чужие и прежняя общая
     * запись «Этап маршрута — порог P%» ключа не дают.
     *
     * @return array{stage:int,attempt:int,run:int,percent:int}|null
     */
    public static function parse(string $name): ?array
    {
        if (!preg_match('/^Этап маршрута ([1-9][0-9]{0,8})(?:, попытка ([2-9]|[1-9][0-9]{1,8}))?'
            . '(?:, запуск ([2-9]|[1-9][0-9]{1,8}))? — (?:нужно|порог) (0|[1-9][0-9]?|100) %$/uD', $name, $m)) {
            return null;
        }
        $key = ['stage' => (int) $m[1], 'attempt' => ($m[2] ?? '') !== '' ? (int) $m[2] : 1,
            'run' => ($m[3] ?? '') !== '' ? (int) $m[3] : 1, 'percent' => (int) $m[4]];
        return in_array($name, [self::name(...array_values($key)), self::legacyName(...array_values($key))], true) ? $key : null;
    }

    /**
     * Итог ступени по правилу ядра GLPI 11.0.8 (ITIL_ValidationStep::getStatus и getAchievements):
     * доли — дробью от числа запросов ступени; порог 0 % — достаточно одного «да», иначе
     * одного «нет»; «Согласовано», если доля «да» не меньше порога; «Ожидает», если округлённая
     * сумма долей «да» и «ожидает» не меньше порога; иначе «Отклонено». Пустая ступень при
     * пороге выше нуля — «Отклонено», как в ядре.
     *
     * @return int CommonITILValidation::WAITING|ACCEPTED|REFUSED
     */
    public static function status(int $accepted, int $refused, int $waiting, int $percent): int
    {
        $total = $accepted + $refused + $waiting;
        // Те же действия, что у ядра: деление, затем умножение на 100.
        $acc = $total === 0 ? 0 : $accepted / $total * 100;
        $ref = $total === 0 ? 0 : $refused / $total * 100;
        $wait = $total === 0 ? 0 : $waiting / $total * 100;
        if ($percent == 0) {
            if ($acc > 0) {
                return CommonITILValidation::ACCEPTED;
            }
            if ($ref > 0) {
                return CommonITILValidation::REFUSED;
            }
            return CommonITILValidation::WAITING;
        }
        if ($acc >= $percent) {
            return CommonITILValidation::ACCEPTED;
        }
        if (round($acc + $wait) >= $percent) {
            return CommonITILValidation::WAITING;
        }
        return CommonITILValidation::REFUSED;
    }

    /** Исход этапа по ступени: согласован, отклонён или ждём (null). */
    public static function outcome(int $accepted, int $refused, int $waiting, int $percent): ?string
    {
        return match (self::status($accepted, $refused, $waiting, $percent)) {
            CommonITILValidation::ACCEPTED => Outcome::COMPLETED,
            CommonITILValidation::REFUSED => Outcome::REFUSED,
            default => null,
        };
    }

    /**
     * Исход этапа по запросам и порогу до 1.11 (общая ступень): доли — целыми процентами
     * с отбрасыванием дробной части. Для запусков, начатых до 1.11, — без изменений.
     */
    public static function legacyOutcome(int $accepted, int $refused, int $total, int $percent): ?string
    {
        if ($total <= 0) {
            return null;
        }
        if (intdiv($accepted * 100, $total) >= $percent) {
            return Outcome::COMPLETED;
        }
        return intdiv(($total - $refused) * 100, $total) < $percent ? Outcome::REFUSED : null;
    }

    /** Запись справочника ступеней для прохода этапа: найти по названию и порогу или создать. */
    public static function ensure(int $stage, int $attempt, int $run, int $percent): int
    {
        if ($stage < 1 || $attempt < 1 || $run < 1 || $percent < 1 || $percent > 100) {
            throw new \RuntimeException('Неверные параметры этапа согласования.');
        }
        $name = self::name($stage, $attempt, $run, $percent);
        $vs = new \ValidationStep();
        // Одно чтение обоих форматов: прежний ID продолжает обслуживать тот же ключ.
        // Две записи — неоднозначность, а не повод выбрать одну и смешать согласования.
        $found = $vs->find(['name' => [$name, self::legacyName($stage, $attempt, $run, $percent)],
            'minimal_required_validation_percent' => $percent], [], 2);
        if (count($found) > 1) {
            throw new \RuntimeException('Найдено несколько одинаковых этапов согласования.');
        }
        if (count($found)) {
            return (int) array_key_first($found);
        }
        $id = (int) $vs->add(['name' => $name, 'minimal_required_validation_percent' => $percent, 'is_default' => 0]);
        if ($id <= 0) {
            throw new \RuntimeException('Не удалось создать этап согласования.');
        }
        return $id;
    }

    /**
     * Номер нового запуска маршрута на заявке: на 1 больше самого большого номера среди
     * своих ступеней, которые уже есть у заявки. Своих ступеней нет — первый запуск.
     */
    public static function nextRun(\CommonITILObject $item): int
    {
        $step_class = $item::getValidationStepInstance();
        if ($step_class === null || $item->isNewItem()) {
            return 1;
        }
        $dictionary = [];
        foreach ($step_class->find(['itemtype' => $item->getType(), 'items_id' => (int) $item->getID()]) as $row) {
            $dictionary[] = (int) $row['validationsteps_id'];
        }
        $run = 0;
        if ($dictionary !== []) {
            foreach ((new \ValidationStep())->find(['id' => $dictionary]) as $row) {
                $key = self::parse((string) $row['name']);
                if ($key !== null && (int) $row['minimal_required_validation_percent'] === $key['percent']) {
                    $run = max($run, $key['run']);
                }
            }
        }
        return $run + 1;
    }
}
