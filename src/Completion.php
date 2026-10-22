<?php

namespace GlpiPlugin\Itilflow;

use CommonITILObject;

/** Отдельно показывает окончание этапов и статус заявки в GLPI. */
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
                'title' => 'Этапы пройдены, заявка закрыта',
                'message' => 'Заявка в статусе «Закрыто». Ответ и историю смотрите в ленте заявки.',
                'needs_resolution' => false,
                'final_reply_verified' => false,
                'solution_acceptance_verified' => false,
            ];
        }
        if (in_array($status, $item::getSolvedStatusArray(), true)) {
            return [
                'title' => 'Этапы пройдены, заявка решена',
                'message' => $staff
                    ? 'Проверьте результат и примите решение.'
                    : 'Заявка в статусе «Решена». Посмотрите ответ в ленте заявки.',
                'needs_resolution' => false,
                'final_reply_verified' => false,
                'solution_acceptance_verified' => false,
            ];
        }
        return [
            'title' => 'Этапы пройдены, заявка ждёт решения',
            'message' => $staff
                ? 'Добавьте решение заявки.'
                : 'Работа по этапам закончена. Исполнитель добавит решение вашей заявки.',
            'needs_resolution' => true,
            'final_reply_verified' => false,
            'solution_acceptance_verified' => false,
        ];
    }
}
