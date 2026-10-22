<?php
require __DIR__ . '/_nachalo.php';
use GlpiPlugin\Itilflow\ApprovalRoster;

$yes = ['available' => true, 'active' => true, 'reason_code' => 'available', 'reason' => '', 'substitutes' => [], 'substitute_warning' => true];
$inactive = ['available' => false, 'active' => false, 'reason_code' => 'inactive', 'reason' => 'Неактивен', 'substitutes' => []];
$facts = ['users' => [1 => $yes, 2 => $yes, 3 => $inactive], 'groups' => [10 => ['available' => true, 'members' => [1, 2, 3]], 11 => ['available' => true, 'members' => [2]]]];
$selection = ['users' => [], 'groups' => [10, 11], 'mode' => 'each', 'legacy' => false];
$r = ApprovalRoster::plan($selection, $facts);
ok($r['ok'] && $r['count'] === 2 && array_column($r['targets'], 'id') === [1, 2], 'каждый: пересекающиеся группы дают уникальный состав сотрудников');
ok(count($r['excluded']) === 1 && $r['excluded'][0]['id'] === 3, 'неактивный участник явно исключён');
$selection['mode'] = 'any'; $r = ApprovalRoster::plan($selection, $facts);
ok($r['ok'] && $r['targets'] === [['itemtype' => 'Group', 'id' => 10], ['itemtype' => 'Group', 'id' => 11]], 'любой: одна native виза на группу');
$selection['users'] = [1];
ok(!ApprovalRoster::plan($selection, $facts)['ok'], 'сотрудники и группы одновременно не принимаются');
$selection['legacy'] = true;
ok(ApprovalRoster::plan($selection, $facts)['count'] === 2, 'старые поля сохраняют приоритет групп');
$selection = ['users' => [], 'groups' => [10], 'mode' => 'each'];
$f = $facts; $f['users'][2]['available'] = false; $f['users'][2]['reason_code'] = 'no_validation_right'; $f['users'][2]['reason'] = 'Нет права';
ok(!ApprovalRoster::plan($selection, $f)['ok'], 'один активный без права блокирует весь состав до отправки');
$f = $facts; $f['groups'][10]['members'] = [];
ok(!ApprovalRoster::plan($selection, $f)['ok'], 'пустая группа не создаёт нулевое согласование');
$f['groups'][10]['members'] = [3]; $f['users'][3]['substitutes'] = [9]; $selection['mode'] = 'any';
ok(!ApprovalRoster::plan($selection, $f)['ok'], 'неактивного участника группы не оживляет заместитель');
$selection['mode'] = 'each';
ok(!ApprovalRoster::plan($selection, $f)['ok'], 'каждый: неактивный оригинал не становится отдельным запросом заместителю');
$selection = ['users' => range(1, 50), 'groups' => [], 'mode' => 'any']; $f = ['users' => [], 'groups' => []];
foreach (range(1, 51) as $id) { $f['users'][$id] = $yes; }
ok(ApprovalRoster::plan($selection, $f)['ok'], '50 запросов разрешены');
$selection['users'][] = 51;
ok(!ApprovalRoster::plan($selection, $f)['ok'], '51 запрос блокируется до записи');
foreach ([['users' => ['1'], 'groups' => [], 'mode' => 'any'], ['users' => [], 'groups' => [], 'mode' => 'bad'], []] as $bad) {
    ok(!ApprovalRoster::plan($bad, $facts)['ok'], 'неверный формат состава закрывает отправку');
}
$p = ApprovalRoster::progress(['accepted' => 2, 'refused' => 1, 'waiting' => 0, 'total' => 3], 67);
ok($p['needed'] === 3 && $p['stalled'] && $p['native_status'] === 2, '67 процентов и 2 да из 3 остаётся native Ожидает, нужен администратор');
$p = ApprovalRoster::progress(['accepted' => 2, 'refused' => 0, 'waiting' => 0, 'dropped' => 1, 'total' => 3], 50);
ok($p['total'] === 3 && $p['needed'] === 2 && $p['accepted'] === 2 && $p['dropped'] === 1, 'снятие не меняет исходный знаменатель и не создаёт голос да');
ok(ApprovalRoster::progress(['accepted' => 1, 'total' => 3], 50)['errors'] !== [], 'потерянные записи не уменьшают знаменатель');
ok(ApprovalRoster::threshold(3, 67)['can_stall'], 'предупреждение зависания для состава 3 и порога 67');
ok(!ApprovalRoster::threshold(3, 50)['can_stall'] && !ApprovalRoster::threshold(3, 100)['can_stall'], 'пороги 50 и 100 не предупреждают ложно');
$f = $facts;
$f['users'][1]['available'] = false; $f['users'][1]['reason_code'] = 'no_validation_right'; $f['users'][1]['substitutes'] = [9];
$s = ['users' => [1], 'groups' => [], 'mode' => 'any'];
ok(ApprovalRoster::plan($s, $f)['ok'], 'активному адресату без права может ответить подтверждённый заместитель');
$f['users'][1]['reason_code'] = 'no_entity';
ok(!ApprovalRoster::plan($s, $f)['ok'], 'заместитель не переносит адресата в другую организацию');
$f['users'][1]['reason_code'] = 'outside_period';
ok(!ApprovalRoster::plan($s, $f)['ok'], 'заместитель не оживляет адресата вне периода действия');
$p = ApprovalRoster::progress(['accepted' => 0, 'refused' => 0, 'waiting' => 2], 0, true);
ok($p['native_status'] === 2 && $p['needed'] === 1, 'форма: нулевой порог ждёт первый ответ');
ok(ApprovalRoster::progress(['accepted' => 1, 'waiting' => 1], 0, true)['native_status'] === 3, 'форма: нулевой порог с одним согласием');
ok(ApprovalRoster::progress(['refused' => 1, 'waiting' => 1], 0, true)['native_status'] === 4, 'форма: нулевой порог с отказом без согласий');
ok(ApprovalRoster::progress(['waiting' => 2], 0)['native_status'] === null, 'собственный этап не принимает нулевой порог');
$named = $facts;
$named['users'][1]['name'] = 'Первый согласующий';
$named['users'][2]['name'] = 'Второй согласующий';
$r = ApprovalRoster::plan(['users' => [1, 2, 1], 'groups' => [], 'mode' => 'any'], $named);
ok(count($r['warnings']) === 1 && substr_count($r['warnings'][0], 'Первый согласующий') === 1
    && str_contains($r['warnings'][0], 'Второй согласующий') && !str_contains($r['warnings'][0], '№'),
    'предупреждение этапа объединяет имена без дублей и номеров');
$p = ApprovalRoster::progress(['accepted' => 29, 'waiting' => 21], 58);
ok($p['needed'] === 30 && $p['native_status'] === 2,
    '29 из 50 при 58 процентах ещё ожидает по дробному правилу ядра; нужно 30');
itog();
