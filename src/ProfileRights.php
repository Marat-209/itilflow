<?php

namespace GlpiPlugin\Itilflow;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Вкладка «Маршруты этапов» в форме профиля GLPI: Администрирование → Профили → профиль.
 *
 * Ядро GLPI 11 показывает в профиле только свои права (Profile::getRightsForForm), права
 * плагина видны, только если плагин сам добавил вкладку на Profile. До 1.13 вкладки не было:
 * права раздавала лишь первая установка (hook.php, Migration::addRight), штатно менять их
 * было нечем.
 *
 * Как у ядра (templates/pages/admin/profile/base_tab.html.twig):
 * - вкладку регистрирует setup.php: Plugin::registerClass(ProfileRights::class, ['addtabon' => ['Profile']]);
 * - галочки — матрица ядра Profile::displayRightsChoiceMatrix, поля _<право>[<бит>_<n>];
 * - форма уходит в front/profile.form.php ядра кнопкой update с ключом CSRF (Html::closeForm).
 *   Ядро само проверяет ключ и право «Профили: изменение» (Profile::check(id, UPDATE)), собирает
 *   биты в Profile::prepareInputForUpdate и пишет glpi_profilerights. Своей точки сохранения
 *   и своего SQL у плагина нет.
 *
 * Вкладка есть у сохранённого профиля стандартного интерфейса и видна тому, у кого «Профили:
 * чтение»; галочки меняет и «Сохранить» видит тот, у кого «Профили: изменение». У профиля
 * упрощённого интерфейса вкладки нет: права маршрутов там не действуют.
 */
final class ProfileRights extends CommonGLPI
{
    /** Право ядра, которым открывается вкладка: «Профили». */
    public static $rightname = 'profile';

    public const TAB = 'Маршруты этапов';
    public const ICON = 'ti ti-route';
    /** Право «Закрыть принудительно» (Authorizer, Engine). */
    public const BYPASS = 'plugin_itilflow_bypass';

    /**
     * Строки матрицы — все права плагина. Новое право плагина вписывается сюда:
     * tests/unit/prava-profilya.php сверяет список с исходниками.
     *
     * @return list<array{label:string,field:string,itemtype?:string,rights?:array<int,string>}>
     */
    public static function matrix(): array
    {
        return [
            // Столбцы — права ядра для маршрута (Process::getRights): «Чтение», «Обновление», «Создать», «Очистить».
            ['itemtype' => Process::class, 'label' => 'Маршруты этапов', 'field' => Process::$rightname],
            ['rights' => [READ => 'Закрыть принудительно'], 'label' => 'Закрытие этапа без ответа', 'field' => self::BYPASS],
        ];
    }

    /** Сохранённый профиль стандартного интерфейса и «Профили: чтение» у того, кто смотрит. */
    public static function visible(CommonGLPI $item): bool
    {
        return $item instanceof Profile
            && !$item->isNewItem()
            && (string) ($item->fields['interface'] ?? '') === 'central'
            && (bool) Session::haveRight(self::$rightname, READ);
    }

    /** Галочки меняет тот, кому ядро даст сохранить профиль: «Профили: изменение». */
    public static function editable(): bool
    {
        return (bool) Session::haveRight(self::$rightname, UPDATE);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return self::visible($item) ? self::createTabEntry(self::TAB, 0, null, self::ICON) : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!self::visible($item)) {
            return false;
        }
        /** @var Profile $item */
        $edit = self::editable();
        echo '<div class="itilflow-profile-rights">';
        if ($edit) {
            echo '<form method="post" action="' . htmlescape(Profile::getFormURL()) . '" data-track-changes="true">';
        }
        $item->displayRightsChoiceMatrix(self::matrix(), ['canedit' => $edit, 'title' => self::TAB]);
        echo self::explanation();
        if ($edit) {
            echo '<div class="card-body mx-n2 border-top d-flex flex-row-reverse align-items-start flex-wrap">'
                . Html::hidden('id', ['value' => (int) $item->getID()])
                . Html::submit('Сохранить', ['name' => 'update', 'class' => 'btn btn-primary', 'icon' => 'ti ti-device-floppy'])
                . '</div>';
            Html::closeForm();
        }
        echo '</div>';
        return true;
    }

    /** Что даёт каждое право — под галочками, простыми словами. */
    public static function explanation(): string
    {
        $rows = [
            ['Маршруты этапов', 'Чтение — видеть маршруты, их настройки и схему, отчёт; экспорт маршрутов.'],
            ['', 'Обновление — администратор маршрутов: настраивать маршруты, этапы и запуск по ответу формы, управлять маршрутом на заявке.'],
            ['', 'Создать — новые маршруты; вместе с «Обновлением» — импорт маршрутов.'],
            ['', 'Очистить — удалять маршруты и этапы.'],
            ['Закрытие этапа без ответа', '«Закрыть принудительно» текущий этап с причиной.'],
        ];
        $html = '<div class="card-body"><table class="table table-sm align-middle mb-2"><tbody>';
        foreach ($rows as [$right, $text]) {
            $html .= '<tr><td class="fw-semibold">' . htmlescape($right) . '</td><td>' . htmlescape($text) . '</td></tr>';
        }
        return $html . '</tbody></table>'
            . '<p class="text-muted mb-0">Пункт меню появится после повторного входа.</p></div>';
    }
}
