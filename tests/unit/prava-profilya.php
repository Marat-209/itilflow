<?php

/**
 * 1.13 · права плагина в профиле GLPI — вкладка «Маршруты этапов» (без базы).
 *
 * До 1.13 у плагина не было вкладки на Profile: ядро GLPI 11 показывает в профиле только свои
 * права, и права itilflow после первой установки штатно менять было нечем. Здесь проверяется:
 * 1) setup.php регистрирует вкладку на форме профиля;
 * 2) матрица — все права плагина, какие есть в исходниках, подписи — словами словаря;
 * 3) вкладка видна только у сохранённого профиля стандартного интерфейса и только с «Профили: чтение»;
 * 4) с «Профили: изменение» — штатная форма ядра: POST в front/profile.form.php, id, кнопка update,
 *    ключ CSRF; без него — галочки только для чтения, без формы;
 * 5) поля матрицы сохраняет само ядро (Profile::prepareInputForUpdate), своей записи прав у плагина нет.
 */

require __DIR__ . '/_nachalo.php';

use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\ProfileRights;
use GlpiPlugin\Itilflow\Terms;

if (!estKlass(ProfileRights::class)) {
    itog();
}

global $DB, $PLUGIN_HOOKS, $GLPI_CACHE, $CFG_GLPI;
$CFG_GLPI['root_doc'] = $CFG_GLPI['root_doc'] ?? '';
// Базы нет: проверке прав достаточно знать, что это не реплика только для чтения.
$DB = new class {
    public function isSlave(): bool { return false; }
};
$koren = dirname(__DIR__, 2);

// 1. Вкладка на форме профиля — из setup.php рабочей копии.
require $koren . '/setup.php';
$PLUGIN_HOOKS = [];
plugin_init_itilflow();
ok(in_array(ProfileRights::class, CommonGLPI::getOtherTabs('Profile'), true), 'setup.php добавляет вкладку на форму профиля GLPI',
    implode(', ', CommonGLPI::getOtherTabs('Profile')));

// 2. Матрица — все права плагина из исходников, без лишних.
$ishodniki = '';
foreach (array_merge(glob($koren . '/src/*.php'), glob($koren . '/front/*.php'), [$koren . '/hook.php', $koren . '/setup.php']) as $f) {
    $ishodniki .= file_get_contents($f) . "\n";
}
preg_match_all("/(?:haveRight\\(|addRight\\(|\\\$rightname\\s*=\\s*)'(plugin_itilflow_[a-z_]+)'/", $ishodniki, $m1);
preg_match_all("/deleteProfileRights\\(\\[([^\\]]*)\\]/", $ishodniki, $m2);
$prava = $m1[1];
foreach ($m2[1] as $spisok) {
    preg_match_all("/'(plugin_itilflow_[a-z_]+)'/", $spisok, $m3);
    array_push($prava, ...$m3[1]);
}
$prava = array_values(array_unique($prava));
sort($prava);
$matrica = ProfileRights::matrix();
$polya = array_column($matrica, 'field');
$sorted = $polya;
sort($sorted);
ok($prava !== [] && $sorted === $prava, 'матрица вкладки — все права плагина из исходников, без лишних',
    'в исходниках: ' . implode(', ', $prava) . '; в матрице: ' . implode(', ', $polya));
foreach ($matrica as $stroka) {
    ok(is_string($stroka['label'] ?? null) && $stroka['label'] !== '' && is_string($stroka['field'] ?? null)
        && (!empty($stroka['itemtype']) || !empty($stroka['rights'])),
        'строка матрицы годится ядру: подпись, право и столбцы — ' . ($stroka['field'] ?? '?'));
}
$po = array_column($matrica, null, 'field');
ok(($po[Process::$rightname]['label'] ?? '') === Terms::MENU && ($po[Process::$rightname]['itemtype'] ?? '') === Process::class,
    '«Маршруты этапов» — столбцы прав ядра для маршрута');
ok(($po[ProfileRights::BYPASS]['label'] ?? '') === Terms::FORCE_CLOSE_RIGHT
    && array_keys($po[ProfileRights::BYPASS]['rights'] ?? []) === [READ]
    && preg_match("/haveRight\\('plugin_itilflow_bypass', READ\\)/", $ishodniki) === 1,
    '«Закрытие этапа без ответа» — тот бит, который проверяет плагин');
$teksty = implode(' ', array_merge(array_column($matrica, 'label'), array_merge(...array_map(
    static fn(array $s): array => array_values($s['rights'] ?? []), $matrica)), [ProfileRights::TAB, strip_tags(ProfileRights::explanation())]));
$zapret = [];
foreach (Terms::FORBIDDEN as $re => $zamena) {
    if (preg_match($re, $teksty)) { $zapret[] = $re . ' → ' . $zamena; }
}
ok($zapret === [], 'подписи и пояснение вкладки — словами словаря', implode('; ', $zapret));

// 3. Кому видна вкладка.
function profil(array $polya): Profile
{
    $p = new class extends Profile {
        public array $vyzovy = [];
        public function displayRightsChoiceMatrix(array $rights, array $options = [])
        {
            $this->vyzovy[] = ['prava' => $rights, 'opcii' => $options];
            echo '<table data-matrica="1"></table>';
            return 1;
        }
    };
    $p->fields = $polya;
    return $p;
}
$central = profil(['id' => 5, 'name' => 'Технический специалист', 'interface' => 'central']);
$vkladka = new ProfileRights();
$_SESSION['glpiactiveprofile'] = ['profile' => READ];
ok(ProfileRights::visible($central) && str_contains(strip_tags((string) $vkladka->getTabNameForItem($central)), ProfileRights::TAB),
    'профиль стандартного интерфейса, «Профили: чтение» — вкладка «Маршруты этапов» есть');
