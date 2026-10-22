<?php
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\ManualAdoptionConfirmation as Confirmation;
$native = ['id' => 8, 'itemtype' => 'Ticket', 'items_id' => 7, 'validationsteps_id' => 6, 'minimal_required_validation_percent' => 0];
$row = ['id' => 10, 'tickets_id' => 7, 'entities_id' => 2, 'itils_validationsteps_id' => 8, 'itemtype_target' => 'User',
    'items_id_target' => 3, 'status' => 3, 'users_id_validate' => 3, 'validation_date' => '2026-10-21 12:30:00', 'comment_validation' => '<synthetic>'];
$other = array_replace($row, ['id' => 11, 'status' => 2, 'validation_date' => null]);
$rows = [$row, $other]; $hash = Confirmation::digest($rows, $native);
ok(!Confirmation::accepts($rows, $native, null), 'ответ нельзя принять без явного подтверждения');
ok(Confirmation::accepts($rows, $native, $hash), 'точный подтверждённый состав принят');
ok(Confirmation::accepts([$other, $row], $native, $hash), 'порядок строк SQL не меняет подтверждение');
ok(Confirmation::accepts([$other], $native, null), 'ожидающий запрос не требует подтверждения ответа');
foreach (['status' => 4, 'users_id_validate' => 4, 'validation_date' => '2026-10-21 12:31:00', 'comment_validation' => 'changed',
    'items_id_target' => 4, 'itemtype_target' => 'Group', 'tickets_id' => 9, 'entities_id' => 9, 'itils_validationsteps_id' => 9] as $key => $value) {
    ok(!Confirmation::accepts([array_replace($row, [$key => $value]), $other], $native, $hash), 'устаревшее подтверждение отклонено: ' . $key);
}
ok(!Confirmation::accepts([$row], $native, $hash), 'исчезновение ожидающего участника меняет подтверждение всего шага');
ok(!Confirmation::accepts($rows, array_replace($native, ['minimal_required_validation_percent' => 100]), $hash), 'изменённый порог требует нового подтверждения');
ok(Confirmation::fromInput(['answered_confirmation' => $hash]) === null, 'скрытый hash без флажка не считается согласием');
ok(Confirmation::fromInput(['answered_confirmation' => $hash, 'confirm_answered' => '1']) === $hash, 'строгий POST флажок принят');
ok(Confirmation::fromInput(['answered_confirmation' => [$hash], 'confirm_answered' => '1']) === null, 'массив вместо hash отклонён');
ok(Confirmation::fromInput(['answered_confirmation' => $hash, 'confirm_answered' => ['1']]) === null, 'массив вместо флажка отклонён');
ok(Confirmation::answerDate($row) === '21.10' && Confirmation::answerDate([]) === 'дата не указана', 'дата ответа показана без выдуманной даты');
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['autoescape' => 'html', 'strict_variables' => true]);
$twig->addFunction(new \Twig\TwigFunction('csrf_token', static fn(): string => 'synthetic'));
$twig->addFunction(new \Twig\TwigFunction('call', static fn(): string => ''));
$group = ['ids' => [10], 'label' => '<unsafe name>', 'requests' => ['<unsafe answer>'], 'answered' => true,
    'confirmation' => $hash, 'confirmation_label' => 'Ответ дан 21.10 — засчитать?'];
$context = ['a' => ['action' => 'adopt_existing', 'groups' => [$group], 'reason' => '<unsafe reason>'], 'step_url' => '/step',
    'cmd' => '', 'r' => ['step_id' => 4], 'csrf' => '', 'instance' => ['fields' => ['id' => 1]], 'command' => null, 'command_key' => 'key', 'expected_attempt_id' => 2];
// Шаблон использует внешний готовый блок команды; его контракт проверяют общие UI-тесты.
try {
    $html = $twig->render('flow_action.html.twig', $context);
    ok(str_contains($html, 'name="confirm_answered"') && str_contains($html, 'required'), 'рендер отвечает обязательным явным флажком');
    ok(!str_contains($html, '<unsafe') && str_contains($html, '&lt;unsafe'), 'имя, ответ и причина экранированы');
    $context['a']['groups'][0]['answered'] = false;
    $html = $twig->render('flow_action.html.twig', $context);
    ok(!str_contains($html, 'name="confirm_answered"') && !str_contains($html, 'name="answered_confirmation"'), 'ожидающий запрос не показывает подтверждение уже данного ответа');
} catch (Throwable $e) { ok(false, 'strict Twig manual adoption', get_class($e) . ': ' . $e->getMessage()); }
itog();
