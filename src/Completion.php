<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;

/** Отдельно показывает окончание этапов и штатное состояние исходной заявки. */
final class Completion
{
    /**
     * Дополнительно проверяет READ и точную связь исходного объекта.
     * Не создаёт решения, не изменяет статус и не выводит внутренние причины.
     * Новый текст включается вызывающим кодом только для новой семантики маршрута.
     *
     * @return array{title: string, message: string, needs_resolution: bool, final_reply_verified: bool, solution_acceptance_verified: bool}|null
     */
    public static function describe(Instance $instance, CommonITILObject $item, bool $staff): ?array
    {
        if (Authorizer::instanceError($instance, 'read') !== null
            || !DefinitionSnapshot::enabled($instance)
            || $instance->fields['state'] !== Instance::DONE
            || $instance->fields['itemtype'] !== $item->getType()
            || (int) $instance->fields['items_id'] !== (int) $item->getID()
            || !$item->getFromDB($item->getID())) {
            return null;
        }
        $status = (int) $item->fields['status'];
        if (in_array($status, $item::getClosedStatusArray(), true)) {
            return [
                'title' => 'Этапы завершены, заявка закрыта',
                'message' => 'В GLPI заявка имеет статус «Закрыта». Итоговый ответ и историю принятия решения смотрите в доступной вам штатной ленте заявки.',
                'needs_resolution' => false,
                'final_reply_verified' => false,
                'solution_acceptance_verified' => false,
            ];
        }
        if (in_array($status, $item::getSolvedStatusArray(), true)) {
            return [
                'title' => 'Этапы завершены, заявка решена',
                'message' => $staff
                    ? 'Заявка имеет статус «Решена». Проверьте итог; принятие решения и закрытие выполняются по правилам GLPI.'
                    : 'Заявка имеет статус «Решена». Проверьте итог в ленте заявки; доступные действия определяются правилами обработки заявки.',
                'needs_resolution' => false,
                'final_reply_verified' => false,
                'solution_acceptance_verified' => false,
            ];
        }
        return [
            'title' => 'Этапы завершены, ожидается решение заявки',
            'message' => $staff
                ? 'Проверьте, что заявителю дан понятный итоговый ответ, и оформите штатное решение заявки.'
                : 'Работа по этапам завершена. Ответственному остаётся оформить итоговое решение вашей заявки.',
            'needs_resolution' => true,
            'final_reply_verified' => false,
            'solution_acceptance_verified' => false,
        ];
    }
}
