<?php

/**
 * Общее начало проверок с базой: GLPI с данными, плагин — из копии рабочей ветки.
 *
 * Запуск — tests/zapusk-stend.sh: копия ветки кладётся во временный каталог
 * контейнера GLPI, проверка идёт от веб-пользователя. Классы и шаблоны плагина
 * берутся из копии, раньше установленных; кэш шаблонов не пишется. Всё, что
 * проверка меняет в базе, откатывается: она целиком идёт в одной транзакции.
 * Данных конкретной установки в проверках нет — они перебирают то, что найдут.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$GLOBALS['itilflow_koren'] = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Itilflow\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $GLOBALS['itilflow_koren'] . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
$itilflow_uroven = ob_get_level();
ob_start();
(new \Glpi\Kernel\Kernel())->boot();
while (ob_get_level() > $itilflow_uroven) {
    ob_end_clean();
}

if (!defined('ITILFLOW_SHABLONY_USTANOVLENNYE')) {
    $env = \Glpi\Application\View\TemplateRenderer::getInstance()->getEnvironment();
    $env->setCache(false);
    $env->getLoader()->prependPath($GLOBALS['itilflow_koren'] . '/templates', 'itilflow');
}

/** @var \DBmysql $DB */
global $DB;
$DB->beginTransaction();
register_shutdown_function(static function (): void {
    global $DB;
    // Уровень вложенности у DBmysql закрытый; откатываем все уровни до нуля.
    $uroven = new \ReflectionProperty(\DBmysql::class, 'transaction_level');
    while ((int) $uroven->getValue($DB) > 0) {
        $DB->rollBack();
    }
});

// Вывод проверки копится до конца: вход под пользователем открывает сессию PHP,
// а она требует, чтобы до неё ничего не было напечатано.
ob_start();

$GLOBALS['itilflow_proval'] = 0;

function ok(bool $uslovie, string $chto, string $podrobno = ''): void
{
    echo ($uslovie ? 'ok ' : 'НЕ ok ') . $chto . ($uslovie || $podrobno === '' ? '' : ' — ' . $podrobno) . PHP_EOL;
    if (!$uslovie) {
        $GLOBALS['itilflow_proval']++;
    }
}

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

function estKlass(string $class): bool
{
    $est = class_exists($class);
    ok($est, 'класс ' . $class . ' есть');
    return $est;
}

/** Первый активный пользователь с правом «Настройка: изменение» — администратор GLPI. */
function adminGlpi(): int
{
    global $DB;
    foreach ($DB->request([
        'SELECT' => ['pu.users_id', 'pu.profiles_id'],
        'FROM'   => 'glpi_profiles_users AS pu',
        'INNER JOIN' => [
            'glpi_profilerights AS pr' => ['ON' => ['pr' => 'profiles_id', 'pu' => 'profiles_id']],
            'glpi_users AS u' => ['ON' => ['u' => 'id', 'pu' => 'users_id']],
        ],
        'WHERE' => ['pr.name' => 'config', 'u.is_active' => 1, 'u.is_deleted' => 0,
            new \Glpi\DBAL\QueryExpression('(' . $DB->quoteName('pr.rights') . ' & ' . UPDATE . ') > 0')],
        'ORDER' => 'pu.users_id',
        'LIMIT' => 1,
    ]) as $row) {
        return (int) $row['users_id'];
    }
    return 0;
}

/**
 * Сессия пользователя. $kak: 'glpi' — администратор GLPI (право «Настройка»),
 * 'process' — администратор процессов: права плагина есть, «Настройки» нет.
 */
function vojti(int $uid, string $kak = 'glpi'): void
{
    $auth = new \Auth();
    $auth->user->getFromDB($uid);
    $auth->auth_succeded = true;
    \Session::init($auth);
    $_SESSION['glpiactiveprofile']['plugin_itilflow_process'] = ALLSTANDARDRIGHT;
    if ($kak === 'process') {
        $_SESSION['glpiactiveprofile']['config'] = 0;
    }
    \Session::loadLanguage('ru_RU');
}

/** Вывод функции, которая печатает HTML. */
function vyvod(callable $f): string
{
    $uroven = ob_get_level();
    ob_start();
    try {
        $f();
    } finally {
        $html = '';
        while (ob_get_level() > $uroven) {
            $html = ob_get_clean() . $html;
        }
    }
    return $html;
}

function dom(string $html): \DOMXPath
{
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><div id="itilflow-proverka">' . $html . '</div>');
    return new \DOMXPath($dom);
}

/** Элемент или его предок скрыт атрибутом hidden. */
function skryt(\DOMNode $n): bool
{
    for (; $n instanceof \DOMElement; $n = $n->parentNode) {
        if ($n->hasAttribute('hidden')) {
            return true;
        }
    }
    return false;
}

/**
 * Человек меняет поля формы этапа или маршрута, скрипт формы скрывает и отключает
 * лишнее, браузер отправляет. Правила видимости берутся из атрибутов data-itilflow-*
 * разметки — так же, как их читает скрипт шаблона.
 *
 * @param array<string,mixed> $izmenit имя поля => значение (для списков галочек — массив)
 */
