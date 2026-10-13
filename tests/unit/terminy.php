<?php

/**
 * Термины 1.9.0: тексты плагина, которые видит человек, — словами русского перевода
 * GLPI 11 и русской терминологии ITSM, без калек и бытовых слов.
 *
 * Проверяются шаблоны (без комментариев Twig, HTML и скриптов) и строковые
 * литералы src/ и front/ (без комментариев PHP). Список — то, что исправлено
 * в 1.9.0, чтобы не вернулось:
 * - «сущность» — в GLPI Entity называется «Организация»;
 * - «виза» — в GLPI это «Согласование», «Запрос на согласование», «Одобрить»;
 * - «плашка», «маршрут запускается», «очередь этапов» (о порядке) — жаргон и кальки;
 * - «Настройка → …» — пункт меню GLPI называется «Настройки»;
 * - «инициатор» в шаблонах — в плагине роль называется «заявитель».
 */

require __DIR__ . '/_nachalo.php';

$koren = dirname(__DIR__, 2);

$obshchie = [
    '/(?<![а-яё])сущност/iu' => '«сущность» — в GLPI «Организация»',
    '/(?<![а-яё])виз(а|ы|у|е|ой|ам|ами|ах)?(?![а-яё])/iu' => '«виза» — в GLPI «согласование» / «запрос на согласование»',
    '/плашк/iu' => '«плашка» — жаргон',
    '/запуска(ет|ют)ся|запустится/iu' => '«маршрут запускается» — калька',
    '/очеред[ьи] этапов/iu' => '«очередь этапов» о порядке этапов',
    '/Настройка →/u' => 'меню GLPI — «Настройки →»',
    '/тикет|апрув|аппрув|деплой/iu' => 'жаргон',
];
$shablony = $obshchie + [
    '/(?<![а-яё])инициатор/iu' => '«инициатор» — в плагине «заявитель»',
];

/** Текст шаблона, который увидит человек: без комментариев Twig и HTML и без скриптов. */
function vidimyjTekst(string $twig): string
{
    $t = preg_replace('/\{#.*?#\}/su', ' ', $twig);
    $t = preg_replace('/<!--.*?-->/su', ' ', $t);
    return preg_replace('/<script\b.*?<\/script>/su', ' ', $t);
}

/** Строковые литералы PHP-файла с номерами строк, без комментариев. */
function literaly(string $php): array
{
    $out = [];
    foreach (token_get_all($php) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $out[] = [$tok[2], $tok[1]];
        }
    }
    return $out;
}

$nashli = [];
$fajlov = 0;
foreach (glob($koren . '/templates/*.twig') as $f) {
    $fajlov++;
    $tekst = vidimyjTekst((string) file_get_contents($f));
    foreach ($shablony as $rx => $pochemu) {
        if (preg_match_all($rx, $tekst, $m)) {
            $nashli[] = 'templates/' . basename($f) . ': ' . implode(', ', array_unique($m[0])) . ' — ' . $pochemu;
        }
    }
}
foreach (array_merge(glob($koren . '/src/*.php'), glob($koren . '/front/*.php')) as $f) {
    $fajlov++;
    foreach (literaly((string) file_get_contents($f)) as [$stroka, $lit]) {
        foreach ($obshchie as $rx => $pochemu) {
            if (preg_match($rx, $lit, $m)) {
                $nashli[] = basename(dirname($f)) . '/' . basename($f) . ':' . $stroka . ': «' . $m[0] . '» — ' . $pochemu;
            }
        }
    }
}

ok($fajlov > 30, 'проверено файлов: ' . $fajlov);
ok($nashli === [], 'тексты плагина — словами GLPI и ITSM, без жаргона', PHP_EOL . '    ' . implode(PHP_EOL . '    ', $nashli));

itog();
