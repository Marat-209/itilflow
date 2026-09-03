<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;
use Glpi\Application\View\TemplateRenderer;
use Group;
use Session;

final class Ui
{
    /** Данные шагов, обогащённые для отображения. */
    public static function decorate(Instance $instance): array
    {
        $rows = [];
        $me = (int) Session::getLoginUserID();
        $mygroups = Engine::myGroups();

        foreach ($instance->getSteps() as $row) {
            $owner = '—';
            if ((int) $row['groups_id'] > 0) {
                $g = new Group();
                $owner = $g->getFromDB((int) $row['groups_id']) ? $g->fields['name'] : '—';
            } elseif ((int) $row['users_id'] > 0) {
                $owner = getUserName((int) $row['users_id']);
            }

            $step = new Step();
            $step->getFromDB((int) $row['id']);

            $link = '';
            $type = (string) ($row['artifact_itemtype'] ?? '');
            $aid = (int) $row['artifact_items_id'];
            if ($type !== '' && $aid > 0 && class_exists($type)) {
                $a = new $type();
                if ($a->getFromDB($aid)) {
                    if ($a instanceof CommonITILObject) {
                        $link = $a->getLink();
                    } elseif ($a instanceof \CommonITILTask) {
                        $link = sprintf('<span class="text-muted">задача #%d</span>', $aid);
                    } elseif ($a instanceof \CommonITILValidation) {
                        $link = sprintf('<span class="text-muted">согласование #%d</span>', $aid);
                    }
                }
            }

            $stage = $step->getStage();

            $rows[] = $row + [
                'is_optional'  => $stage !== null ? (int) $stage->fields['is_optional'] : 0,
                'due'          => $row['date_due']
                    ? \Html::convDateTime((string) $row['date_due']) : '',
                'overdue'      => Sheet::wasOverdue($row),
                'completion_comment' => $stage !== null
                    ? (string) $stage->fields['completion_comment'] : 'optional',
                'owner_name'   => $owner,
                'state_label'  => Step::getStateLabels()[$row['state']] ?? $row['state'],
                'mode_label'   => Stage::getModeLabels()[$row['execution_mode']] ?? $row['execution_mode'],
                'is_mine'      => $step->isOwnedBy($me, $mygroups),
                'artifact_link' => $link,
                'duration_h'   => (int) $row['duration'] > 0
                    ? \Html::timestampToString((int) $row['duration'], false)
                    : '',
            ];
        }
        return $rows;
    }

