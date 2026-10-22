<?php

/**
 * Термины: сам словарь экрана и кнопка ответа согласующего.
 *
 * С 1.13 запрещённые слова — один список, GlpiPlugin\Itilflow\Terms::FORBIDDEN, и одна проверка
 * текстов — tests/unit/terminy-113.php. Сюда перенесены и запреты 1.9 («сущность», «плашка»,
 * «очередь этапов», «Настройка →», жаргон), а исключение 1.11 «Виза из формы» снято:
 * с 1.13 это «Согласование из формы». Чтобы одно правило не проверялось двумя наборами
 * по-разному, здесь тексты плагина не перебираются — проверяется, что словарь ловит то, что
 * должен, и не ловит слова GLPI и выбранные слова.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Terms;

$koren = dirname(__DIR__, 2);

if (!estKlass(Terms::class)) {
    itog();
}

/** Какие правила словаря срабатывают на текст. */
function lovyat(string $tekst): array
{
    $pravila = [];
    foreach (Terms::FORBIDDEN as $rx => $zamena) {
        if (preg_match($rx, $tekst)) {
            $pravila[] = $rx;
        }
    }
    return $pravila;
}

// Что словарь обязан ловить: запреты 1.9 и прежние слова, которые заменил словарь 1.13.
$nelzya = [
    'Виза из формы', 'в организации сущности', 'жёлтая плашка', 'очередь этапов', 'Настройка → Автоматические действия',
    'тикет', 'заявитель получит ответ', 'администратор процессов', 'Нарушение маршрута', 'Источник',
    'ступень согласования', 'шаг согласования', 'Решение отдела', 'учитываемых прохождений', 'Не потребовался',
    'Приостановлен', 'точка возврата', 'Маршруты обработки', 'Сотрудник №17', 'обновите карточку',
];
$propushcheny = array_values(array_filter($nelzya, static fn(string $t): bool => lovyat($t) === []));
ok($propushcheny === [], 'словарь ловит прежние слова и запреты 1.9', implode(' | ', $propushcheny));

// Что словарь ловить не должен: слова GLPI и выбранные слова.
$mozhno = [
    'Источник запросов', 'источника запроса', 'действие проходит', 'Маршруты этапов', 'Запустить маршрут этапов',
    'инициатор заявки', 'пользователь', 'этап согласования', 'администратор маршрутов', 'Согласование из формы',
    'Журнал маршрута', 'Не нужен по условию', 'Прежняя попытка', 'Нужно одобрений, %', 'Организация',
];
$lishnie = [];
foreach ($mozhno as $t) {
    if (($p = lovyat($t)) !== []) {
        $lishnie[] = $t . ' ← ' . implode(' ', $p);
    }
}
ok($lishnie === [], 'словарь не ловит слова GLPI и выбранные слова', implode(' | ', $lishnie));

// Константы словаря сами не нарушают словарь.
$konstanty = array_filter((new ReflectionClass(Terms::class))->getConstants(), 'is_string');
$plohie = [];
foreach ($konstanty as $imya => $slovo) {
    if (lovyat($slovo) !== []) {
        $plohie[] = $imya . ' = ' . $slovo;
    }
}
ok($plohie === [], 'слова словаря Terms проходят свои же запреты', implode(' | ', $plohie));

// Кнопка ответа согласующего — как кнопка GLPI в ленте («Одобрить» / «Отказать»).
$dejstvie = (string) file_get_contents($koren . '/templates/flow_action.html.twig');
$knopki = [];
foreach (['approve', 'refuse'] as $v) {
    $knopki[$v] = preg_match('/<button[^>]*name="approval_action"[^>]*value="' . $v . '"[^>]*>(.*?)<\/button>/su', $dejstvie, $m)
        ? trim(strip_tags($m[1])) : null;
}
ok($knopki === ['approve' => Terms::APPROVE, 'refuse' => Terms::REFUSE], 'ответ согласующего на вкладке маршрута: «Одобрить» / «Отказать», как в GLPI',
    json_encode($knopki, JSON_UNESCAPED_UNICODE));

itog();
