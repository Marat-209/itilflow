<?php

use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Transfer;
use Symfony\Component\HttpFoundation\HeaderUtils;

// Экспорт маршрутов в файл JSON — как экспорт форм GLPI 11 (/Form/Export): право чтения
// маршрутов; в файл попадают только маршруты, которые пользователь видит. Без ids — все
// видимые маршруты, с ids[] — выбранные (массовое действие «Экспорт» в списке).
Session::checkRight('plugin_itilflow_process', READ);

if (isset($_GET['ids'])) {
    $ids = Transfer::requestIds($_GET['ids']);
} else {
    $ids = array_keys((new Process())->find(
        getEntitiesRestrictCriteria(Process::getTable(), '', '', true),
        ['name', 'id']
    ));
}
$processes = [];
foreach ($ids as $id) {
    $process = new Process();
    if ($process->can($id, READ)) {
        $processes[] = $process;
    }
}
if ($processes === []) {
    Session::addMessageAfterRedirect('Нет маршрутов для экспорта.', false, WARNING);
    Html::redirect(Process::getSearchURL());
}

$export = Transfer::export($processes);
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . HeaderUtils::makeDisposition(
    HeaderUtils::DISPOSITION_ATTACHMENT,
    $export['filename'],
    'itilflow-routes.json'
));
echo $export['json'];