ok(!ProfileRights::visible(profil(['id' => 6, 'interface' => 'helpdesk'])) && $vkladka->getTabNameForItem(profil(['id' => 6, 'interface' => 'helpdesk'])) === '',
    'профиль упрощённого интерфейса — вкладки нет');
ok(!ProfileRights::visible(profil(['interface' => 'central'])), 'новый, ещё не сохранённый профиль — вкладки нет');
ok(!ProfileRights::visible(new Ticket()), 'не профиль — вкладки нет');
$_SESSION['glpiactiveprofile'] = ['profile' => 0];
ok(!ProfileRights::visible($central) && $vkladka->getTabNameForItem($central) === ''
    && ProfileRights::displayTabContentForItem($central) === false, 'без права на профили — ни вкладки, ни содержимого');
unset($_SESSION['glpiactiveprofile']['profile']);
ok(!ProfileRights::visible($central), 'право на профили не выдано вовсе — вкладки нет');

// 4. Содержимое вкладки: штатная форма ядра.
$_SESSION['glpiactiveprofile'] = ['profile' => READ | UPDATE];
$izm = profil(['id' => 5, 'interface' => 'central']);
ob_start();
$otvet = ProfileRights::displayTabContentForItem($izm);
$html = (string) ob_get_clean();
$vyzov = $izm->vyzovy[0] ?? [];
ok($otvet === true && count($izm->vyzovy) === 1 && ($vyzov['prava'] ?? null) === ProfileRights::matrix()
    && ($vyzov['opcii']['canedit'] ?? null) === true, '«Профили: изменение» — матрица ядра со всеми правами плагина, галочки меняются');
ok(preg_match('~<form[^>]+method="post"[^>]+action="[^"]*front/profile\.form\.php"~', $html) === 1,
    'форма уходит в front/profile.form.php ядра', mb_substr($html, 0, 200));
ok(preg_match('~name=["\']id["\'][^>]*value=["\']5["\']|value=["\']5["\'][^>]*name=["\']id["\']~', $html) === 1
    && preg_match('~name=["\']update["\']~', $html) === 1, 'в форме — номер профиля и кнопка update, как у вкладок ядра');
ok(preg_match('~name=["\']_glpi_csrf_token["\']~', $html) === 1 && str_contains($html, '</form>'), 'форма закрыта с ключом CSRF');

$_SESSION['glpiactiveprofile'] = ['profile' => READ];
$chten = profil(['id' => 5, 'interface' => 'central']);
ob_start();
ProfileRights::displayTabContentForItem($chten);
$html = (string) ob_get_clean();
ok(($chten->vyzovy[0]['opcii']['canedit'] ?? null) === false && !str_contains($html, '<form')
    && preg_match('~name=["\']update["\']~', $html) === 0, 'только «Профили: чтение» — галочки без изменения, без формы и кнопки');

// 5. Сохраняет ядро: поля матрицы — его поля _<право>[<бит>_<n>], Profile::prepareInputForUpdate.
$GLPI_CACHE = new class {
    public function get($key, $default = null)
    {
        return $key === 'all_possible_rights'
            ? ['ticket' => '', 'profile' => '', 'plugin_itilflow_process' => '', 'plugin_itilflow_bypass' => ''] : $default;
    }
    public function set($key, $value) { return true; }
};
$sohr = new Profile();
$sohr->fields = ['id' => 5, 'interface' => 'central'];
$vvod = $sohr->prepareInputForUpdate(['id' => 5,
    '_plugin_itilflow_process' => [READ . '_0' => 1, UPDATE . '_0' => 1, CREATE . '_0' => 1, PURGE . '_0' => 0],
    '_plugin_itilflow_bypass' => [READ . '_1' => 1]]);
$zapis = (new ReflectionProperty(Profile::class, 'profileRight'))->getValue($sohr);
ok(($zapis['plugin_itilflow_process'] ?? null) === (READ | UPDATE | CREATE) && ($zapis['plugin_itilflow_bypass'] ?? null) === READ
    && !isset($vvod['_plugin_itilflow_process']) && !isset($vvod['_plugin_itilflow_bypass']),
    'ядро само собирает галочки обоих прав плагина в права профиля', json_encode($zapis));
$svoya = [];
foreach (array_merge(glob($koren . '/src/*.php'), glob($koren . '/front/*.php')) as $f) {
    // Код без комментариев: описание вкладки называет таблицу ядра, но не пишет в неё.
    $kod = '';
    foreach (token_get_all((string) file_get_contents($f)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $kod .= is_array($t) ? $t[1] : $t;
    }
    if (preg_match('/updateProfileRights|addProfileRights|glpi_profilerights|new\s+\\\\?ProfileRight\(\)\)?->(add|update)/', $kod)) {
        $svoya[] = basename($f);
    }
}
ok($svoya === [], 'своей записи прав профиля у плагина нет — только форма ядра', implode(', ', $svoya));

itog();
