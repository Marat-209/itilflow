<?php
namespace GlpiPlugin\Itilflow;

use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\AnswersSet_FormDestinationItem;

/** Только типизированные факты используемых вопросов; HTML-ответы и полный опросник не сохраняются. */
final class FormFacts
{
    private static array $submissions = [];
    private const NS = 'Glpi\\Form\\QuestionType\\';

    /**
     * Вопросы формы по uuid. $forEditor — ещё раздел (section) и вид вопроса словами GLPI
     * (type_label) для «Что проверяем»; в снимок условия эти поля не попадают (freezeDefinitions).
     */
    public static function questionCatalog(int $formId, bool $checkAccess = true, bool $forEditor = false): array
    {
        $form = new Form();
        if ($formId <= 0 || !$form->getFromDB($formId) || ($checkAccess && !$form->can($formId, READ))) { return []; }
        $out = []; $sections = [];
        foreach ($form->getQuestions() as $question) {
            $d = self::describeQuestion($question);
            $section = $question->getSection();
            $d['conditional'] = $d['conditional'] || ($section !== null
                && !empty(json_decode((string) ($section->fields['conditions'] ?? '[]'), true)));
            if ($forEditor) {
                $sid = $section !== null ? (int) $section->getID() : 0;
                if (!isset($sections[$sid])) {
                    $title = $section !== null ? trim((string) ($section->fields['name'] ?? '')) : '';
                    $sections[$sid] = $title !== '' ? $title : 'Раздел' . ($sections ? ' ' . (count($sections) + 1) : '');
                }
                $d['section'] = $sections[$sid];
                $d['type_label'] = self::typeLabel($d);
            }
            if ($d['uuid'] !== '') { $out[$d['uuid']] = $d; }
        }
        return $out;
    }

    /** Вид вопроса словами GLPI («краткий ответ», «дата и время»); иначе — простым словом. */
    private static function typeLabel(array $d): string
    {
        $native = (string) ($d['native_type'] ?? '');
        if (str_starts_with($native, self::NS) && class_exists($native)) {
            try {
                $name = trim((string) (new $native())->getName());
                if ($name !== '') { return mb_strtolower(mb_substr($name, 0, 1)) . mb_substr($name, 1); }
            } catch (\Throwable $e) { TransactionScope::keepGoing($e); }
        }
        return ['text' => 'текст', 'file' => 'файл', 'date' => 'дата', 'number' => 'число', 'bool' => 'флажок',
            'choice' => 'выбор', 'ref' => 'объект'][$d['type'] ?? ''] ?? 'вопрос';
    }

