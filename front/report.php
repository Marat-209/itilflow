<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Report;

Session::checkRight('plugin_itilflow_process', READ);

$processes = [];
foreach ((new Process())->find([], ['name ASC']) as $id => $p) {
    $processes[(int) $id] = $p['name'];
}

$pid = (int) ($_GET['processes_id'] ?? 0);
if ($pid <= 0 && count($processes)) {
    $pid = (int) array_key_first($processes);
}

Html::header(
    'Узкие места маршрутов',
    $_SERVER['PHP_SELF'],
    'admin',
    Process::class
);

TemplateRenderer::getInstance()->display('@itilflow/report.html.twig', [
    'processes' => $processes,
    'pid'       => $pid,
    'stages'    => $pid > 0 ? Report::byStage($pid) : [],
    'summary'   => $pid > 0 ? Report::summary($pid) : [],
    'modes'     => \GlpiPlugin\Itilflow\Stage::getModeLabels(),
]);

Html::footer();
