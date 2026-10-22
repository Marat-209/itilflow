<?php
/** Дополнительные чистые границы ручного принятия; без сети и БД. */
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\{Step,Instance,StructuredAccess,Outcome};
$borrowed = new class extends Step {
    public function hasExternalBindings(): bool { return true; }
    public function getInstance(): ?Instance { return null; }
};
$old = new class extends Step {
    public function hasExternalBindings(): bool { return false; }
    public function getInstance(): ?Instance { return null; }
};
$rows = [['status' => 3], ['status' => 3], ['status' => 4]];
ok(StructuredAccess::decide($borrowed, $rows, 67) === null, 'borrowed у старого запуска сохраняет native WAITING при 2/3 и67');
ok(StructuredAccess::decide($old, $rows, 67) === Outcome::REFUSED, 'обычный старый этап сохраняет прежний расчёт');
ok(StructuredAccess::decide($borrowed, [['status' => 2]], 0) === null, 'borrowed native0 без ответа ожидает');
ok(StructuredAccess::decide($borrowed, [['status' => 3], ['status' => 2]], 0) === Outcome::COMPLETED, 'borrowed native0 переносит согласие');
ok(StructuredAccess::decide($borrowed, [['status' => 4], ['status' => 2]], 0) === Outcome::REFUSED, 'borrowed native0 переносит отказ');
$t = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['autoescape' => 'html', 'strict_variables' => true]);
foreach (['call', 'csrf_token'] as $fn) { $t->addFunction(new \Twig\TwigFunction($fn, static fn() => '')); }
$context = ['command' => null, 'instance' => ['fields' => ['id' => 1]], 'r' => ['step_id' => 2], 'step_url' => '/safe', 'a' => ['action' => 'set_approver']];
$html = $t->render('flow_action.html.twig', $context);
ok(!str_contains($html, 'name="fallback_percent"'), 'обычный runtime не переопределяет порог');
$context['a'] += ['form_fallback' => true, 'fallback_percent' => 67];
$html = $t->render('flow_action.html.twig', $context);
ok(str_contains($html, 'name="fallback_percent"') && str_contains($html, 'value="67"') && str_contains($html, 'min="1"'), 'новые запросы вместо формы требуют явного ненулевого порога');
$ruleTicket = new \Ticket();
$ruleTicket->input = ['name' => 'Проверка', 'content' => 'Тестовый ответ', 'entities_id' => 0,
    '_plugin_itilflow_processes_id' => 17];
$ruleSignature = (new ReflectionMethod(\GlpiPlugin\Itilflow\RuleStart::class, 'signature'))
    ->invoke(null, 'Ticket', $ruleTicket->input);
$pendingRules = new ReflectionProperty(\GlpiPlugin\Itilflow\RuleStart::class, 'pending');
$pendingRules->setValue(null, [spl_object_id($ruleTicket) => ['object' => $ruleTicket,
    'signature' => $ruleSignature, 'process' => 17, 'rule' => 3, 'rule_name' => 'Тестовое правило']]);
try {
    ok(\GlpiPlugin\Itilflow\RuleStart::ruleName($ruleTicket) === 'Тестовое правило', 'журнал берёт имя из подтверждённого правила');
    ok(\GlpiPlugin\Itilflow\RuleStart::ruleName(clone $ruleTicket) === null, 'другая заявка не заимствует имя правила');
    $ruleTicket->input['name'] = 'Изменено';
    ok(\GlpiPlugin\Itilflow\RuleStart::ruleName($ruleTicket) === null, 'изменённый вход не выдаётся за совпадение правила');
} finally { $pendingRules->setValue(null, []); }
itog();
