<?php
namespace GlpiPlugin\Itilflow;

use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\FormDestination;

/**
 * Запуск маршрута по ответу формы.
 *
 * Где выбирается маршрут: в обратном вызове поля плагина после создания всех заявок
 * отправки (FormField::applyConfiguratedValueAfterDestinationCreation, production) —
 * там известны точное назначение, набор ответов и созданные заявки; всё идёт внутри
 * транзакции отправки. До создания назначение неизвестно и не угадывается.
 *
 * Правила выбора:
 * - выбор — на каждую созданную заявку, кандидаты — включённые маршруты нового порядка с
 *   start_if, у которых та же форма и назначение = назначению заявки или 0 («любое»);
 * - ровно один «да» и ни одного «неизвестно» — запуск; иначе маршрут не запускается,
 *   заявка остаётся такой, какой её создала форма (в группе назначения), администратору
 *   маршрутов — запись журнала и предупреждение в готовности. Отправку формы никогда не
 *   блокировать: исключение откатило бы все заявки отправки и дало бы ошибку 500;
 * - один путь запуска на форму в организации: если у формы есть маршрут с start_if, подходящий
 *   организации заявки, маршрут по бизнес-правилу для неё не запускается (suppressesRuleStart);
 *   в других организациях заявки той же формы запускаются правилами, как раньше;
 * - уже запущенный маршрут (правилом до 1.13-настройки, вручную) не заменяется.
 *
 * Основа: формат start_source, стыки (вызовы из Hook, FormField, Engine, FormFacts),
 * runDestination/destinationMatches.
 */
final class FormStart
{
    public const ONE = 'one';
    public const NONE = 'none';
    public const MANY = 'many';
    public const UNKNOWN = 'unknown';

    /** Одноразовое свидетельство выбора: spl_object_id(заявки) => ['object' => заявка, 'process' => id]. Только RAM. */
    private static array $pending = [];

    /**
     * Стык Hook::postItilAdd (до Engine::startFromRule). true — заявка пришла с формы, у которой
     * в организации заявки есть маршрут с «Запускать, если»: правило маршрут не запускает, выбор
     * будет в afterCreated. В других организациях заявки той же формы правила запускают как раньше.
     * Форму узнать по отметке FormField::ANSWERS_MARKER во входе и FormFacts::submittedForm().
     */
    public static function suppressesRuleStart(\CommonITILObject $item): bool
    {
        if (!$item instanceof \Ticket || !is_array($item->input)) { return false; }
        $answersId = (int) ($item->input[FormField::ANSWERS_MARKER] ?? 0);
        $formId = $answersId > 0 ? FormFacts::submittedForm($answersId) : null;
        if ($formId === null || !self::enabledFor(self::startRows($formId), $item)) { return false; }
        try {
            $form = new \Glpi\Form\Form();
            $formName = CurrentRead::load($form, $formId) ? (string) ($form->fields['name'] ?? '') : '';
            $ruleName = RuleStart::ruleName($item);
            self::journal($item, ($ruleName !== null && $ruleName !== '' ? 'Правило «' . $ruleName . '»' : 'Бизнес-правило')
                . ' не запустило маршрут: у формы' . ($formName !== '' ? ' «' . $formName . '»' : '') . ' запуск по ответу формы.');
        } catch (\Throwable) {
            // Этот вызов ещё вне FormTransactionGuard. Ошибка диагностики не разрешает
            // второй путь запуска и не отменяет отправку; потерю транзакции не скрываем.
            TransactionScope::assertHealthy();
        }
        return true;
    }

