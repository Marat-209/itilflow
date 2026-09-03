<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Sheet;

Session::checkCentralAccess();

$instance = new Instance();
if (!$instance->getFromDB((int) ($_GET['id'] ?? 0))) {
    throw new \Glpi\Exception\Http\BadRequestHttpException('Неизвестный маршрут');
}
$item = $instance->getItilItem();
if ($item === null || !$item->can($item->getID(), READ)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::popHeader('Лист согласования', $_SERVER['PHP_SELF']);

TemplateRenderer::getInstance()->display('@itilflow/sheet.html.twig', [
    'header' => Sheet::header($instance, $item),
    'rows'   => Sheet::rows($instance),
]);

Html::popFooter();
