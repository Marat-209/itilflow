<?php

/**
 * Словарь — GlpiPlugin\Itilflow\Terms (слово GLPI, иначе простое русское;
 * выбраны «инициатор заявки», «пользователь», «этап согласования»,
 * «администратор маршрутов», меню «Маршруты этапов»). Здесь — запрещённые слова Terms::FORBIDDEN.
 *
 * Проверяются: шаблоны (без комментариев Twig/HTML и скриптов) и строковые литералы src/ и front/
 * без комментариев, без текстов исключений (throw …) и без журнала (logInFile, error_log).
 * src/Terms.php не проверяется: в нём сам список. Единственное исключение — три служебные строки
 * со словом «порог» (см. $porogPoisk ниже).
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Terms;

if (!estKlass(Terms::class)) {
    itog();
}

$koren = dirname(__DIR__, 2);

function vidimyjTekst113(string $twig): string
{
    $t = preg_replace('/\{#.*?#\}/su', ' ', $twig);
    $t = preg_replace('/<!--.*?-->/su', ' ', $t);
    return preg_replace('/<script\b.*?<\/script>/su', ' ', $t);
}

/** Литералы, которые может увидеть человек: без throw-выражений и записей журнала. */
function vidimyeLiteraly(string $php): array
{
    $out = [];
    $sluzhebnoe = false;
    foreach (token_get_all($php) as $tok) {
        if (!is_array($tok)) {
            if ($tok === ';') {
                $sluzhebnoe = false;
            }
            continue;
        }
        if ($tok[0] === T_THROW || ($tok[0] === T_STRING && in_array($tok[1], ['logInFile', 'error_log'], true))) {
            $sluzhebnoe = true;
        }
        if (!$sluzhebnoe && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $out[] = [$tok[2], $tok[1]];
        }
    }
    return $out;
}

/**
 * Узкое исключение (стык Г → В): служебные имена записей «Этап согласования» GLPI до 1.13 со словом
 * «порог». Плагин по ним находит свои прежние записи (Engine::ensureValidationStep,
 * ValidationLadder::legacyName и ::parse) — без них появятся дубли и потеряется nextRun.
 * Новые имена пишутся словами «нужно N %». Исключение — по файлу и точному тексту литерала,
 * только для правила «порог»; любое другое место со словом «порог» остаётся ошибкой.
 */
$porogPoisk = [
    'src/Engine.php' => ['Этап маршрута — порог %d%%'],
    'src/ValidationLadder.php' => [' — порог ', '(?:, запуск ([2-9]|[1-9][0-9]{1,8}))? — (?:нужно|порог) (0|[1-9][0-9]?|100) %$/uD'],
];
$porogPravilo = '/(?<![а-яё])порог/iu';
$porogPropushcheno = [];

$nashli = [];
$fajlov = 0;
foreach (glob($koren . '/templates/*.twig') as $f) {
    $fajlov++;
    $tekst = vidimyjTekst113((string) file_get_contents($f));
    foreach (Terms::FORBIDDEN as $rx => $zamena) {
        if (preg_match_all($rx, $tekst, $m)) {
            $nashli[] = 'templates/' . basename($f) . ': «' . implode('», «', array_unique($m[0])) . '» → ' . $zamena;
        }
    }
}
foreach (array_merge(glob($koren . '/src/*.php'), glob($koren . '/front/*.php')) as $f) {
    if (basename($f) === 'Terms.php') {
        continue;
    }
    $fajlov++;
    $imya = basename(dirname($f)) . '/' . basename($f);
    foreach (vidimyeLiteraly((string) file_get_contents($f)) as [$stroka, $lit]) {
        foreach (Terms::FORBIDDEN as $rx => $zamena) {
            if (!preg_match($rx, $lit, $m)) {
                continue;
            }
            $tekst = strlen($lit) >= 2 && in_array($lit[0], ["'", '"'], true) && $lit[-1] === $lit[0] ? substr($lit, 1, -1) : $lit;
            if ($rx === $porogPravilo && in_array($tekst, $porogPoisk[$imya] ?? [], true)) {
                $porogPropushcheno[] = $imya . ':' . $stroka;
                continue;
            }
            $nashli[] = $imya . ':' . $stroka . ': «' . $m[0] . '» → ' . $zamena;
        }
    }
}

ok($fajlov > 30, 'проверено файлов: ' . $fajlov);
ok(array_key_exists($porogPravilo, Terms::FORBIDDEN), 'правило «порог» есть в словаре — исключение относится к нему');
ok(count($porogPropushcheno) === 3, 'исключение «порог» — ровно три служебные строки поиска прежних этапов согласования',
    implode(', ', $porogPropushcheno));
info('найдено мест: ' . count($nashli));
ok($nashli === [], 'тексты на экране — словами словаря 1.13', PHP_EOL . '    ' . implode(PHP_EOL . '    ', $nashli));

itog();
