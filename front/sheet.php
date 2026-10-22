<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Itilflow\Authorizer;
use GlpiPlugin\Itilflow\Instance;
use GlpiPlugin\Itilflow\Sheet;
use GlpiPlugin\Itilflow\Ui;

Session::checkCentralAccess();

$instance = new Instance();
if (!$instance->getFromDB((int) ($_GET['id'] ?? 0))) {
    throw new \Glpi\Exception\Http\BadRequestHttpException('Страница открыта неверно. Вернитесь к заявке и повторите.');
}
$item = $instance->getItilItem();
// Заявку в корзине сотрудник с правом чтения видит в GLPI — и её лист согласования тоже.
if ($item === null || (Authorizer::instanceError($instance, 'read') !== null && !Ui::trashReadable($instance, $item))) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::popHeader('Лист согласования', $_SERVER['PHP_SELF']);

TemplateRenderer::getInstance()->display('@itilflow/sheet.html.twig', [
    'header' => Sheet::header($instance, $item),
    'rows'   => Sheet::rows($instance),
]);

Html::popFooter();
