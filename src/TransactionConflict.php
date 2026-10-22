<?php
namespace GlpiPlugin\Itilflow;

/**
 * Сервер прервал транзакцию команды из-за блокировок (1213, 1205), и она откачена целиком
 * (TransactionScope::rollbackAfterConflict): состояние известно, запрос продолжается —
 * в отличие от TransactionLost. Повторять стоит только взаимную блокировку (1213): после 1205
 * ожидание уже длилось до таймаута сервера.
 */
final class TransactionConflict extends \RuntimeException
{
    public readonly bool $retryable;

    public function __construct(\Throwable $previous)
    {
        parent::__construct('Транзакция маршрута откачена сервером из-за блокировок.', 0, $previous);
        $this->retryable = TransactionScope::isDeadlock($previous);
    }
}
