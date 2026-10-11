<?php

use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Step;

// CSRF проверяет штатный middleware GLPI (CSRF_COMPLIANT в setup.php).
Session::checkLoginUser();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException('Действие требует POST.');
}
$actions = array_values(array_intersect(
    ['start', 'complete', 'skip', 'override', 'set_approver', 'reassign', 'withdraw', 'abort', 'resume'],
    array_keys($_POST)
));
if (count($actions) !== 1) {
    throw new \Glpi\Exception\Http\BadRequestHttpException('Укажите одно действие маршрута.');
}
$action = $actions[0];
$id = static function (string $key): int {
    $value = $_POST[$key] ?? null;
    return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value)
        && (string) (int) $value === (string) $value ? (int) $value : 0;
};
$text = static fn(string $key): string => is_string($_POST[$key] ?? null)
    ? trim(strip_tags($_POST[$key])) : '';

$command_key = is_string($_POST['command_key'] ?? null) ? $_POST['command_key'] : '';
$expected_attempt = $id('expected_attempt_id');

// Единственное действие этого endpoint, доступное из самообслуживания.
// Проверки инициатора, объекта и состояния находятся также в Engine::withdraw.
if ($action === 'withdraw') {
    $instance = new Instance();
    if ($instance->getFromDB($id('instances_id'))) {
        Engine::withdraw($instance, $text('withdraw_reason'), $command_key, $expected_attempt);
    }
    Html::back();
    return;
}

Session::checkCentralAccess();
if ($action === 'start') {
    $type = $_POST['itemtype'] ?? null;
    if (is_string($type) && in_array($type, ['Ticket', 'Change', 'Problem'], true)) {
        $item = new $type();
        if ($item->getFromDB($id('items_id'))) {
            Engine::start($item, $id('processes_id'));
        }
    }
    Html::back();
    return;
}
if (in_array($action, ['abort', 'resume'], true)) {
    $instance = new Instance();
    if ($instance->getFromDB($id('instances_id'))) {
        if ($action === 'abort') {
            Engine::abort($instance, $text('abort_reason'), $command_key, $expected_attempt);
        } else {
            Engine::resume($instance, $command_key, $expected_attempt);
        }
    }
    Html::back();
    return;
}

$step = new Step();
if (!$step->getFromDB($id('steps_id'))) {
    Html::back();
    return;
}
switch ($action) {
    case 'complete':
    case 'skip':
        Engine::completeStep($step, $text('report'), $action === 'skip', $command_key, $expected_attempt);
        break;
    case 'override':
        Engine::overrideStep($step, $text('report'), $command_key, $expected_attempt);
        break;
    case 'set_approver':
        $ids = static function (string $key): ?array {
            $values = $_POST[$key] ?? [];
            if (!is_array($values)) {
                $values = [$values];
            }
            $result = [];
            foreach ($values as $value) {
                // Перед каждым списком с множественным выбором GLPI ставит скрытое поле с тем же
                // именем и пустым значением: пустой список приходит как «», это «никого не выбрали».
                if ($value === '') {
                    continue;
                }
                if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
                    || (string) (int) $value !== (string) $value) {
                    return null;
                }
                $result[] = (int) $value;
            }
            return $result;
        };
        $groups = $ids('appr_groups_id');
        $users = $ids('appr_users_id');
        if ($groups !== null && $users !== null) {
            Engine::designateApprover($step, $groups, $users, $text('appr_reason'), $command_key, $expected_attempt);
        } else {
            Session::addMessageAfterRedirect('Согласующий не указан: выбор не распознан. Обновите карточку и выберите заново.', false, ERROR);
        }
        break;
    case 'reassign':
        Engine::reassign($step, $id('to_groups_id'), $id('to_users_id'), $text('reassign_reason'), $command_key, $expected_attempt);
        break;
}
Html::back();
