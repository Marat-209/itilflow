<?php

use GlpiPlugin\Itilflow\Decision;
use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Step;

// CSRF проверяется middleware; права и номер изменения ответов повторно проверяет команда.
Session::checkLoginUser();
Session::checkCentralAccess();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException('Страница открыта неверно. Вернитесь к заявке и повторите.');
}
$id = static function (string $key): int {
    $raw = $_POST[$key] ?? null;
    return is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw) && (string) (int) $raw === $raw ? (int) $raw : 0;
};
$step = new Step();
$answers = Decision::fromPost($_POST['decision_answers'] ?? null);
$revision = Decision::revisionFromPost($_POST['expected_decision_revision'] ?? null);
$action = $_POST['decision_action'] ?? null;
$result = ['status' => 'rejected'];
$found = $step->getFromDB($id('steps_id')) && (int) $step->fields['plugin_itilflow_instances_id'] === $id('instances_id');
if ($found && $answers !== null && $revision !== null && in_array($action, ['save','approve'], true)
    && is_string($_POST['command_key'] ?? null) && ($action !== 'approve' || $id('validation_id') > 0)) {
    $result = Engine::saveDecision($step, $answers, $revision, $_POST['command_key'], $id('expected_attempt_id'),
        $action === 'approve' ? $id('validation_id') : 0);
}
$applied = in_array($result['status'], ['applied','already_applied'], true);
$message = $action === 'approve' ? 'Ответы сохранены, согласование одобрено.' : 'Ответы сохранены.';
if (!$applied) {
    $message = 'Ответы не сохранены: обновите страницу и ответьте на все вопросы.';
    $items = [];
    $current = null;
    if ($found) {
        try {
            $fresh = new Step();
            if ($fresh->getFromDB($step->getID())) { $items = Decision::items($fresh); $current = Decision::read($fresh)['revision']; }
        } catch (\Throwable $e) {
            // Повреждённые ответы этапа — общий текст.
        }
    }
    if ($revision !== null && $current !== null && $items !== [] && $current !== $revision) {
        // Пока форма была открыта, ответы этапа сохранил другой человек.
        $message = 'Ответы этапа уже изменили — посмотрите их и сохраните снова.';
    } elseif ($answers === null || ($items !== [] && count($answers) !== count($items))) {
        $message = 'Ответы не сохранены: ответьте на все вопросы этапа.';
    }
}
Session::addMessageAfterRedirect($message, false, $applied ? INFO : ERROR);
Html::back();
