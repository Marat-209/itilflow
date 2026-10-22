<?php

/**
 * Пересчёт без сохранения (1.13): готовность этапа и «Что дальше», фразы
 * и кратко редактора условий, ошибки срока и SLA под полями. Только чтение, в базу не пишет.
 *
 * Запрос — POST всей формы (скрипт condition_editor.html.twig). Ключ CSRF — и в заголовке
 * X-Glpi-Csrf-Token (его проверяет ядро), и в теле _glpi_csrf_token (проверка здесь);
 * ключ не гасится: форму потом сохраняют с ним же.
 *   _itilflow_draft = stage — форма этапа;
 *   _itilflow_draft = start — «Запускать, если» маршрута: редактор условий (поля под именем
 *                             _itilflow_draft_prefix, форма — forms_forms_id) и строка готовности;
 *   _itilflow_draft = process — форма маршрута без редактора условий: строка готовности.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Itilflow\BranchConfiguration;
use GlpiPlugin\Itilflow\ConditionEditor;
use GlpiPlugin\Itilflow\FormConfiguration;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Readiness;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\StageForm;

Session::checkRight('plugin_itilflow_process', READ);
// Заголовки — пока вывода не было (проверка с базой вызывает скрипт через include после своего вывода).
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

$answer = static function (int $code, array $data): void {
    if (!headers_sent()) { http_response_code($code); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !Session::validateCSRF($_POST, true)) {
    $answer(403, ['error' => 'Страница устарела. Обновите её и повторите.']);
    return;
}
$process = new Process();
// Форма этапа несёт номер маршрута отдельно (id — номер этапа); форма маршрута — в id.
$processForm = in_array($_POST['_itilflow_draft'] ?? '', [ConditionEditor::SCOPE_START, 'process'], true);
$pid = $processForm ? (int) ($_POST['id'] ?? 0) : (int) ($_POST['plugin_itilflow_processes_id'] ?? 0);
if ($pid <= 0 || !$process->getFromDB($pid) || !$process->can($pid, READ)) {
    $answer(404, ['error' => 'Маршрут не найден. Обновите страницу.']);
    return;
}
// Пересчёт формы маршрута — только тому, кто её правит: готовность по чужой форме назвала бы
// её вопросы, назначения и маршруты, которые читателю не видны.
if ($processForm && !$process->can($pid, UPDATE)) {
    $answer(403, ['error' => 'Нет права изменять маршрут.']);
    return;
}
// Форма из ввода — только видимая этому пользователю.
$draftForm = $processForm ? (int) ($_POST['forms_forms_id'] ?? 0) : 0;
if ($draftForm > 0 && $draftForm !== (int) ($process->fields['forms_forms_id'] ?? 0)) {
    $form = new \Glpi\Form\Form();
    if (!$form->getFromDB($draftForm) || !$form->can($draftForm, READ)) {
        $answer(404, ['error' => 'Форма не найдена. Обновите страницу.']);
        return;
    }
}

/** Фразы, ошибки и кратко одного редактора по итогам ConditionEditor::parseRows. */
$editorAnswer = static function (array $parsed, string $scope, array $stages): array {
    $clauses = []; $errors = [];
    foreach ($parsed['rows'] as $row) {
        if ($row['clause'] !== null) { $clauses[$row['index']] = $row['clause']; }
        else { $errors[$row['index']] = mb_strtoupper(mb_substr($row['error'], 0, 1)) . mb_substr($row['error'], 1); }
    }
    $phrases = array_combine(array_keys($clauses), ConditionEditor::phrases(array_values($clauses), $scope, $parsed['join'], $stages) ?: []) ?: [];
    $rowErrors = array_filter($parsed['errors'], static fn(string $e): bool => !preg_match('/^Условие \d+: /u', $e));
    $rule = $clauses ? ['version' => 1, 'join' => $parsed['join'], 'clauses' => array_values($clauses)] : [];
    return ['summary' => ConditionEditor::summary($scope, $parsed['mode'], $rule, $stages),
        'phrases' => (object) $phrases, 'errors' => (object) $errors, 'general' => array_values($rowErrors)];
};

/**
 * Форма маршрута без сохранения: строка готовности по введённым полям и «Запускать, если»
 * и подпись нулевого пункта «Назначение формы» (при запуске по форме — «Любое назначение…»).
 */
$processAnswer = static function (Process $process): array {
    $draft = clone $process;
    $draft->fields = Process::draftFields($process->fields, $_POST);
    return ['readiness_html' => TemplateRenderer::getInstance()->render('@itilflow/readiness.html.twig',
            ['r' => Readiness::check($draft), 'stage_view' => false]),
        'destination_zero' => FormConfiguration::destinationZeroLabel($draft->fields)];
};

if (($_POST['_itilflow_draft'] ?? '') === 'process') {
    $answer(200, $processAnswer($process));
    return;
}

if (($_POST['_itilflow_draft'] ?? '') === ConditionEditor::SCOPE_START) {
    $prefix = (string) ($_POST['_itilflow_draft_prefix'] ?? '');
    if (!preg_match('/^[a-z_]{1,40}$/D', $prefix)) {
        $answer(400, ['error' => 'Неизвестный редактор условий.']);
        return;
    }
    $fields = $process->fields;
    if (isset($_POST['forms_forms_id'])) { $fields['forms_forms_id'] = (int) $_POST['forms_forms_id']; }
    $parsed = ConditionEditor::parseRows(is_array($_POST[$prefix] ?? null) ? $_POST[$prefix] : [], ConditionEditor::SCOPE_START, $fields);
    $answer(200, ['editors' => [$prefix => $editorAnswer($parsed, ConditionEditor::SCOPE_START, [])]] + $processAnswer($process));
    return;
}

$draft = Stage::draft($_POST, $process);
$id = (int) $draft['stage']['id'];
$check = Readiness::check($process, $draft['stages']);
$mine = static fn(array $list): array => array_values(array_filter($list, static fn(array $x): bool => ($x['stage'] ?? null) === $id));
$problems = $mine($check['problems']);
foreach ($draft['parsed']['errors'] as $error) {
    $problems[] = ['text' => 'Когда выполнять — ' . mb_strtolower(mb_substr($error, 0, 1)) . mb_substr($error, 1), 'stage' => $id, 'url' => null, 'link' => ''];
}
foreach (array_diff($draft['errors'], $draft['parsed']['errors']) as $error) {
    $problems[] = ['text' => $error, 'stage' => $id, 'url' => null, 'link' => ''];
}
$readiness = ['problems' => $problems, 'notes' => $mine($check['notes'])];
$answer(200, [
    'editors' => [BranchConfiguration::EDITOR => $editorAnswer($draft['parsed'], ConditionEditor::SCOPE_STAGE, $draft['stages'])],
    'readiness_html' => TemplateRenderer::getInstance()->render('@itilflow/readiness.html.twig', ['r' => $readiness, 'stage_view' => true]),
    'what_next' => StageForm::whatNext($draft['stage'], $draft['stages']),
    'what_before' => StageForm::reachedFrom($draft['stage'], $draft['stages']),
    'fields' => (object) $draft['fields'],
]);
