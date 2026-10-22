<?php
namespace GlpiPlugin\Itilflow;

/**
 * Чистые части строки этапа на заявке, без базы:
 * Ui и лист согласования собирают из них строку, тест проверяет их без GLPI.
 *
 * Видимость: инициатор заявки не видит строк «Не нужен по условию», вопросов
 * этапа и условий. Остальные видят «если …» у будущих условных этапов и причину у
 * «Не нужен по условию». Схема — только у администратора маршрутов.
 */
final class StepLine
{
    /**
     * Строка этапа «Не нужен по условию».
     *
     * @param array $note condition_note шага (truth, reasons, manual{choice,reason,actor,at})
     * @param string $reason ConditionText::reason — для truth = 'no'
     * @param ?string $author имя того, кто выбрал «Этап не нужен» (manual.actor), если выбирал человек
     * @param ?string $date когда
     * @return array{label:string,summary:string,author:?string,date:?string,visible:bool}
     *   summary — причина: факты условия или текст человека из manual.reason; visible — false у инициатора
     */
    public static function notNeeded(array $note, string $reason, ?string $author, ?string $date, bool $initiator): array
    {
        if ($initiator) {
            // Инициатор строку не видит; причина и автор не уходят в шаблон даже по ошибке вида.
            return ['label' => Terms::NOT_NEEDED, 'summary' => '', 'author' => null, 'date' => null, 'visible' => false];
        }
        $manual = is_array($note['manual'] ?? null) && ($note['manual']['choice'] ?? '') === 'skip' ? $note['manual'] : null;
        if ($manual !== null) {
            $text = trim((string) ($manual['reason'] ?? ''));
            // Причина — законченной фразой: в листе согласования за ней идёт «Выбрал: …».
            if ($text !== '' && !preg_match('/[.!?…]$/u', $text)) { $text .= '.'; }
            return ['label' => Terms::NOT_NEEDED,
                'summary' => 'Отмечен вручную.' . ($text !== '' ? ' Причина: ' . $text : ''),
                'author' => $author !== null && trim($author) !== '' ? trim($author) : null,
                'date' => $date !== null && trim($date) !== '' ? trim($date) : null, 'visible' => true];
        }
        return ['label' => Terms::NOT_NEEDED, 'summary' => trim($reason), 'author' => null, 'date' => null, 'visible' => true];
    }

    /**
     * Будущий этап: «Впереди» и для условного этапа — «если …» (ConditionText::short).
     *
     * @return array{label:string,summary:string}
     */
    public static function ahead(string $short, bool $initiator): array
    {
        return ['label' => Terms::AHEAD, 'summary' => $initiator ? '' : trim($short)];
    }

    /**
     * Ответы на вопросы этапа одной строкой: «Ответы этапа: … — да; … — нет ответа.»
     * Пустая строка — у этапа нет вопросов или смотрит инициатор заявки.
     *
     * @param list<array{key:string,label:string}> $items вопросы этапа
     * @param array<string,bool> $answers сохранённые ответы
     */
    public static function answers(array $items, array $answers, bool $initiator): string
    {
        if ($initiator) { return ''; }
        $out = [];
        foreach ($items as $item) {
            $value = $answers[$item['key'] ?? ''] ?? null;
            $out[] = trim((string) ($item['label'] ?? '')) . ' — ' . (is_bool($value) ? ($value ? 'да' : 'нет') : 'нет ответа');
        }
        return $out === [] ? '' : 'Ответы этапа: ' . implode('; ', $out) . '.';
    }

    /**
     * Этап не открылся: движок оставил его «Впереди», записал причину в комментарий
     * этапа и остановил маршрут. Строка говорит это простыми словами; пустая — причины нет.
     */
    public static function notStarted(string $comment): string
    {
        $comment = trim($comment);
        if ($comment === '') { return ''; }
        return 'Этап не запущен — ' . mb_strtolower(mb_substr($comment, 0, 1)) . mb_substr($comment, 1);
    }

