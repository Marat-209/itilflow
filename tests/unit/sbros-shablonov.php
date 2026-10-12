<?php

/**
 * Установка и «Обновить» удаляют скомпилированные копии шаблонов плагина.
 *
 * В рабочем режиме GLPI 11 не перечитывает изменённый шаблон: ключ кэша — путь,
 * а не содержимое. Поэтому после обновления плагина форма оставалась старой,
 * пока администратор не выполнит cache:clear. Проверка раскладывает «старые»
 * скомпилированные копии ровно туда, где их ищет Twig (оба значения флага
 * отладки), и ждёт, что TemplateCache::clear уберёт их и только их.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\TemplateCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

if (!estKlass(TemplateCache::class)) {
    itog();
}

$plagin = dirname(__DIR__, 2);
$kesh = sys_get_temp_dir() . '/itilflow-kesh-' . bin2hex(random_bytes(4)) . '/';

// Как в TemplateRenderer: корень — каталог GLPI, пространство плагина зарегистрировано.
$okruzhenie = static function () use ($plagin, $kesh): Environment {
    $loader = new FilesystemLoader([], '/var/www/glpi');
    $loader->addPath($plagin . '/templates', 'itilflow');
    return new Environment($loader, ['cache' => $kesh, 'debug' => false, 'auto_reload' => false]);
};

$shablony = array_map('basename', glob($plagin . '/templates/*.html.twig'));
ok(count($shablony) >= 9, 'шаблоны плагина найдены', (string) count($shablony));

// Раскладываем «старые копии» для обоих флагов отладки и одну чужую.
$env = $okruzhenie();
$fajly = [];
foreach ([false, true] as $otladka) {
    $otladka ? $env->enableDebug() : $env->disableDebug();
    foreach ($shablony as $imya) {
        $put = $env->getCache(false)->generateKey('@itilflow/' . $imya, $env->getTemplateClass('@itilflow/' . $imya));
        @mkdir(dirname($put), 0777, true);
        file_put_contents($put, '<?php // старая копия');
        $fajly[] = $put;
    }
}
$chuzhoj = $kesh . 'zz/chuzhoj.php';
@mkdir(dirname($chuzhoj), 0777, true);
file_put_contents($chuzhoj, '<?php // шаблон GLPI');
ok(count(array_unique($fajly)) === 2 * count($shablony), 'у двух флагов отладки разные копии');

// Свежее окружение, как при установке из консоли: флаг отладки выключен.
$env = $okruzhenie();
$udaleno = TemplateCache::clear($env);
$ostalos = array_values(array_filter($fajly, 'file_exists'));
ok($ostalos === [], 'старые копии шаблонов плагина удалены', implode(', ', $ostalos));
ok($udaleno === count($fajly), 'возвращено число удалённых копий', (string) $udaleno);
ok(is_file($chuzhoj), 'чужие скомпилированные шаблоны не тронуты');
ok($env->isDebug() === false, 'флаг отладки окружения восстановлен');

// Повторный вызов — нечего удалять, без ошибок.
ok(TemplateCache::clear($env) === 0, 'повторный сброс ничего не находит и не падает');

// Пространство @itilflow не зарегистрировано (при «Обновить» плагин неактивен).
$loader = new FilesystemLoader([], '/var/www/glpi');
$env = new Environment($loader, ['cache' => $kesh, 'debug' => true, 'auto_reload' => false]);
$put = $env->getCache(false)->generateKey('@itilflow/stage.html.twig',
    (static function () use ($plagin, $kesh): string {
        $l = new FilesystemLoader([], '/var/www/glpi');
        $l->addPath($plagin . '/templates', 'itilflow');
        $e = new Environment($l, ['cache' => $kesh, 'debug' => true, 'auto_reload' => false]);
        return $e->getTemplateClass('@itilflow/stage.html.twig');
    })());
file_put_contents($put, '<?php // старая копия');
ok(TemplateCache::clear($env) === 1 && !is_file($put),
    'без пространства @itilflow оно добавляется, копия удаляется');
ok($env->isDebug() === true, 'включённый флаг отладки восстановлен');

// Сбой не роняет установку: исключение ловится, причина уходит в журнал.
$zhurnal = [];
$slomannoe = new class ($loader) extends Environment {
    public function getTemplateClass(string $name, ?int $index = null): string
    {
        throw new \RuntimeException('кэш недоступен');
    }
};
$itog = TemplateCache::clear($slomannoe, static function (string $tekst) use (&$zhurnal): void {
    $zhurnal[] = $tekst;
});
ok($itog === -1, 'при сбое возвращается -1, исключения нет');
ok(count($zhurnal) === 1 && str_contains($zhurnal[0], 'кэш недоступен'), 'причина сбоя записана в журнал');

// Установка вызывает сброс; ошибка сброса не прерывает её.
$hook = (string) file_get_contents($plagin . '/hook.php');
ok(str_contains($hook, 'TemplateCache::clear('), 'plugin_itilflow_install вызывает TemplateCache::clear');

itog();
