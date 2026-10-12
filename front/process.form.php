<?php

use GlpiPlugin\Itilflow\Process;

Session::checkRight('plugin_itilflow_process', READ);

$process = new Process();

if (isset($_POST['add'])) {
    $process->check(-1, CREATE, $_POST);
    $newid = $process->add($_POST);
    if ($newid) {
        Html::redirect(Plugin::getWebDir('itilflow') . '/front/process.form.php?id=' . $newid);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $process->check((int) $_POST['id'], UPDATE);
    $process->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $process->check((int) $_POST['id'], PURGE);
    $process->delete($_POST, true);
    Html::redirect(Plugin::getWebDir('itilflow') . '/front/process.php');
} elseif (isset($_POST['convert'])) {
    // «Перевести на новый порядок»: право изменения маршрута, ключ CSRF проверяет GLPI.
    $process->check((int) $_POST['id'], UPDATE);
    $process->convertToStructured();
    Html::back();
}

$id = (int) ($_GET['id'] ?? -1);

Html::header(
    Process::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'admin',
    Process::class
);

$process->display(['id' => $id]);

Html::footer();