    public static function describeQuestion(Question $question): array
    {
        $native = (string) ($question->fields['type'] ?? '');
        $d = ['id' => (int) ($question->fields['id'] ?? 0), 'uuid' => (string) ($question->fields['uuid'] ?? ''),
            'label' => (string) ($question->fields['name'] ?? ''), 'native_type' => $native,
            'type' => 'unsupported', 'multiple' => false, 'options' => [], 'itemtype' => null,
            'supported' => false, 'reason' => 'unsupported_type',
            'required' => !empty($question->fields['is_mandatory']),
            'conditional' => !empty(json_decode((string) ($question->fields['conditions'] ?? '[]'), true))];
        // Не создаём произвольный класс из настройки стороннего типа вопроса.
        $short = str_starts_with($native, self::NS) ? substr($native, strlen(self::NS)) : '';
        $allowed = ['QuestionTypeNumber', 'QuestionTypeCheckbox', 'QuestionTypeDropdown', 'QuestionTypeRadio',
            'QuestionTypeItem', 'QuestionTypeItemDropdown', 'QuestionTypeRequester', 'QuestionTypeAssignee', 'QuestionTypeObserver',
            'QuestionTypeShortText', 'QuestionTypeLongText', 'QuestionTypeEmail', 'QuestionTypeFile', 'QuestionTypeDateTime'];
        if (!in_array($short, $allowed, true)) { return $d; }
        try {
            $type = new $native();
            if ($short === 'QuestionTypeNumber') { $d['type'] = 'number'; }
            // 1.13: краткий и долгий ответ, файл — только «заполнено / приложен»; содержимое не хранится.
            elseif (in_array($short, ['QuestionTypeShortText', 'QuestionTypeLongText', 'QuestionTypeEmail'], true)) { $d['type'] = 'text'; }
            elseif ($short === 'QuestionTypeFile') { $d['type'] = 'file'; }
            elseif ($short === 'QuestionTypeDateTime') {
                // Только время без даты не сравнить с днём подачи.
                if (!$type->isDateEnabled($question)) { $d['reason'] = 'time_only'; return $d; }
                $d['type'] = 'date';
            }
            elseif (in_array($short, ['QuestionTypeCheckbox', 'QuestionTypeDropdown', 'QuestionTypeRadio'], true)) {
                foreach ($type->getOptions($question) as $key => $label) {
                    if (!is_scalar($label)) { return $d; }
                    $d['options'][] = ['key' => (string) $key, 'label' => (string) $label];
                }
                $d['type'] = $short === 'QuestionTypeCheckbox' && count($d['options']) === 1 ? 'bool' : 'choice';
                $d['multiple'] = $short === 'QuestionTypeCheckbox'
                    || ($short === 'QuestionTypeDropdown' && $type->isMultipleDropdown($question));
            } else {
                $d['type'] = 'ref';
                if ($type instanceof \Glpi\Form\QuestionType\AbstractQuestionTypeActors) {
                    // Смешанный выбор сотрудников/групп не имеет одного типа для условия.
                    $extra = json_decode((string) ($question->fields['extra_data'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($extra)) { return $d; }
                    $types = array_values(array_filter($type->getAllowedActorTypes(),
                        static fn(string $actor): bool => $type->isTypeEnabled($question, $actor)));
                    $d['multiple'] = $type->isMultipleActors($question);
                    if ($d['multiple'] || count($types) !== 1 || !in_array($types[0], ['User', 'Group'], true)) {
                        $d['reason'] = 'ambiguous_actor_type'; return $d;
                    }
                    $d['itemtype'] = $types[0];
                } elseif (method_exists($type, 'getDefaultValueItemtype')) {
                    $d['itemtype'] = $type->getDefaultValueItemtype($question);
                    if (!in_array($d['itemtype'], ['User', 'Group', 'ITILCategory'], true)) { return $d; }
                }
            }
            $d['supported'] = $d['id'] > 0 && $d['uuid'] !== '';
            $d['reason'] = $d['supported'] ? null : 'invalid_question_identity';
        } catch (\Throwable $e) { TransactionScope::keepGoing($e); $d['reason'] = 'invalid_question_config'; }
        return $d;
    }

    public static function freezeDefinitions(int $formId, array $uuids, bool $checkAccess = false): array
    {
        $catalog = self::questionCatalog($formId, $checkAccess); $out = []; $errors = [];
        foreach (array_unique($uuids) as $uuid) {
            if (!is_string($uuid) || !isset($catalog[$uuid]) || !$catalog[$uuid]['supported']) {
                $errors[] = 'Вопрос формы недоступен или его тип не поддерживается.'; continue;
            }
            $out[$uuid] = $catalog[$uuid];
        }
        return ['definitions' => $out, 'errors' => array_values(array_unique($errors))];
    }

    public static function validateMapping(array $imported, array $target, array $explicitMap = []): array
    {
        if (empty($target['supported'])) { return ['Тип вопроса назначения не поддерживается.']; }
        foreach (['native_type', 'type', 'multiple', 'options', 'itemtype'] as $field) {
            if (($imported[$field] ?? null) !== ($target[$field] ?? null)) { return ['Тип или варианты вопроса изменились.']; }
        }
        if (($imported['uuid'] ?? '') !== ($target['uuid'] ?? '')
            && ($explicitMap[$imported['uuid'] ?? ''] ?? null) !== ($target['uuid'] ?? null)) {
            return ['Нужно явно сопоставить вопрос формы.'];
        }
        return [];
    }

    /** Вызывается штатным getConfig с тем Form, которым ядро сформировало AnswersSet. Только RAM. */
    public static function rememberSubmission(Form $form, AnswersSet $answers): void
    {
        TransactionScope::assertHealthy();
        $id = (int) $answers->getID();
        if ($id <= 0 || (int) ($answers->fields['forms_forms_id'] ?? 0) !== (int) $form->getID() || isset(self::$submissions[$id])) { return; }
        $saving = $creating = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['object'] ?? null) instanceof \Glpi\Form\AnswersHandler\AnswersHandler) {
                $saving = $saving || ($frame['function'] ?? '') === 'doSaveAnswers';
                $creating = $creating || ($frame['function'] ?? '') === 'createDestinations';
            }
        }
        // Старый AnswersSet, переданный вручную в getConfig, не превращает текущие labels в исторические.
        if (!$saving || !$creating) { return; }
        $metadata = []; $visible = []; $answersDigest = null;
        // Модель условий ядра получает подготовленные ответы так же, как createDestinations.
        try {
            $engine = new \Glpi\Form\Condition\Engine($form, new \Glpi\Form\Condition\EngineInput(answers: $answers->toArray()));
            $visibility = $engine->computeVisibility();
            foreach ($form->getQuestions() as $q) {
                $d = self::describeQuestion($q); $metadata[$d['uuid']] = $d;
                $section = $q->getSection();
                $visible[$d['uuid']] = $section !== null && $visibility->isQuestionVisible($q->getID())
                    && $visibility->isSectionVisible($section->getID());
            }
            $answersDigest = self::answersDigest($answers);
        } catch (\Throwable $e) { TransactionScope::keepGoing($e); $visible = []; }
        self::$submissions[$id] = ['form_id' => (int) $form->getID(), 'metadata' => $metadata, 'visible' => $visible,
            'answers_digest' => $answersDigest];
    }

    public static function definitions(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot['stages'] ?? [] as $stage) {
            $run = $stage['run_if'] ?? null;
            if (is_string($run)) { $run = json_decode($run, true); }
            foreach (is_array($run) ? ($run['clauses'] ?? []) : [] as $clause) {
                if (($clause['source'] ?? '') !== 'form_answer') { continue; }
                $uuid = (string) ($clause['question_uuid'] ?? ''); $d = $clause['question'] ?? null;
                if ($uuid !== '' && is_array($d)) {
                    if (isset($out[$uuid]) && $out[$uuid] !== $d) { $d['supported'] = false; }
                    $out[$uuid] = $d;
                }
            }
        }
        return $out;
    }

    public static function load(Instance $instance): array
    {
        $v = json_decode((string) ($instance->fields['form_facts'] ?? ''), true);
        return is_array($v) && ($v['version'] ?? null) === 1 && in_array($v['status'] ?? '', ['pending', 'final'], true)
            && is_array($v['facts'] ?? null) ? $v : [];
    }
    public static function get(Instance $instance): array { return self::load($instance); }
    public static function ensureForStart(Instance $instance, \CommonITILObject $item): void { self::forStart($instance, $item); }

    public static function forStart(Instance $instance, \CommonITILObject $item): array
    {
        TransactionScope::requireActive();
        if (($stored = self::load($instance)) !== []) { return $stored; }
        $snapshot = DefinitionSnapshot::load($instance); $definitions = self::definitions($snapshot ?? []);
        $formId = (int) ($snapshot['process']['forms_forms_id'] ?? 0);
        $marker = (int) ($item->input[FormField::ANSWERS_MARKER] ?? 0);
        $capture = self::$submissions[$marker] ?? null;
        $source = ['mode' => 'none', 'form_id' => $formId, 'answerset_id' => 0, 'ticket_id' => (int) $item->getID(), 'destination_id' => null];
        if ($item instanceof \Ticket && $capture !== null && $capture['form_id'] === $formId) {
            $source['mode'] = 'callback'; $source['answerset_id'] = $marker;
            return self::save($instance, self::envelope('pending', $source, self::unknowns($definitions, 'pending_source')));
        }
        if (!$item instanceof \Ticket || $formId <= 0) { return self::save($instance, self::envelope('final', $source, self::unknowns($definitions, 'no_form'))); }
        $links = CurrentRead::find(new AnswersSet_FormDestinationItem(), ['itemtype' => 'Ticket', 'items_id' => (int) $item->getID()]);
        if (count($links) !== 1) { return self::save($instance, self::envelope('final', $source, self::unknowns($definitions, $links ? 'ambiguous_source' : 'no_form'))); }
        $link = reset($links); $answers = new AnswersSet();
        if (!CurrentRead::load($answers, (int) $link['forms_answerssets_id']) || (int) $answers->fields['forms_forms_id'] !== $formId) {
            return self::save($instance, self::envelope('final', $source, self::unknowns($definitions, 'source_mismatch')));
        }
        $source['mode'] = 'late_unique'; $source['answerset_id'] = (int) $answers->getID();
        $source['submitted_at'] = self::submittedAt($answers);
        return self::save($instance, self::envelope('final', $source,
            self::buildFacts($definitions, $answers, self::questionCatalog($formId, false), [], false)));
    }

    /**
     * Форма текущей отправки по отметке FormField::ANSWERS_MARKER во входе
     * заявки — только для набора ответов, который ядро сохраняет в этом запросе. null — не форма.
     */
    public static function submittedForm(int $answersetId): ?int
    {
        $capture = self::$submissions[$answersetId] ?? null;
        return $capture !== null ? (int) $capture['form_id'] : null;
    }

    /**
     * Факты отправки для «Запускать, если» — до запуска, без записи в базу:
     * те же правила, что у FormFacts::afterCreated (видимость из отправки, неизвестное не
     * становится «нет»). $definitions — вопросы условий кандидатов (снимки из start_if.rule).
     * Без подтверждённой отправки — все «неизвестно» с причиной submission_unproven.
     */
    public static function submissionFacts(AnswersSet $answers, array $definitions): array
    {
        TransactionScope::assertHealthy();
        $form = (int) ($answers->fields['forms_forms_id'] ?? 0);
        $capture = self::$submissions[(int) $answers->getID()] ?? null;
        $source = ['mode' => 'callback', 'form_id' => $form, 'answerset_id' => (int) $answers->getID(),
            'ticket_id' => null, 'destination_id' => null, 'submitted_at' => self::submittedAt($answers)];
        $facts = self::submissionMatches($capture, $form, self::answersDigest($answers))
            ? self::buildFacts($definitions, $answers, $capture['metadata'], $capture['visible'], true)
            : self::unknowns($definitions, 'submission_unproven');
        return self::envelope('final', $source, $facts);
    }

    public static function afterCreated(FormDestination $destination, AnswersSet $answers, array $tickets): void
    {
        TransactionScope::requireActive();
        foreach ($tickets as $ticket) {
            if (!$ticket instanceof \Ticket) { continue; }
            foreach (CurrentRead::find(new Instance(), ['itemtype' => 'Ticket', 'items_id' => (int) $ticket->getID()]) as $row) {
                $instance = new Instance(); $instance->fields = $row; $snapshot = DefinitionSnapshot::load($instance);
                if ($snapshot === null || (int) ($snapshot['schema'] ?? 0) < 3) { continue; }
                $stored = self::load($instance);
                if (($stored['status'] ?? '') === 'final') { continue; }
                $defs = self::definitions($snapshot); $form = (int) ($snapshot['process']['forms_forms_id'] ?? 0);
                $source = ['mode' => 'callback', 'form_id' => $form, 'answerset_id' => (int) $answers->getID(),
                    'ticket_id' => (int) $ticket->getID(), 'destination_id' => (int) $destination->getID(),
                    'submitted_at' => self::submittedAt($answers)];
                $capture = self::$submissions[(int) $answers->getID()] ?? null;
                $proof = self::submissionMatches($capture, $form, self::answersDigest($answers))
                    && (int) $answers->fields['forms_forms_id'] === $form && (int) $destination->fields['forms_forms_id'] === $form
                    // У запуска по ответу формы — фактическое назначение запуска.
                    && FormStart::destinationMatches($snapshot['process'], $row['start_source'] ?? null, (int) $destination->getID())
                    && ($stored['status'] ?? '') === 'pending'
                    && (int) ($stored['source']['form_id'] ?? 0) === $form
                    && (int) ($stored['source']['ticket_id'] ?? 0) === (int) $ticket->getID()
                    && (int) ($stored['source']['answerset_id'] ?? 0) === (int) $answers->getID();
                $facts = $proof ? self::buildFacts($defs, $answers, $capture['metadata'], $capture['visible'], true)
                    : self::unknowns($defs, 'submission_unproven');
                self::save($instance, self::envelope('final', $source, $facts));
                Engine::resumeFormFacts($instance);
            }
        }
    }

    public static function failed(FormDestination $destination, AnswersSet $answers, array $tickets): void
    {
        TransactionScope::requireActive();
        foreach ($tickets as $ticket) {
            foreach (CurrentRead::find(new Instance(), ['itemtype' => 'Ticket', 'items_id' => (int) $ticket->getID()]) as $row) {
                $i = new Instance(); $i->fields = $row; $s = self::load($i);
                if (($s['status'] ?? '') !== 'pending' || (int) ($s['source']['answerset_id'] ?? 0) !== (int) $answers->getID()) { continue; }
                $failed = self::envelope('final', $s['source'], self::unknowns(self::definitions(DefinitionSnapshot::load($i) ?? []), 'callback_failed'));
                $failed['recovery_required'] = true;
                self::save($i, $failed);
            }
        }
    }

    /** Доказательство относится к наблюдённой отправке, а не к именам установленных плагинов. */
    public static function submissionMatches(?array $capture, int $form, string $answersDigest): bool
    {
        TransactionScope::assertHealthy();
        return $form > 0 && $capture !== null && ($capture['form_id'] ?? null) === $form
            && is_array($capture['metadata'] ?? null) && is_array($capture['visible'] ?? null)
            && is_string($capture['answers_digest'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $capture['answers_digest']) === 1
            && hash_equals($capture['answers_digest'], $answersDigest);
    }

    /** Сырые значения используются только для отпечатка в RAM, без записи полного опросника. */
    private static function answersDigest(AnswersSet $answers): string
    {
        $rows = [];
        foreach ($answers->getAnswers() as $answer) {
            $rows[] = [(int) $answer->getQuestionId(), $answer->getRawType(), $answer->getRawAnswer()];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp(DefinitionSnapshot::encode($a), DefinitionSnapshot::encode($b)));
        return hash('sha256', DefinitionSnapshot::encode($rows));
    }

    private static function buildFacts(array $defs, AnswersSet $answers, array $metadata, array $visible, bool $historical): array
    {
        $rows = []; foreach ($answers->getAnswers() as $answer) { $rows[$answer->getQuestionId()][] = $answer; }
        $facts = [];
        foreach ($defs as $uuid => $d) {
            $list = $rows[(int) ($d['id'] ?? 0)] ?? [];
            $fact = self::unknown($d, count($list) > 1 ? 'duplicate_answer' : 'missing_answer');
            if (count($list) === 1) {
                $a = $list[0];
                $fact = self::normalize($d, $a->getRawType(), $a->getRawAnswer(), $metadata[$uuid] ?? null,
                    $historical, $visible[$uuid] ?? null);
            } elseif ($list === [] && $historical && in_array($d['type'] ?? '', self::EMPTY_WHEN_ABSENT, true)) {
                // Видимый в отправке вопрос без строки ответа — пустой ответ: так ядро сохраняет
                // файл без вложения. Видимость и снимок вопроса проверяет normalize.
                $fact = self::normalize($d, (string) ($d['native_type'] ?? ''), null, $metadata[$uuid] ?? null,
                    $historical, $visible[$uuid] ?? null);
            }
            $facts[$uuid] = $fact;
        }
        return $facts;
    }

    /** Чистая нормализация: неизвестное не становится ложью или пустым выбором. */
    public static function normalize(array $d, string $native, mixed $raw, ?array $observed, bool $historical, ?bool $visible): array
    {
        if (empty($d['supported']) || $observed === null || ($d['uuid'] ?? '') !== ($observed['uuid'] ?? '')
            || (int) ($d['id'] ?? 0) !== (int) ($observed['id'] ?? 0)) { return self::unknown($d, 'question_unproven'); }
        if ($native !== ($d['native_type'] ?? '') || self::validateMapping($d, $observed) !== []) { return self::unknown($d, 'definition_changed'); }
        if ($historical && $visible !== true) { return self::unknown($d, 'visibility_unproven'); }
        $value = null; $type = $d['type'];
        if (in_array($type, self::EMPTY_WHEN_ABSENT, true)) {
            // Пустой ответ известен, только когда вопрос был виден: скрытый вопрос ответа не имеет.
            if ($visible === false) { return self::unknown($d, 'visibility_unproven'); }
            if (self::emptyAnswer($type, $raw)) {
                if ($visible !== true) { return self::unknown($d, 'visibility_unproven'); }
                return ['status' => 'known', 'type' => $type, 'value' => in_array($type, ['text', 'file'], true) ? false : null, 'reason' => null];
            }
        }
        if ($type === 'text') {
            // Только «заполнено»: текст ответа не хранится.
            if (!is_string($raw)) { return self::unknown($d, 'invalid_text'); }
            $value = true;
        } elseif ($type === 'file') {
            if (!is_array($raw) || !array_is_list($raw)) { return self::unknown($d, 'invalid_file'); }
            $value = true;
        } elseif ($type === 'date') {
            $value = self::answerDate($raw);
            if ($value === null) { return self::unknown($d, 'invalid_date'); }
        } elseif ($type === 'number') {
            $value = self::decimal($raw);
            if ($value === null) { return self::unknown($d, 'invalid_number'); }
        } elseif ($type === 'ref') {
            if (is_array($raw) && array_is_list($raw) && count($raw) === 1) { $raw = $raw[0]; }
            if (!is_array($raw) || !in_array($raw['itemtype'] ?? '', ['User', 'Group', 'ITILCategory'], true)
                || (!empty($d['itemtype']) && $raw['itemtype'] !== $d['itemtype'])
                || !is_int($raw['items_id'] ?? null) && !(is_string($raw['items_id'] ?? null) && preg_match('/^[1-9][0-9]*$/D', $raw['items_id']))) {
                return self::unknown($d, 'invalid_reference');
            }
            $id = filter_var($raw['items_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) { return self::unknown($d, 'invalid_reference'); }
            $value = ['itemtype' => $raw['itemtype'], 'id' => $id];
        } elseif ($type === 'choice' || $type === 'bool') {
            // При позднем запуске validateMapping выше сверяет ключи и подписи
            // со снимком назначения. Непустой сохранённый выбор можно прочитать;
            // пустой ответ без доказанной видимости не означает «нет».
            if ($visible === false) { return self::unknown($d, 'visibility_unproven'); }
            $keys = array_column($d['options'], 'key');
            $values = is_array($raw) && array_is_list($raw) ? $raw : (is_string($raw) || is_int($raw) ? [$raw] : null);
            if ($values === null) { return self::unknown($d, 'invalid_choice'); }
            if ($values === [] && $visible !== true) { return self::unknown($d, 'visibility_unproven'); }
            $selected = [];
            foreach ($values as $v) {
                if ((!is_string($v) && !is_int($v)) || !in_array((string) $v, $keys, true) || in_array((string) $v, $selected, true)) { return self::unknown($d, 'invalid_choice'); }
                $selected[] = (string) $v;
            }
            if ($type === 'bool') {
                if (count($keys) !== 1 || count($selected) > 1) { return self::unknown($d, 'invalid_boolean'); }
                $value = $selected !== [];
            } else {
                if (empty($d['multiple']) && count($selected) !== 1) { return self::unknown($d, 'invalid_choice'); }
                sort($selected, SORT_STRING); $value = !empty($d['multiple']) ? $selected : $selected[0];
            }
        } else { return self::unknown($d, 'unsupported_type'); }
        return ['status' => 'known', 'type' => $type, 'value' => $value, 'reason' => null];
    }

    /** Виды, у которых пустой ответ — тоже ответ: «не заполнено», «не приложен». */
    private const EMPTY_WHEN_ABSENT = ['text', 'file', 'date', 'ref'];

    private static function emptyAnswer(string $type, mixed $raw): bool
    {
        if ($raw === null || $raw === '' || $raw === []) { return true; }
        if ($type === 'text' && is_string($raw)) {
            $plain = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return trim($plain) === '';
        }
        if ($type === 'date' && is_string($raw)) { return trim($raw) === ''; }
        if ($type === 'ref' && is_array($raw)) {
            $one = array_is_list($raw) && count($raw) === 1 ? $raw[0] : $raw;
            return is_array($one) && !array_is_list($one) && in_array($one['items_id'] ?? null, [0, '0', '', null], true);
        }
        return false;
    }

    /** Дата ответа формы — строкой Y-m-d H:i:s; ядро присылает «Y-m-d», «Y-m-d H:i» или «Y-m-dTH:i». */
    public static function answerDate(mixed $raw): ?string
    {
        if (!is_string($raw)) { return null; }
        $raw = trim($raw);
        foreach (['!Y-m-d' => '/^\d{4}-\d{2}-\d{2}$/D', '!Y-m-d H:i' => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D',
            '!Y-m-d\TH:i' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', '!Y-m-d H:i:s' => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',
            '!Y-m-d\TH:i:s' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D'] as $format => $pattern) {
            if (preg_match($pattern, $raw) !== 1) { continue; }
            $d = \DateTimeImmutable::createFromFormat($format, $raw, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) { return null; }
            return $d->format('Y-m-d H:i:s');
        }
        return null;
    }

    /** Дата отправки формы для «через N дней после подачи». */
    private static function submittedAt(AnswersSet $answers): ?string
    {
        foreach ([$answers->fields['date_creation'] ?? null, $_SESSION['glpi_currenttime'] ?? null] as $at) {
            if (Condition::dateTime($at) !== null) { return $at; }
        }
        return null;
    }

    /** Ограниченный точный десятичный синтаксис; float никогда не проходит как точное число. */
    public static function decimal(mixed $raw): ?string
    {
        if (is_int($raw)) { $raw = (string) $raw; }
        if (!is_string($raw) || strlen($raw) > 128 || !preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D', $raw, $m)) { return null; }
        $integer = ltrim($m[2], '0'); $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($m[3] ?? '', '0');
        return (($m[1] === '-' && ($integer !== '0' || $fraction !== '')) ? '-' : '') . $integer . ($fraction !== '' ? '.' . $fraction : '');
    }
    private static function unknown(array $d, string $reason): array { return ['status' => 'unknown', 'type' => $d['type'] ?? 'unsupported', 'value' => null, 'reason' => $reason]; }
    private static function unknowns(array $defs, string $reason): array { return array_map(static fn($d) => self::unknown($d, $reason), $defs); }
    private static function envelope(string $status, array $source, array $facts): array
    {
        return ['version' => 1, 'status' => $status, 'source' => $source, 'facts' => $facts,
            'input_revision' => hash('sha256', DefinitionSnapshot::encode([$source, $facts]))];
    }
    private static function save(Instance $instance, array $envelope): array
    {
        $old = self::load($instance);
        if (($old['status'] ?? '') === 'final') { return $old; }
        if (!$instance->update(['id' => $instance->getID(), 'form_facts' => DefinitionSnapshot::encode($envelope)])) {
            throw new \RuntimeException('Факты формы не сохранены.');
        }
        $instance->fields['form_facts'] = DefinitionSnapshot::encode($envelope);
        return $envelope;
    }
}
