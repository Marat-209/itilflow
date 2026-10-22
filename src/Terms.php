<?php
namespace GlpiPlugin\Itilflow;

/**
 * Словарь экрана: одно понятие — одно слово.
 *
 * Где понятие есть в GLPI — слово русского GLPI 11.0.8
 * (`locales/ru_RU.po`); где нет — простое общепринятое русское слово, без канцелярита.
 * Выбраны: «инициатор заявки», «пользователь», «этап согласования»,
 * «администратор маршрутов», меню — «Маршруты этапов» (не переименовывать).
 *
 * Константы — для PHP-кода частей; в шаблонах слова пишутся буквально.
 * FORBIDDEN — что не должно встречаться в видимых текстах (проверка tests/unit/terminy-113.php).
 */
final class Terms
{
    // Люди и роли
    public const INITIATOR = 'инициатор заявки';
    public const INITIATOR_LABEL = 'Инициатор заявки';
    public const USER = 'пользователь';
    public const APPROVER = 'согласующий';
    public const ASSIGNEE = 'исполнитель';
    public const DEPUTY = 'заместитель';
    public const ROUTE_ADMIN = 'администратор маршрутов';
    public const GROUP = 'Группа';
    public const DEPARTMENT = 'отдел';
    public const DEPARTMENT_GROUP = 'Группа отдела';

    // Согласование (слова GLPI)
    public const APPROVAL = 'согласование';
    public const APPROVAL_REQUEST = 'запрос на согласование';
    public const APPROVE = 'Одобрить';
    public const REFUSE = 'Отказать';
    public const APPROVED = 'Согласовано';
    public const REJECTED = 'Отклонено';
    public const WAITING_APPROVAL = 'В ожидании согласования';
    public const VALIDATION_STEP = 'этап согласования';
    public const NEEDED_APPROVALS = 'Нужно одобрений, %';
    public const APPROVER_LIST = 'список согласующих';

    // Кто согласует
    public const APPROVER_FIXED = 'Задан в этапе';
    public const APPROVER_RUNTIME = 'Указывает исполнитель заявки';
    public const APPROVER_FORM = 'Согласование из формы';
    public const GROUP_MODE = 'Как согласует группа';
    public const GROUP_ANY = 'Любой из группы — один запрос всей группе';
    public const GROUP_EACH = 'Каждый в группе — запрос каждому';

    // Маршрут и этапы
    public const MENU = 'Маршруты этапов';
    public const TAB = 'Маршрут этапов';
    public const RULE_ACTION = 'Запустить маршрут этапов';
    public const ROUTE = 'маршрут';
    public const STAGE = 'этап';
    public const HOW = 'Как выполняется';
    public const TASK = 'Задача';
    public const CHILD = 'Дочерняя заявка';
    public const PARENT = 'основная заявка';
    public const ATTEMPT = 'попытка';
    public const RESTART_FROM = 'Начать заново с этапа…';
    public const RESTART_FIELD = 'С какого этапа можно начать заново';

    // Условия
    public const WHEN = 'Когда выполнять';
    public const RUN_IF = 'Выполнять, если';
    public const START_IF = 'Запускать, если';
    public const ALWAYS = 'Всегда';
    public const ONLY_IF = 'Только если выполнены условия';
    public const START_OFF = 'Не запускать по форме';
    public const WHAT_CHECK = 'Что проверяем';
    public const COMPARE = 'Сравнение';
    public const VALUE = 'Значение';
    public const ANY_OF = 'любой из';
    public const NONE_OF = 'ни один из';
    public const EQUALS = 'Равно';
    public const NOT_EQUALS = 'Не равно';
    public const GREATER = 'Больше';
    public const NOT_LESS = 'Не меньше';
    public const LESS = 'Меньше';
    public const NOT_GREATER = 'Не больше';
    public const IF_NO_ANSWER = 'Если ответа нет';
    public const STAGE_QUESTIONS = 'Вопросы этапа';
    public const STAGE_ANSWERS = 'ответы этапа';
    public const SAVE_ANSWERS = 'Сохранить ответы';
    public const NOT_NEEDED = 'Не нужен по условию';
    public const NOT_NEEDED_BUTTON = 'Этап не нужен';
    public const SKIPPED = 'Пропущен';
    public const RUN_STAGE = 'Выполнять этап?';
    public const CHOICE = 'Выбор';

    // Состояния
    public const AHEAD = 'Впереди';
    public const NOW = 'Сейчас';
    public const IN_PROGRESS = 'В работе';
    public const PASSED = 'Пройден';
    public const STOPPED = 'Остановлен';
    public const ABORTED = 'Прерван';
    public const UNFINISHED = 'Не закончен';
    public const PREVIOUS_ATTEMPT = 'Прежняя попытка';
    public const RESULT = 'Результат';
    public const DEPARTMENT_RESULT = 'Результат отдела';
    public const DONE = 'Выполнено';
    public const REFUSAL = 'Отказ';
    public const ON_CLARIFICATION = 'На уточнении';

