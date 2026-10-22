<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Itilflow\Process;
use GlpiPlugin\Itilflow\Transfer;
use GlpiPlugin\Itilflow\TransferError;
use GlpiPlugin\Itilflow\TransferMapper;

// Импорт маршрутов — шаги импорта форм GLPI 11 (/Form/Import…): файл → предпросмотр →
// «Устранять проблемы» → импорт. Право — как у настроек плагина: создание и изменение
// маршрутов. Ключ CSRF каждого POST проверяет GLPI. Файл между шагами идёт скрытым полем
// и на каждом шаге проверяется заново.
Session::checkRight('plugin_itilflow_process', CREATE);
Session::checkRight('plugin_itilflow_process', UPDATE);

global $CFG_GLPI;
$self = $CFG_GLPI['root_doc'] . '/plugins/itilflow/front/import.php';
$step = is_string($_GET['step'] ?? null) ? $_GET['step'] : '';
$page = [
    'title'  => 'Импорт маршрутов',
    'menu'   => ['admin', Process::class],
    'action' => $self,
    'list'   => Process::getSearchURL(),
];
$template = 'import_step1.html.twig';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($step, ['preview', 'resolve', 'execute'], true)) {
    // Всё, что может отказать, — до вывода страницы: причина — сообщением на первом шаге.
    try {
        $json = Transfer::requestJson($_POST, $_FILES);
        $file = Transfer::parse($json);

        $mapper = new TransferMapper(Session::getActiveEntities());
        // Сначала прежние замены (поле JSON), затем выбранные на шаге «Устранять проблемы».
        foreach ([$_POST['replacements_json'] ?? null, $_POST['replacements'] ?? null] as $source) {
            foreach (Transfer::requestReplacements($source) as [$itemtype, $original, $replacement]) {
                $mapper->addReplacement($itemtype, $original, $replacement);
            }
        }
        $skipped = Transfer::requestIds($_POST['skipped_routes'] ?? []);
        $copies = array_values(array_diff(
            Transfer::requestIds($_POST['copy_routes'] ?? []),
            isset($_POST['uncopy']) ? Transfer::requestIds($_POST['uncopy']) : []
        ));
        $page += [
            'json'         => $json,
            'skipped'      => $skipped,
            'copies'       => $copies,
            'replacements' => $mapper->getReplacements(),
        ];

        $route = $step === 'resolve' ? Transfer::route($file, Transfer::requestIds($_POST['route_id'] ?? null)[0] ?? 0) : null;
        if ($route !== null) {
            $route['start_questions'] = Transfer::startQuestions($route, $mapper);
            $template = 'import_step3_issues.html.twig';
            $page += [
                'route'         => $route,
                'issues'        => Transfer::issues($route, $mapper),
                'active_entity' => (int) Session::getActiveEntity(),
            ];
        } elseif ($step !== 'execute') {
            $rows = Transfer::preview($file, $mapper, $skipped, $copies);
            if ($rows === []) {
                // Все маршруты убраны из списка — как в GLPI, обратно к выбору файла.
                Html::redirect($self);
            }
            $template = 'import_step2_preview.html.twig';
            $page += [
                'rows'  => $rows,
                'ready' => count(array_filter($rows, static fn(array $r): bool => $r['status'] === Transfer::READY)),
            ];
        } else {
            $result = Transfer::import($file, $mapper, $skipped, $copies);
            foreach ($result['rows'] as &$row) {
                $row['url'] = $row['id'] > 0 ? Process::getFormURLWithID($row['id']) : '';
            }
            unset($row);
            $template = 'import_step4_result.html.twig';
            $page += ['result' => $result];
        }
    } catch (TransferError | InvalidArgumentException $e) {
        Session::addMessageAfterRedirect(htmlescape($e instanceof TransferError ? $e->getMessage()
            : 'Импорт недоступен: нет активной организации.'), false, ERROR);
        Html::redirect($self);
    }
}

TemplateRenderer::getInstance()->display('@itilflow/' . $template, $page);
