<?php

use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Stage;

Session::checkRight('plugin_itilflow_process', READ);

$stage = new Stage();

$backToProcess = static function (int $processes_id): void {
    Html::redirect(
        Plugin::getWebDir('itilflow')
        . '/front/process.form.php?id=' . $processes_id
        . '&forcetab=' . urlencode(Stage::class . '$1')
    );
};

if (isset($_POST['add'])) {
    $stage->check(-1, CREATE, $_POST);
    $stage->add($_POST);
    $backToProcess((int) $_POST['plugin_itilflow_processes_id']);
} elseif (isset($_POST['update'])) {
    $stage->check((int) $_POST['id'], UPDATE);
    $stage->update($_POST);
    $backToProcess((int) $_POST['plugin_itilflow_processes_id']);
} elseif (isset($_POST['purge'])) {
    $stage->check((int) $_POST['id'], PURGE);
    $pid = (int) $_POST['plugin_itilflow_processes_id'];
    $stage->delete($_POST, true);
    $backToProcess($pid);
}

$id = (int) ($_GET['id'] ?? -1);
$processes_id = (int) ($_GET['plugin_itilflow_processes_id'] ?? 0);
if ($id > 0 && $stage->getFromDB($id)) {
    $processes_id = (int) $stage->fields['plugin_itilflow_processes_id'];
}

Html::header(
    Stage::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'admin',
    Process::class
);

$stage->display([
    'id'                           => $id,
    'plugin_itilflow_processes_id' => $processes_id,
]);

Html::footer();