    /**
     * Стык FormField (production), отдельный FormTransactionGuard до FormAdoption/FormFacts.
     * Для каждой заявки: candidates → FormFacts::submissionFacts → select → при ONE —
     * self::$pending + Engine::startFromForm + запись start_source (sourceForForm);
     * иначе — Violation::record(null, 'Ticket', id, 'action_form_start', false, текст, организация заявки).
     *
     * @param list<\Ticket> $tickets
     */
    public static function afterCreated(FormDestination $destination, AnswersSet $answers, array $tickets): void
    {
        $formId = (int) ($answers->fields['forms_forms_id'] ?? 0);
        $rows = $formId > 0 ? self::startRows($formId) : [];
        if ($rows === []) { return; }
        TransactionScope::requireActive();
        $answersId = (int) $answers->getID();
        $destinationId = (int) $destination->getID();
        if ($answersId <= 0 || $destinationId <= 0 || FormFacts::submittedForm($answersId) !== $formId
            || (int) ($destination->fields['forms_forms_id'] ?? 0) !== $formId) {
            throw new \RuntimeException('Не удалось установить форму созданной заявки.');
        }
        $seen = [];
        foreach ($tickets as $ticket) {
            if (!$ticket instanceof \Ticket || $ticket->getID() <= 0 || isset($seen[(int) $ticket->getID()])) { continue; }
            $seen[(int) $ticket->getID()] = true;
            if (!is_array($ticket->input) || (int) ($ticket->input[FormField::ANSWERS_MARKER] ?? 0) !== $answersId) {
                throw new \RuntimeException('Не удалось установить ответы формы созданной заявки.');
            }
            // В организации заявки запуска по форме нет — заявка идёт как раньше (правилом или вручную).
            if (!self::enabledFor($rows, $ticket)) { continue; }
            $candidates = self::candidatesFrom($rows, $destinationId, $ticket);
            if (CurrentRead::find(new Instance(), ['itemtype' => 'Ticket', 'items_id' => (int) $ticket->getID()])) {
                if ($candidates) { self::journal($ticket, 'Маршрут уже запущен. Запуск по ответу формы не заменил существующий маршрут.'); }
                continue;
            }
            $definitions = []; $conflicts = [];
            foreach ($candidates as $candidate) {
                foreach ($candidate['start']['rule']['clauses'] ?? [] as $clause) {
                    if (!is_array($clause) || ($clause['source'] ?? '') !== 'form_answer') { continue; }
                    $uuid = $clause['question_uuid'] ?? null; $definition = $clause['question'] ?? null;
                    if (!is_string($uuid) || $uuid === '' || !is_array($definition)) { continue; }
                    if (isset($definitions[$uuid]) && (($definitions[$uuid]['id'] ?? null) !== ($definition['id'] ?? null)
                        || ($definitions[$uuid]['uuid'] ?? null) !== ($definition['uuid'] ?? null)
                        || FormFacts::validateMapping($definitions[$uuid], $definition) !== []
                        || FormFacts::validateMapping($definition, $definitions[$uuid]) !== [])) { $conflicts[$uuid] = true; }
                    $definitions[$uuid] = $definition;
                }
            }
            foreach ($conflicts as $uuid => $_) { $definitions[$uuid]['supported'] = false; }
            if ($candidates === []) {
                $name = trim((string) ($destination->fields['name'] ?? ''));
                self::journal($ticket, 'Маршрут не запущен: у назначения формы' . ($name !== '' ? ' «' . $name . '»' : '')
                    . ' нет маршрута с запуском по ответу формы.');
                continue;
            }
            $facts = FormFacts::submissionFacts($answers, $definitions);
            $selection = self::select($candidates, $facts);
            if ($selection['kind'] !== self::ONE) {
                self::journal($ticket, self::selectionMessage($selection, $candidates, $definitions, $facts));
                continue;
            }
            $processId = $selection['process_id'];
            $process = new Process();
            if (!CurrentRead::load($process, $processId)) { throw new \RuntimeException('Выбранный маршрут недоступен.'); }
            $truths = [];
            foreach ($candidates as $candidate) {
                $truths[] = ['id' => $candidate['id'], 'truth' => in_array($candidate['id'], $selection['yes'], true)
                    ? 'yes' : (in_array($candidate['id'], $selection['unknown'], true) ? 'unknown' : 'no')];
            }
            $source = self::sourceForForm($formId, $destinationId, $answersId, $processId,
                (string) $process->fields['start_if'], $truths, $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
            $oid = spl_object_id($ticket);
            self::$pending[$oid] = ['object' => $ticket, 'process' => $processId, 'form' => $formId,
                'answers' => $answersId, 'signature' => self::signature($ticket->fields)];
            try { $instance = Engine::startFromForm($ticket); }
            finally { unset(self::$pending[$oid]); }
            if ($instance !== null && (int) ($instance->fields['process_version'] ?? 0) !== (int) $process->fields['version']) {
                throw new \RuntimeException('Версия запущенного маршрута отличается от выбранной.');
            }
            if ($instance === null || !$instance->update(['id' => $instance->getID(), 'start_source' => DefinitionSnapshot::encode($source)])) {
                throw new \RuntimeException('Не удалось сохранить запуск по ответу формы.');
            }
        }
    }

    /**
     * Восстановление после отката своей части обратного вызова: только журнал, без запуска.
     * Не бросает: исключение здесь FormTransactionGuard считает потерей транзакции и откатывает
     * всю отправку формы (ошибка 500 у инициатора). Запись журнала — только для администратора.
     */
    public static function failed(FormDestination $destination, AnswersSet $answers, array $tickets, \Throwable $error): void
    {
        $seen = [];
        foreach ($tickets as $ticket) {
            if (!$ticket instanceof \Ticket || $ticket->getID() <= 0 || isset($seen[(int) $ticket->getID()])) { continue; }
            $seen[(int) $ticket->getID()] = true;
            unset(self::$pending[spl_object_id($ticket)]);
            try {
                self::journal($ticket, 'Маршрут не запущен. Заявка сохранена — проверьте «Запускать, если».');
            } catch (\Throwable) {
                // Потерянную транзакцию не скрываем; несохранённая запись журнала отправку не отменяет.
                TransactionScope::assertHealthy();
            }
        }
    }

    /**
     * Кандидаты для назначения: list<array{id:int,name:string,start:array}> — start из StartRule::decode.
     * Организация маршрута должна подходить заявке (Authorizer::processFitsItem).
     *
     * @return list<array{id:int,name:string,start:array}>
     */
    public static function candidates(int $formId, int $destinationId, \Ticket $ticket): array
    {
        if ($formId <= 0 || $destinationId <= 0) { return []; }
        return self::candidatesFrom(self::startRows($formId), $destinationId, $ticket);
    }

    /**
     * Чистый отбор кандидатов из маршрутов формы (startRows): назначение заявки или «любое»,
     * организация маршрута подходит заявке.
     *
     * @return list<array{id:int,name:string,start:array}>
     */
    public static function candidatesFrom(array $rows, int $destinationId, \Ticket $ticket): array
    {
        if ($destinationId <= 0) { return []; }
        $out = [];
        foreach ($rows as $row) {
            $start = StartRule::decode($row['start_if'] ?? null);
            if ($start === null || !in_array((int) ($row['forms_destinations_id'] ?? -1), [0, $destinationId], true)) { continue; }
            if (!self::fits($row, $ticket)) { continue; }
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'start' => $start];
        }
        return $out;
    }

