<?php

use GlpiPlugin\Itilflow\Authorizer;
use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Outcome;
use GlpiPlugin\Itilflow\Stage;
use GlpiPlugin\Itilflow\Step;
use GlpiPlugin\Itilflow\StepLine;
use GlpiPlugin\Itilflow\Decision;

global $CFG_GLPI;

// Штатный CSRF middleware; права проверяются сервером также для самообслуживания.
Session::checkLoginUser();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException('Страница открыта неверно. Вернитесь к заявке и повторите.');
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
// Кнопки строки этапа: одно поле результата; приватный комментарий необязателен —
// без него причиной служит тот же текст.
$do = $text('itilflow_do', 20);
if ($do !== '') {
    $action = match ($do) {
        'done', 'refuse' => 'department_outcome', 'return' => 'request_clarification',
        'activate' => 'activate_attempt', 'new_attempt' => 'new_attempt', default => '',
    };
    if (in_array($do, ['done', 'refuse'], true)) {
        $_POST['outcome'] = $do === 'done' ? Outcome::COMPLETED : Outcome::REFUSED;
    }
    if ($text('reason') === '') {
        $_POST['reason'] = in_array($do, ['activate', 'new_attempt'], true) ? $text('subject_summary', 16000) : $text('public_summary');
    }
}
$attempt = $id('expected_attempt_id');
$instance = new Instance();
$step = new Step();
$result = ['status' => 'rejected'];
$problem = '';
if ($instance->getFromDB($id('instances_id'))) {
    if (in_array($action, ['new_attempt', 'activate_attempt'], true)) {
        $result = $action === 'new_attempt'
            ? Engine::newAttempt($instance, $id('target_stage_id'), $text('reason'), $text('subject_summary', 16000), $key, $attempt)
            : Engine::activateAttempt($instance, $text('subject_summary', 16000), $text('reason'), $key, $attempt);
    } elseif ($step->getFromDB($id('steps_id'))
        && (int) $step->fields['plugin_itilflow_instances_id'] === (int) $instance->getID()) {
        if ($action === 'department_outcome') {
            // Ответы этапа приходят вместе с результатом (у дочерней заявки нет отдельного «Сохранить ответы»).
            // Для «Отказ» ответы не нужны: пустые поля не становятся ответом «нет».
            $answers = array_key_exists('decision_answers', $_POST) ? Decision::fromPost($_POST['decision_answers']) : null;
            if ($text('outcome', 24) === Outcome::REFUSED) { $answers = null; }
            if ($text('outcome', 24) === Outcome::REFUSED || !array_key_exists('decision_answers', $_POST) || $answers !== null) {
                $result = Engine::departmentOutcome($step, $text('outcome', 24), $text('reason'), $text('public_summary'), $key, $attempt,
                    Decision::revisionFromPost($_POST['expected_decision_revision'] ?? null), $answers);
            } else {
                $problem = 'Ответьте на все вопросы этапа — без ответов «Выполнено» не сохранить.';
            }
        } elseif ($action === 'request_clarification') {
            $result = Engine::requestClarification($step, $id('target_stage_id'), $text('reason'), $text('public_summary'), $key, $attempt,
                Decision::revisionFromPost($_POST['expected_decision_revision'] ?? null));
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
$applied = in_array($status, ['applied', 'already_applied'], true);

// Ответы этапа успели изменить, пока форма была открыта: сказать это, а не «проверьте права».
if (!$applied && $problem === '' && !$step->isNewItem() && in_array($action, ['department_outcome', 'request_clarification'], true)
    && ($expected = Decision::revisionFromPost($_POST['expected_decision_revision'] ?? null)) !== null) {
    try {
        $fresh = new Step();
        if ($fresh->getFromDB($step->getID()) && Decision::items($fresh) !== [] && Decision::read($fresh)['revision'] !== $expected) {
            $problem = 'Ответы этапа уже изменили — посмотрите их и сохраните снова.';
        }
    } catch (\Throwable $e) {
        // Повреждённые ответы этапа — общий текст ниже.
    }
}

// После действия человек может потерять доступ к заявке (согласующий из самообслуживания
// видит её только по своему запросу на согласование). Тогда — не на страницу «нет прав», а домой.
if ($applied && !$instance->isNewItem()) {
    if (!Authorizer::stepItemVisible($instance, $step)) {
        Session::addMessageAfterRedirect($action === 'request_clarification'
            ? 'Готово: заявка возвращена инициатору на уточнение.' : 'Готово: действие сохранено.', false, INFO);
        Html::redirect(StepLine::homeUrl((string) Session::getCurrentInterface(), (string) $CFG_GLPI['root_doc']));
    }
}

Session::addMessageAfterRedirect(match ($status) {
    'applied' => 'Действие сохранено.', 'already_applied' => 'Это действие уже выполнено.',
    'unknown' => 'Не удалось проверить, выполнено ли действие. Обновите страницу и посмотрите историю этапа.',
    default => $problem !== '' ? $problem : 'Действие не выполнено: проверьте поля и обновите страницу.',
}, false, $applied ? INFO : ERROR);
Html::back();
