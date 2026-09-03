<?php

use GlpiPlugin\Itilflow\Engine;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Step;

Session::checkCentralAccess();

/* --------------------------------------------------- запуск маршрута вручную */
if (isset($_POST['start'])) {
    Session::checkRight('plugin_itilflow_process', UPDATE);
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $items_id = (int) ($_POST['items_id'] ?? 0);
    $processes_id = (int) ($_POST['processes_id'] ?? 0);

    if (is_a($itemtype, CommonITILObject::class, true) && $items_id > 0 && $processes_id > 0) {
        $item = new $itemtype();
        if ($item->getFromDB($items_id) && $item->can($items_id, READ)) {
            if (Engine::start($item, $processes_id) === null) {
                Session::addMessageAfterRedirect('Маршрут не запущен.', false, ERROR);
            }
        }
    }
    Html::back();
}

/* ------------------------------------------- завершение / пропуск текущего шага */
if (isset($_POST['complete']) || isset($_POST['skip'])) {
    $step = new Step();
    if (!$step->getFromDB((int) ($_POST['steps_id'] ?? 0))) {
        Html::back();
    }
    $instance = $step->getInstance();
    $item = $instance?->getItilItem();
    if ($instance === null || $item === null || !$item->can($item->getID(), UPDATE)) {
        Session::addMessageAfterRedirect('Недостаточно прав.', false, ERROR);
        Html::back();
    }

    $mine = $step->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups());
    $admin = Session::haveRight('plugin_itilflow_process', UPDATE);
    if (!$mine && !$admin) {
        Session::addMessageAfterRedirect(
            'Этот этап закреплён за другой группой.',
            false,
            ERROR
        );
        Html::back();
    }

    $skip = isset($_POST['skip']);
    $report = trim(strip_tags((string) ($_POST['report'] ?? '')));

    $error = Engine::validateReport($step, $report, $skip);
    if ($error !== null) {
        Session::addMessageAfterRedirect($error, false, ERROR);
        Html::back();
    }

    Engine::completeStep($step, $report, $skip);
    Html::back();
}

/* ------------------------------------------------ переназначение этапа */
if (isset($_POST['reassign'])) {
    $step = new Step();
    if (!$step->getFromDB((int) ($_POST['steps_id'] ?? 0))) {
        Html::back();
    }
    $instance = $step->getInstance();
    $item = $instance?->getItilItem();
    if ($instance === null || $item === null || !$item->can($item->getID(), UPDATE)) {
        Session::addMessageAfterRedirect('Недостаточно прав.', false, ERROR);
        Html::back();
    }
    $mine = $step->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups());
    $admin = Session::haveRight('plugin_itilflow_process', UPDATE);
    if (!$mine && !$admin) {
        Session::addMessageAfterRedirect(
            'Передать этап может его текущий исполнитель или администратор процессов.',
            false,
            ERROR
        );
        Html::back();
    }

    Engine::reassign(
        $step,
        (int) ($_POST['to_groups_id'] ?? 0),
        (int) ($_POST['to_users_id'] ?? 0),
        trim(strip_tags((string) ($_POST['reassign_reason'] ?? '')))
    );
    Html::back();
}

/* ------------------------------------------------ отзыв заявки инициатором */
if (isset($_POST['withdraw'])) {
    $instance = new Instance();
    if (!$instance->getFromDB((int) ($_POST['instances_id'] ?? 0))) {
        Html::back();
    }
    $item = $instance->getItilItem();
    if ($item === null || !$item->can($item->getID(), READ)) {
        Session::addMessageAfterRedirect('Недостаточно прав.', false, ERROR);
        Html::back();
    }
    // Отзывать может инициатор заявки или администратор процессов.
    $me = (int) Session::getLoginUserID();
    $is_requester = $item->isUser(CommonITILActor::REQUESTER, $me);
    if (!$is_requester && !Session::haveRight('plugin_itilflow_process', UPDATE)) {
        Session::addMessageAfterRedirect('Отозвать заявку может её инициатор.', false, ERROR);
        Html::back();
    }

    Engine::withdraw($instance, trim(strip_tags((string) ($_POST['withdraw_reason'] ?? ''))));
    Html::back();
}

/* ------------------------------------------------ прервать / возобновить маршрут */
if (isset($_POST['abort']) || isset($_POST['resume'])) {
    Session::checkRight('plugin_itilflow_process', UPDATE);
    $instance = new Instance();
    if ($instance->getFromDB((int) ($_POST['instances_id'] ?? 0))) {
        if (isset($_POST['abort'])) {
            Engine::abort($instance, 'решение администратора');
        } else {
            Engine::resume($instance);
        }
    }
    Html::back();
}

Html::back();
