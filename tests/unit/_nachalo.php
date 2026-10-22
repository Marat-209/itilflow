<?php

/**
 * Общее начало «чистых» проверок: GLPI без базы и без сети, плагин — из рабочей копии.
 *
 * Запуск — в одноразовом контейнере образа GLPI, рабочая копия смонтирована
 * только для чтения туда, где GLPI ищет плагин (см. tests/zapusk-unit.sh).
 * Каждая проверка печатает строки «ok …» или «НЕ ok …» и в конце итог;
 * код выхода 0 — все проверки прошли.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require '/var/www/glpi/vendor/autoload.php';

// Классы плагина — из рабочей копии, раньше любых других загрузчиков.
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Itilflow\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

$GLOBALS['itilflow_proval'] = 0;

function ok(bool $uslovie, string $chto, string $podrobno = ''): void
{
    echo ($uslovie ? 'ok ' : 'НЕ ok ') . $chto . ($uslovie || $podrobno === '' ? '' : ' — ' . $podrobno) . PHP_EOL;
    if (!$uslovie) {
        $GLOBALS['itilflow_proval']++;
    }
}

/** Сведения без проверки. */
function info(string $tekst): void
{
    echo 'инфо: ' . $tekst . PHP_EOL;
}

function itog(): void
{
    $n = $GLOBALS['itilflow_proval'];
    echo ($n ? 'ПРОВАЛ: ' . $n : 'ВСЁ ok') . PHP_EOL;
    exit($n ? 1 : 0);
}

/** Класс плагина есть в рабочей копии — иначе проверка красная, а не фатальная ошибка. */
function estKlass(string $class): bool
{
    $est = class_exists($class);
    ok($est, 'класс ' . $class . ' есть');
    return $est;
}
