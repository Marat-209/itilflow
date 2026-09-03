<?php

use GlpiPlugin\Itilflow\Process;

Session::checkRight('plugin_itilflow_process', READ);

Html::header(
    Process::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'admin',
    Process::class
);

Search::show(Process::class);

Html::footer();
