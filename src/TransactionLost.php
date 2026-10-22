<?php
namespace GlpiPlugin\Itilflow;

/** Не бизнес-отказ: продолжать запрос после потери транзакции нельзя. */
final class TransactionLost extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        // Без SQL, значений полей и исходного исключения с данными заявки.
        parent::__construct('Действие остановлено: изменения не удалось сохранить целиком. Обновите страницу и проверьте результат (' . $reason . ').');
    }
}