    /**
     * Один путь запуска на форму — в организации заявки: правило
     * подавляется, только если у формы есть маршрут с «Запускать, если», организация которого
     * подходит заявке. Чистая — по строкам startRows.
     */
    public static function enabledFor(array $rows, \CommonITILObject $item): bool
    {
        foreach ($rows as $row) {
            if (StartRule::isOn($row) && self::fits($row, $item)) { return true; }
        }
        return false;
    }

    /** Организация маршрута подходит заявке — как при запуске (Authorizer::processFitsItem). */
    private static function fits(array $row, \CommonITILObject $item): bool
    {
        $process = new Process(); $process->fields = $row;
        return Authorizer::processFitsItem($process, $item);
    }

    /**
     * Чистый выбор без базы.
     *
     * @param list<array{id:int,name:string,start:array}> $candidates
     * @param array $facts конверт FormFacts (status, facts, …)
     * @return array{kind:string,process_id:?int,yes:list<int>,unknown:list<int>}
     *   kind: ONE — ровно один «да» и ни одного «неизвестно»; MANY — «да» больше одного;
     *   UNKNOWN — есть «неизвестно» (даже рядом с одним «да»); NONE — кандидатов нет или все «нет».
     */
    public static function select(array $candidates, array $facts): array
    {
        $yes = []; $unknown = [];
        foreach ($candidates as $candidate) {
            $truth = StartRule::evaluate($candidate['start'], $facts)['truth'] ?? 'unknown';
            if ($truth === 'yes') { $yes[] = $candidate['id']; }
            elseif ($truth !== 'no') { $unknown[] = $candidate['id']; }
        }
        $kind = count($yes) > 1 ? self::MANY : ($unknown ? self::UNKNOWN : ($yes ? self::ONE : self::NONE));
        return ['kind' => $kind, 'process_id' => $kind === self::ONE ? $yes[0] : null, 'yes' => $yes, 'unknown' => $unknown];
    }

    /** Engine::startFromForm берёт выбор один раз; чужая или повторная заявка — null. */
    public static function consume(\CommonITILObject $item): ?int
    {
        $oid = spl_object_id($item);
        $proof = self::$pending[$oid] ?? null;
        unset(self::$pending[$oid]);
        if (!$item instanceof \Ticket || !is_array($proof) || ($proof['object'] ?? null) !== $item
            || !is_array($item->input) || (int) ($item->input[FormField::ANSWERS_MARKER] ?? 0) !== $proof['answers']
            || FormFacts::submittedForm($proof['answers']) !== $proof['form']
            || self::signature($item->fields) !== $proof['signature']) { return null; }
        $fresh = new \Ticket();
        if (!CurrentRead::load($fresh, (int) $item->getID()) || self::signature($fresh->fields) !== $proof['signature']) { return null; }
        return $proof['process'];
    }

