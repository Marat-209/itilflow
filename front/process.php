<?php

use GlpiPlugin\Itilflow\Process;

Session::checkRight('plugin_itilflow_process', READ);

Html::header(
    Process::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'admin',
    Process::class
);

// Предупреждение только здесь, администратору: на заявках людям служебные тексты не показываются.
$sync = new CronTask();
if ($sync->getFromDBbyName('GlpiPlugin\\Itilflow\\Cron', 'itilflow_status_sync') && (int) $sync->fields['state'] === CronTask::STATE_DISABLE) {
    echo '<div class="alert alert-warning">Автоматическое действие <b>itilflow_status_sync</b> выключено: статус заявки '
        . 'по этапам («Ожидание», «Согласование») не меняется. Включите его: Настройка → Автоматические действия '
        . '(нужен системный запуск cron.php раз в минуту).</div>';
}

Search::show(Process::class);

Html::footer();