function kakBrauzer(\DOMXPath $x, string $form, array $izmenit): array
{
    $f = $x->query($form)->item(0);
    $statusPoRezhimu = null;
    foreach ($izmenit as $name => $value) {
        $spisok = is_array($value);
        $q = $form . '//*[@name="' . $name . ($spisok ? '[]' : '') . '"]';
        foreach ($x->query($q) as $el) {
            /** @var \DOMElement $el */
            $type = strtolower($el->getAttribute('type'));
            if ($type === 'radio' || $type === 'checkbox') {
                $on = $spisok ? in_array($el->getAttribute('value'), array_map('strval', $value), true)
                    : $el->getAttribute('value') === (string) $value;
                $on ? $el->setAttribute('checked', 'checked') : $el->removeAttribute('checked');
            } elseif ($el->nodeName === 'select') {
                $est = false;
                foreach ($x->query('.//option', $el) as $o) {
                    $o->removeAttribute('selected');
                    if ($o->getAttribute('value') === (string) $value) {
                        $o->setAttribute('selected', 'selected');
                        $est = true;
                    }
                }
                if (!$est) {
                    // Выпадающие списки GLPI с поиском приносят варианты по запросу.
                    $o = $el->ownerDocument->createElement('option', (string) $value);
                    $o->setAttribute('value', (string) $value);
                    $o->setAttribute('selected', 'selected');
                    $el->appendChild($o);
                }
            } elseif ($el->nodeName === 'textarea') {
                $el->textContent = (string) $value;
            } else {
                $el->setAttribute('value', (string) $value);
            }
        }
        if ($name === 'execution_mode') {
            $statusPoRezhimu = (string) $value;
        }
    }
    // Статус нового этапа — по режиму, если его не выбрали руками.
    $sbm = $x->query($form . '//*[@data-itilflow-status-by-mode]')->item(0);
    if ($sbm !== null && $statusPoRezhimu !== null && !array_key_exists('ticket_status', $izmenit)) {
        $map = json_decode($sbm->getAttribute('data-itilflow-status-by-mode'), true);
        return kakBrauzer($x, $form, $izmenit + ['ticket_status' => (string) ($map[$statusPoRezhimu] ?? 0)]);
    }
    $znach = static function (string $name) use ($x, $form): string {
        $r = $x->query($form . '//input[@name="' . $name . '" and @checked]')->item(0);
        if ($r !== null) {
            return $r->getAttribute('value');
        }
        $s = $x->query($form . '//select[@name="' . $name . '"]//option[@selected]')->item(0);
        return $s !== null ? $s->getAttribute('value') : '';
    };
    $s = [
        'mode' => $znach('execution_mode') ?: 'inline',
        'approver' => $znach('approver_source') ?: 'fixed',
        'where' => $znach('entity_strategy') ?: 'inherit',
        'result' => $znach('department_result_source'),
        'onrej' => $znach('on_reject') ?: 'block',
    ];
    $structured = $f instanceof \DOMElement && $f->getAttribute('data-itilflow-structured') === '1';
    foreach ($x->query($form . '//*[@data-itilflow-m] | ' . $form . '//*[@data-itilflow-c]') as $el) {
        $m = $el->getAttribute('data-itilflow-m');
        $c = $el->getAttribute('data-itilflow-c');
        $vid = ($m === '' || in_array($s['mode'], explode(' ', $m), true)) && match ($c) {
            'apfixed' => $s['mode'] !== 'approval' || $s['approver'] === 'fixed',
            'wherefixed' => $s['where'] === 'fixed',
            'back' => $s['onrej'] === 'back',
            'visa' => $s['mode'] === 'approval' || ($structured && $s['mode'] === 'delegated' && $s['result'] === 'native_validation'),
            default => true,
        };
        $vid ? $el->removeAttribute('hidden') : $el->setAttribute('hidden', 'hidden');
    }
    foreach ($x->query($form . '//*[@data-itilflow-field]') as $pole) {
        $off = skryt($pole);
        foreach ($x->query('.//input | .//select | .//textarea', $pole) as $c) {
            $off ? $c->setAttribute('disabled', 'disabled') : $c->removeAttribute('disabled');
        }
    }
    return otpravka($x, $form);
}

/**
 * Что отправит браузер: только включённые поля формы. Скрытое по режиму поле
 * отключено (disabled) и не уходит; свёрнутое «Дополнительно» уходит.
 *
 * @return array<string,mixed> имя => значение; имена с [] — списки
 */
function otpravka(\DOMXPath $x, string $form = '//form'): array
{
    $out = [];
    foreach ($x->query($form . '//input | ' . $form . '//select | ' . $form . '//textarea') as $el) {
        /** @var \DOMElement $el */
        $name = $el->getAttribute('name');
        if ($name === '' || $el->hasAttribute('disabled')) {
            continue;
        }
        $type = strtolower($el->getAttribute('type'));
        if (in_array($type, ['submit', 'button'], true)) {
            continue;
        }
        if (in_array($type, ['radio', 'checkbox'], true) && !$el->hasAttribute('checked')) {
            continue;
        }
        if ($el->nodeName === 'select') {
            $vals = [];
            foreach ($x->query('.//option', $el) as $o) {
                if ($o->hasAttribute('selected')) {
                    $vals[] = $o->getAttribute('value');
                }
            }
            if (!$vals && !$el->hasAttribute('multiple')) {
                $first = $x->query('.//option', $el)->item(0);
                $vals = $first ? [$first->getAttribute('value')] : [];
            }
            $value = $el->hasAttribute('multiple') ? $vals : ($vals[0] ?? '');
        } elseif ($el->nodeName === 'textarea') {
            $value = $el->textContent;
        } else {
            $value = $el->getAttribute('value');
        }
        if (str_ends_with($name, '[]')) {
            $key = substr($name, 0, -2);
            $out[$key] = array_merge($out[$key] ?? [], (array) $value);
        } else {
            $out[$name] = $value;
        }
    }
    return $out;
}