    private static function signature(array $fields): string
    {
        $values = [];
        foreach (['id', 'entities_id', 'status', 'is_deleted', 'name', 'content'] as $key) { $values[$key] = (string) ($fields[$key] ?? ''); }
        return hash('sha256', serialize($values));
    }

    /** Включённые маршруты нового порядка этой формы с «Запускать, если» — во всех организациях. */
    private static function startRows(int $formId): array
    {
        $out = [];
        foreach (CurrentRead::find(new Process(), ['is_active' => 1, 'itemtype' => 'Ticket',
            'outcome_contract' => DefinitionSnapshot::MODE, 'forms_forms_id' => $formId]) as $row) {
            if (StartRule::isOn($row)) { $out[] = $row; }
        }
        return $out;
    }

    private static function journal(\Ticket $ticket, string $message): void
    {
        if (!Violation::record(null, 'Ticket', (int) $ticket->getID(), 'action_form_start', false, $message,
            (int) ($ticket->fields['entities_id'] ?? 0))) { throw new \RuntimeException('Не удалось записать результат запуска маршрута.'); }
    }

    private static function selectionMessage(array $selection, array $candidates, array $definitions, array $facts): string
    {
        if ($selection['kind'] === self::MANY) {
            $names = [];
            foreach ($candidates as $candidate) {
                if (in_array($candidate['id'], $selection['yes'], true)) { $names[] = '«' . $candidate['name'] . '»'; }
            }
            return 'Маршрут не запущен: подошли несколько маршрутов: ' . implode(', ', $names) . '.';
        }
        if ($selection['kind'] === self::UNKNOWN) {
            $names = [];
            foreach ($candidates as $candidate) {
                if (!in_array($candidate['id'], $selection['unknown'], true)) { continue; }
                foreach ($candidate['start']['rule']['clauses'] ?? [] as $clause) {
                    $uuid = $clause['question_uuid'] ?? '';
                    if (($facts['facts'][$uuid]['status'] ?? '') === 'known') { continue; }
                    $name = $definitions[$uuid]['label'] ?? '';
                    if ($name !== '') { $names[$name] = '«' . $name . '»'; }
                }
            }
            return $names ? 'Маршрут не запущен: неизвестны ответы на ' . implode(', ', $names) . '.'
                : 'Маршрут не запущен: не удалось определить условия запуска. Администратору маршрутов нужно проверить настройку.';
        }
        return 'Маршрут не запущен: не подошёл ни один маршрут по ответам формы.';
    }

    /**
     * Формат instances.start_source для запуска по ответу формы (version 1):
     * {"version":1,"kind":"form","form_id":F,"destination_id":D,"answerset_id":A,
     *  "process_id":P,"start_if_sha256":"…","candidates":[{"id":P,"truth":"yes|no|unknown"}],"at":"Y-m-d H:i:s"}
     */
    public static function sourceForForm(int $formId, int $destinationId, int $answersetId, int $processId,
        string $startIf, array $candidates, string $at): array
    {
        return ['version' => 1, 'kind' => 'form', 'form_id' => $formId, 'destination_id' => $destinationId,
            'answerset_id' => $answersetId, 'process_id' => $processId, 'start_if_sha256' => hash('sha256', $startIf),
            'candidates' => array_values($candidates), 'at' => $at];
    }

    /**
     * Назначение формы, ответы и согласование которого относятся к этому запуску.
     * Запуск по форме — фактическое назначение из start_source. Иначе — назначение маршрута из
     * снимка (как в 1.11–1.12); 0 — назначение не задано («любое»).
     */
    public static function runDestination(array $snapshotProcess, mixed $startSource): int
    {
        $source = is_string($startSource) ? json_decode($startSource, true) : $startSource;
        if (is_array($source) && ($source['version'] ?? null) === 1 && ($source['kind'] ?? '') === 'form'
            && is_int($source['destination_id'] ?? null) && $source['destination_id'] > 0) {
            return $source['destination_id'];
        }
        return (int) ($snapshotProcess['forms_destinations_id'] ?? 0);
    }

    /** Правило FormFacts::afterCreated 1.12 («0 — любое назначение») с назначением запуска по форме. */
    public static function destinationMatches(array $snapshotProcess, mixed $startSource, int $destinationId): bool
    {
        $run = self::runDestination($snapshotProcess, $startSource);
        return $run === 0 || $run === $destinationId;
    }
}