    /**
     * Предупреждения о согласующих (нет заместителя, участник пропущен, группа недоступна)
     * нужны, пока этап впереди или идёт; у закончившегося этапа они только шум.
     */
    public static function rosterNotesVisible(string $state): bool
    {
        return in_array($state, [Step::RUNNING, Step::PENDING], true);
    }

    /**
     * Строка этапа, который ждёт ответа инициатора на уточнение. $auto — маршрут продолжится от
     * комментария инициатора сам (уточнение вернул этап согласования, Engine::answerContinues);
     * иначе продолжает исполнитель кнопкой «Продолжить». Инициатору — только то, что будет на деле;
     * просьба ответить и сам вопрос — в шапке вкладки (waitingHeader).
     */
    public static function waitingInput(bool $staff, bool $auto): string
    {
        if ($staff) {
            return $auto ? 'Маршрут продолжится сам, когда инициатор ответит комментарием.' : 'После ответа нажмите «Продолжить».';
        }
        return $auto ? 'Маршрут продолжится сам после вашего ответа.' : 'Ждём вашего ответа.';
    }

    /**
     * Название дочерней заявки этапа: «Выдача — к заявке №12345». Номер этапа и попытки — служебные,
     * людям в списке заявок они не нужны; связь с основной заявкой видна и в связанных заявках.
     */
    public static function childName(string $stage, int $parent): string
    {
        $name = trim(mb_substr(trim($stage), 0, 100));
        return ($name !== '' ? $name : 'Этап') . ' — к заявке №' . $parent;
    }

    /** Пункт меню администратора, когда начать заново можно только с одного этапа. */
    public static function restartFrom(int $ranking, string $name): string
    {
        return 'Начать заново с этапа ' . $ranking . ' «' . trim($name) . '»';
    }

    /** Пояснение, которое только повторяет метку («Согласовано» и «Согласовано.»), — пустое. */
    public static function withoutRepeat(string $label, string $summary): string
    {
        $norm = static fn(string $s): string => mb_strtolower(rtrim(trim($s), '.!… '));
        return $summary !== '' && $norm($summary) === $norm($label) ? '' : $summary;
    }

    /**
     * Шапка вкладки, пока ждём ответа инициатора: инициатору — просьба ответить и вопрос, сотруднику —
     * вопрос, который задали инициатору. $question — публичный текст уточнения (попытка, subject_summary).
     */
    public static function waitingHeader(bool $initiator, string $question): string
    {
        $q = trim(preg_replace('/\s+/u', ' ', strip_tags($question)) ?? '');
        if (mb_strlen($q) > 500) { $q = rtrim(mb_substr($q, 0, 499)) . '…'; }
        if ($initiator) {
            return 'Ответьте комментарием в заявке.' . ($q !== '' ? ' Вопрос: «' . $q . '»' : '');
        }
        return $q !== '' ? 'Вопрос инициатору: «' . $q . '»' : '';
    }

    /**
     * «Согласующие ещё не выбраны» — только у идущего этапа без запросов. У будущего этапа запросов
     * и не должно быть, а кто согласует (группа, пользователи, «укажет исполнитель») — в столбце
     * «Исполнитель».
     *
     * @return list<string>
     */
    public static function approversNotChosen(string $state): array
    {
        return $state === Step::RUNNING ? ['Согласующие ещё не выбраны.'] : [];
    }

    /**
     * Видит ли человек строку так, как её видит инициатор заявки. В основном интерфейсе — нет:
     * это исполнители и администраторы. В самообслуживании условия и причины видит только
     * согласующий этой заявки, остальные — как инициатор.
     */
    public static function asInitiator(bool $central, bool $approver): bool
    {
        return !$central && !$approver;
    }

    /**
     * Куда отправить человека, который после своего действия больше не видит заявку:
     * на его главную страницу (как после входа в GLPI), а не на страницу «нет прав».
     */
    public static function homeUrl(string $interface, string $rootDoc): string
    {
        return rtrim($rootDoc, '/') . ($interface === 'helpdesk' ? '/Helpdesk' : '/front/central.php');
    }
}