    /** Полная вкладка маршрута на заявке. */
    public static function showFlowTab(CommonITILObject $item): void
    {
        $instance = Instance::getForItem($item);

        if ($instance === null) {
            $available = [];
            foreach ((new Process())->find([
                'is_active' => 1,
                'itemtype'  => $item->getType(),
            ] + getEntitiesRestrictCriteria(Process::getTable(), '', $item->fields['entities_id'], true)) as $id => $p) {
                $available[$id] = $p['name'];
            }
            TemplateRenderer::getInstance()->display('@itilflow/flow_start.html.twig', [
                'item'      => $item,
                'processes' => $available,
                'can_start' => Session::haveRight('plugin_itilflow_process', UPDATE),
            ]);
            return;
        }

        $process = $instance->getProcess();
        $current = $instance->getCurrentStep();

        // Форма завершения показывается только исполнителю текущего этапа
        // и только для этапов, которые закрываются вручную.
        $form = null;
        if ($current !== null && $instance->fields['state'] === Instance::RUNNING) {
            $stage = $current->getStage();
            $mine = $current->isOwnedBy((int) Session::getLoginUserID(), Engine::myGroups());
            $admin = Session::haveRight('plugin_itilflow_process', UPDATE);
            if ($stage !== null
                && $stage->fields['execution_mode'] === Stage::MODE_INLINE
                && ($mine || $admin)) {
                $form = [
                    'steps_id'    => $current->getID(),
                    'title'       => sprintf('Этап %d. %s',
                        (int) $current->fields['ranking'], (string) $current->fields['stage_name']),
                    'instruction' => (string) $stage->fields['content'],
                    'comment'     => (string) $stage->fields['completion_comment'],
                    'is_optional' => (int) $stage->fields['is_optional'],
                ];
            }
        }

        $is_staff = Session::getCurrentInterface() === 'central';
        $me = (int) Session::getLoginUserID();
        $can_admin = Session::haveRight('plugin_itilflow_process', UPDATE);
        $process_obj = $instance->getProcess();

        // Переназначение доступно исполнителю текущего этапа и администратору.
        $reassign = null;
        if ($is_staff && $current !== null && $instance->fields['state'] === Instance::RUNNING) {
            $cstage = $current->getStage();
            if ($cstage !== null && $cstage->fields['execution_mode'] !== Stage::MODE_APPROVAL
                && ($current->isOwnedBy($me, Engine::myGroups()) || $can_admin)) {
                $reassign = [
                    'steps_id' => $current->getID(),
                    'title'    => sprintf('Этап %d. %s',
                        (int) $current->fields['ranking'], (string) $current->fields['stage_name']),
                ];
            }
        }

        // Указание согласующего: этап-согласование, у которого согласующий
        // определяется при прохождении.
        //
        // Проверять принадлежность этапа здесь нельзя: у такого этапа
        // ответственный ещё не задан, и isOwnedBy() вернул бы false для всех.
        // Поэтому право даём тем, кто фактически ведёт заявку: администратору
        // процессов и исполнителям заявки — это и есть диспетчер.
        $approver = null;
        if ($is_staff && $current !== null && $instance->fields['state'] === Instance::RUNNING
            && $current->awaitsApprover()
            && ($can_admin || self::isAssignee($item, $me))) {
            $approver = [
                'steps_id' => $current->getID(),
                'title'    => sprintf('Этап %d. %s',
                    (int) $current->fields['ranking'], (string) $current->fields['stage_name']),
            ];
        }

        // Отзыв заявки — право инициатора.
        $withdraw = null;
        if ($process_obj !== null && $process_obj->fields['allow_withdraw']
            && in_array($instance->fields['state'], [Instance::RUNNING, Instance::HALTED], true)
            && ($item->isUser(\CommonITILActor::REQUESTER, $me) || $can_admin)) {
            $withdraw = ['instances_id' => $instance->getID()];
        }

        TemplateRenderer::getInstance()->display('@itilflow/flow_tab.html.twig', [
            'form'          => $form,
            'is_staff'      => $is_staff,
            'item'          => $item,
            'instance'      => $instance,
            'process'       => $process,
            'process_name'  => $process ? $process->fields['name'] : '',
            'enforcement'   => $process ? (Process::getEnforcementLabels()[$process->fields['enforcement']] ?? '') : '',
            'state_label'   => Instance::getStateLabels()[$instance->fields['state']] ?? '',
            'steps'         => self::decorate($instance),
            'current_id'    => $current ? $current->getID() : 0,
            'can_admin'     => $can_admin,
            'reassign'      => $reassign,
            'approver'      => $approver,
            'withdraw'      => $withdraw,
            'sheet_url'     => \Plugin::getWebDir('itilflow') . '/front/sheet.php?id=' . $instance->getID(),
            'violations'    => $is_staff ? self::violations($instance) : [],
        ]);
    }

    /** Ведёт ли этот сотрудник заявку: назначен лично или через свою группу. */
    private static function isAssignee(CommonITILObject $item, int $users_id): bool
    {
        if ($item->isUser(\CommonITILActor::ASSIGN, $users_id)) {
            return true;
        }
        foreach (Engine::myGroups() as $gid) {
            if ($item->isGroup(\CommonITILActor::ASSIGN, $gid)) {
                return true;
            }
        }
        return false;
    }

    private static function violations(Instance $instance): array
    {
        $out = [];
        foreach ((new Violation())->find(
            ['plugin_itilflow_instances_id' => $instance->getID()],
            ['date DESC'],
            20
        ) as $id => $row) {
            $row['user_name'] = (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '—';
            $out[] = $row;
        }
        return $out;
    }
}
