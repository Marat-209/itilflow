<?php

/**
 * С базой, только чтение: ключи, которые посчитает TemplateCache в консоли,
 * совпадают с файлами, которые скомпилировал веб-сервер. Ничего не удаляет.
 * Запуск — tests/zapusk-stend.sh kesh-shablonov-chtenie.
 */

// Ключи считаются для шаблонов установленного плагина — копию ветки не подставляем.
define('ITILFLOW_SHABLONY_USTANOVLENNYE', true);
require __DIR__ . '/_nachalo.php';

use Glpi\Application\View\TemplateRenderer;

$env = TemplateRenderer::getInstance()->getEnvironment();
$cache = $env->getCache(false);
ok(method_exists($cache, 'generateKey'), 'кэш шаблонов GLPI — файловый');
$najdeno = 0;
$vsego = 0;
foreach (glob('/var/www/glpi/plugins/itilflow/templates/*.html.twig') as $f) {
    $name = '@itilflow/' . basename($f);
    $vsego++;
    $env->disableDebug();
    $put = $cache->generateKey($name, $env->getTemplateClass($name));
    $najdeno += is_file($put) ? 1 : 0;
}
echo 'инфо: скомпилированных копий в рабочем кэше найдено ' . $najdeno . ' из ' . $vsego . PHP_EOL;
ok($najdeno > 0, 'ключи консоли совпадают с копиями веб-сервера');
itog();
