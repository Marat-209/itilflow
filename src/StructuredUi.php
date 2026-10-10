<?php

namespace GlpiPlugin\Itilflow;

/** Связь дочерней заявки отдела с её этапом; вид строит Ui (одна таблица маршрута). */
final class StructuredUi
{
    public static function childStep(\CommonITILObject $item): ?Step
    {
        if (Authorizer::itemError($item, READ) !== null) { return null; }
        $step = Step::getByArtifact($item->getType(), $item->getID());
        $instance = $step?->getInstance();
        return $instance !== null && DefinitionSnapshot::enabled($instance)
            && StructuredAccess::child($step) !== null ? $step : null;
    }
}
