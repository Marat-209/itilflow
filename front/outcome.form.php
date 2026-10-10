<?php

use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Outcome;
use GlpiPlugin\Itilflow\Step;

// Штатный CSRF middleware; права проверяются сервером также для Self-Service.
Session::checkLoginUser();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException('Действие требует POST.');
}
$id = static function (string $key): int {
    $value = $_POST[$key] ?? null;
    return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value)
        && (string) (int) $value === (string) $value ? (int) $value : 0;
};
$text = static function (string $key, int $limit = 8000): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) && strlen($value) <= $limit ? trim(strip_tags($value)) : '';
};
$action = $text('action', 40);
$key = $text('command_key', 64);
$attempt = $id('expected_attempt_id');
$instance = new Instance();
$step = new Step();
$result = ['status' => 'rejected'];
if ($instance->getFromDB($id('instances_id'))) {
    if (in_array($action, ['new_attempt', 'activate_attempt'], true)) {
        $result = $action === 'new_attempt'
            ? Engine::newAttempt($instance, $id('target_stage_id'), $text('reason'), $text('subject_summary', 16000), $key, $attempt)
            : Engine::activateAttempt($instance, $text('subject_summary', 16000), $text('reason'), $key, $attempt);
    } elseif ($step->getFromDB($id('steps_id'))
        && (int) $step->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()) {
        if ($action === 'department_outcome') {
            $result = Engine::departmentOutcome($step, $text('outcome', 24), $text('reason'), $text('public_summary'), $key, $attempt);
        } elseif ($action === 'request_clarification') {
            $result = Engine::requestClarification($step, $id('target_stage_id'), $text('reason'), $text('public_summary'), $key, $attempt);
        } elseif ($action === 'reconcile_outcome') {
            $result = Engine::reconcileOutcome($step, $text('validation_itemtype', 100), $id('validations_id'), $key, $attempt);
        } elseif ($action === 'retry_status_sync') {
            $result = Engine::retryStatusSync($step, $key, $attempt);
        } elseif (in_array($action, ['explain_outcome', 'publish_positive_result'], true)) {
            $outcome = new Outcome();
            if ($outcome->getFromDB($id('outcomes_id')) && (int) $outcome->fields['plugin_itilflow_steps_id'] === (int) $step->getID()) {
                $result = $action === 'explain_outcome'
                    ? Engine::explainOutcome($outcome, $text('public_summary'), $key, $attempt)
                    : Engine::publishPositiveResult($outcome, $text('public_summary'), $key, $attempt);
            }
        }
    }
}
$status = $result['status'];
Session::addMessageAfterRedirect(match ($status) {
    'applied' => 'Действие сохранено.', 'already_applied' => 'Это действие уже выполнено.',
    'unknown' => 'Результат не подтверждён. Сначала обновите карточку и проверьте историю.',
    default => 'Действие отклонено: проверьте права, состояние и заполнение полей.',
}, false, in_array($status, ['applied', 'already_applied'], true) ? INFO : ERROR);
Html::back();
