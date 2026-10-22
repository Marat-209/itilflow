<?php

namespace GlpiPlugin\Itilflow;

use Glpi\Application\View\TemplateRenderer;
use Twig\Cache\RemovableCacheInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Сброс скомпилированных копий шаблонов плагина.
 *
 * В рабочем режиме GLPI 11 не перечитывает изменённый шаблон: ключ кэша Twig —
 * путь шаблона и набор опций, а не содержимое файла. «Плагины → Обновить»
 * кэш шаблонов не трогает, поэтому после обновления форма оставалась старой,
 * пока не выполнят `php bin/console cache:clear`. Установка плагина удаляет
 * свои копии сама — для обоих значений флага отладки: он входит в ключ.
 * `cache:clear` в инструкции остаётся: копии, собранные другой версией PHP
 * (консоль и веб-сервер), здесь не найти.
 */
final class TemplateCache
{
    /**
     * @param Environment|null $env окружение Twig; по умолчанию — окружение GLPI
     * @param callable|null    $log куда писать причину сбоя; по умолчанию журнал GLPI itilflow.log
     * @return int число удалённых копий; -1 — сбой (установка продолжается)
     */
    public static function clear(?Environment $env = null, ?callable $log = null): int
    {
        $debug = null;
        try {
            $env ??= TemplateRenderer::getInstance()->getEnvironment();
            $debug = $env->isDebug();
            $dir = dirname(__DIR__) . '/templates';
            $loader = $env->getLoader();
            // При «Обновить» плагин неактивен, и GLPI не регистрирует пространство @itilflow.
            if ($loader instanceof FilesystemLoader && !in_array('itilflow', $loader->getNamespaces(), true)) {
                $loader->addPath($dir, 'itilflow');
            }
            $cache = $env->getCache(false);
            if (!$cache instanceof RemovableCacheInterface) {
                return 0;
            }
            $names = array_map(static fn(string $f): string => '@itilflow/' . basename($f),
                glob($dir . '/*.html.twig') ?: []);
            // Имена классов считаются заранее для обоих флагов: removeCache() Twig
            // подменяет ключ шаблона в памяти, и второй флаг посчитался бы неверно.
            $classes = [];
            foreach ([false, true] as $flag) {
                $flag ? $env->enableDebug() : $env->disableDebug();
                foreach ($names as $name) {
                    $classes[] = [$name, $env->getTemplateClass($name)];
                }
            }
            $removed = 0;
            foreach ($classes as [$name, $class]) {
                $file = method_exists($cache, 'generateKey') ? $cache->generateKey($name, $class) : '';
                $existed = $file !== '' && is_file($file);
                $cache->remove($name, $class);
                $removed += $existed && !is_file($file) ? 1 : 0;
            }
            return $removed;
        } catch (\Throwable $e) {
            TransactionScope::assertHealthy();
            $text = 'itilflow: скомпилированные шаблоны не сброшены (' . $e->getMessage()
                . '). Выполните php bin/console cache:clear.';
            if ($log !== null) {
                $log($text);
            } else {
                \Toolbox::logInFile('itilflow', $text . PHP_EOL, true, false);
            }
            return -1;
        } finally {
            if ($env !== null && $debug !== null) {
                $debug ? $env->enableDebug() : $env->disableDebug();
            }
        }
    }
}