    // Действия и поля
    public const RETURN_FOR_CLARIFICATION = 'Вернуть на уточнение';
    public const WAITING_INITIATOR = 'Ждём ответа инициатора';
    public const RETURN_TARGET = 'Куда вернуть после уточнения';
    public const RETURN_STAGE = 'этап для возврата';
    public const ON_REFUSE_FINISH = 'Отказать инициатору и закончить маршрут';
    public const ON_REFUSE_STOP = 'Остановить маршрут до решения администратора';
    public const WITHDRAW = 'Отозвать';
    public const HAND_OVER = 'Передать';
    public const FORCE_CLOSE = 'Закрыть принудительно';
    public const FORCE_CLOSE_RIGHT = 'Закрытие этапа без ответа';
    public const REASON = 'Причина';
    public const ANSWER_INITIATOR = 'Ответить инициатору';
    public const PRIVATE_COMMENT = 'Приватный комментарий (инициатор не видит)';
    public const TIMELINE = 'лента заявки';
    public const ROUTE_LOG = 'Журнал маршрута';
    public const ROUTE_LOG_ENTRY = 'Запись журнала маршрута';
    public const OUT_OF_ORDER = 'Если действие не по порядку этапов';
    public const LOG_ONLY = 'Только записать';
    public const WARN = 'Предупредить';
    public const DENY = 'Запретить';
    public const VERSION = 'версия';

    /**
     * Видимые тексты: регулярное выражение => чем заменить. Проверяются шаблоны (без комментариев
     * и скриптов) и строковые литералы src/ и front/ (без комментариев и текстов исключений).
     * Слова GLPI не ловятся: «Источник запросов» (Request source), «проходит».
     * Последние пять строк — запреты 1.9 (tests/unit/terminy.php проверяет сам словарь).
     */
    public const FORBIDDEN = [
        '/(?<![а-яё])виз(а|ы|у|е|ой|ам|ами|ах)?(?![а-яё])/iu' => '«согласование»; «Виза из формы» → «Согласование из формы»',
        '/Запрос отправлен формой/u' => '«Согласование из формы»',
        '/(?<![а-яё])ступен/iu' => '«этап согласования»',
        '/шаг(а|е|ом|и|у)? согласования/iu' => '«этап согласования» — полностью',
        '/(?<![а-яё])порог/iu' => '«Нужно одобрений» / «нужно N из M»',
        '/(?<![а-яё])заявител/iu' => '«инициатор заявки» (в тексте — «инициатор»)',
        '/инициатор(а|у|ом|е)? запроса/iu' => '«инициатор заявки»',
        '/исходн(ый|ого|ому|ым) инициатор/iu' => '«инициатор»',
        '/администратор(а|у|ом|е|ы|ов)? процессов/iu' => '«администратор маршрутов»',
        '/администратор(а|у|ом|е)? маршрута(?![а-яё])/iu' => '«администратор маршрутов»',
        '/ответственн(ый|ого|ому|ым) за процесс/iu' => '«администратор маршрутов»',
        '/[Мм]аршрут(ы|ов|а)? обработки/u' => 'меню остаётся «Маршруты этапов»',
        '/[Нн]е потребовал(ся|ось|ась|ись)/u' => '«Не нужен по условию»',
        '/не требуется/u' => '«Не нужен по условию»',
        '/[Рр]ешени(е|я|ю|ем) отдела/u' => '«Вопросы этапа» / «ответы этапа»',
        '/вопрос(а|ы|ов)? решения/iu' => '«вопрос этапа»',
        '/ревизи/iu' => 'убрать с экрана',
        '/(?<![а-яё])редакци/iu' => 'убрать с экрана; у маршрута — «версия»',
        '/Если не проверить/u' => '«Если ответа нет»',
        '/Внутренний комментарий/u' => '«Приватный комментарий (инициатор не видит)»',
        '/(?<![а-яё])прохожд/iu' => '«попытка» или «раз»',
        '/(?<![а-яё])проход(а|ов|е|ы|ом|ами|ах)?(?![а-яё])/iu' => '«попытка» или «раз»',
        '/[Пп]убличн(ый|ого|ому|ым) итог/u' => '«Ответить инициатору» / «Результат отдела:»',
        '/точк(а|и|у|е|ой) (возврата|перехода)/iu' => '«этап для возврата» / «Куда вернуть после уточнения»',
        '/(?<![а-яё])источник(?![а-яё]* запрос)/iu' => '«Что проверяем»; в GLPI «источник» — источник запроса',
        '/(Сотрудник|Пользователь|Группа|Категория) №/u' => 'название вместо номера; удалённый — «удалён (№ N)»',
        '/Ожидает очереди/u' => '«Впереди»',
        '/[Пп]риостановлен/u' => '«Остановлен»',
        '/[Пп]рекращ(ён|ено|ена|ены) без/u' => '«Прерван» / «Не закончен»',
        '/Заменён новой попыткой/u' => '«Прежняя попытка»',
        '/[Оо]сновани(е|я) (выбора|решения|принятия)/u' => '«Причина»',
        '/(?<![а-яё])[Нн]е один из/u' => '«ни один из»',
        '/Отклонён(?![а-яё])/u' => '«Отклонено»',
        '/[Шш]аг маршрута/u' => '«Этап»',
        '/[Оо]бход маршрута/u' => '«Закрытие этапа без ответа»',
        '/[Нн]арушени(е|я|й) маршрута/u' => '«Журнал маршрута»',
        '/Режим контроля/u' => '«Если действие не по порядку этапов»',
        '/квитанц/iu' => 'служебное — убрать',
        '/(?<![а-яё])сним(ок|ка|ке|ком|ки)(?![а-яё])/iu' => 'служебное — убрать',
        '/(?<![а-яё])штатн/iu' => 'служебное — убрать',
        '/не подтвержд|не подтверждён/iu' => 'служебное — сказать, что сделать',
        '/обновите карточку/iu' => 'служебное — сказать, что сделать',
        '/(?<![а-яё])сущност/iu' => '«Организация» — так Entity в GLPI',
        '/плашк/iu' => 'жаргон',
        '/очеред[ьи] этапов/iu' => '«порядок этапов»',
        '/Настройка →/u' => '«Настройки →» — так меню GLPI',
        '/тикет|апрув|аппрув|деплой/iu' => 'жаргон',
    ];
}
