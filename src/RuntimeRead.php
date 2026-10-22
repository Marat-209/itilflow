<?php

namespace GlpiPlugin\Itilflow;

/** Обычное чтение UI сохранено; внутри команды возвращаются именно locking rows. */
trait RuntimeRead
{
    public function getFromDB($ID)
    {
        // В команде — текущее чтение; объект перечитается при её повторе (CurrentRead::load).
        return TransactionScope::hasActive()
            ? CurrentRead::load($this, (int) $ID) : parent::getFromDB($ID);
    }

    public function find($condition = [], $order = [], $limit = null)
    {
        return TransactionScope::hasActive()
            ? CurrentRead::find($this, $condition, is_array($order) ? $order : [$order], $limit)
            : parent::find($condition, $order, $limit);
    }
}
